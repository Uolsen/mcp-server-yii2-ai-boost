<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use codechap\yii2boost\Helpers\ProjectRootResolver;
use codechap\yii2boost\Mcp\Tools\Base\BaseTool;

/**
 * DevServerTool - Start, stop, and make requests to the Yii2 built-in dev server.
 *
 * Spawns `php -S` directly (bypassing `yii serve` + `passthru()`) so we get the
 * real PID and can reliably stop the server.
 *
 * Uses PID files in the system temp directory so servers can be discovered and
 * stopped even after the MCP process that started them has exited.
 *
 * Supports running multiple servers simultaneously for advanced template apps
 * (frontend, backend, api) on different ports.
 */
class DevServerTool extends BaseTool
{
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 8080;
    private const MIN_PORT = 1024;
    private const MAX_PORT = 65535;
    private const DEFAULT_TIMEOUT = 10;
    private const MAX_TIMEOUT = 30;
    private const MAX_RESPONSE_LENGTH = 102400; // 100 KB
    private const SERVER_STARTUP_WAIT = 1; // seconds to wait for server to start
    private const DEFAULT_APP_KEY = '_default';
    private const PID_FILE_PREFIX = 'yii2_boost_devserver_';

    /** @var array<string, int> Default port assignments per app */
    private const APP_PORT_DEFAULTS = [
        '_default'  => 8080,
        'frontend'  => 8080,
        'backend'   => 8081,
        'api'       => 8082,
    ];

    /**
     * Registry of running server instances, keyed by app key.
     * Holds process resources for servers started by THIS process.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $servers = [];

    public function getName(): string
    {
        return 'dev_server';
    }

    public function getDescription(): string
    {
        return 'Start/stop the Yii2 built-in dev server and make HTTP requests to it. '
             . 'Actions: start, stop, status, request. '
             . 'Supports multiple servers for advanced template apps (frontend, backend, api). '
             . 'The "request" action auto-starts the server if needed.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'Action to perform: start, stop, status, or request',
                    'enum' => ['start', 'stop', 'status', 'request'],
                ],
                'app' => [
                    'type' => 'string',
                    'description' => 'Application to serve (advanced template only): frontend, backend, api. '
                                   . 'Each app runs on its own port. Use "all" with stop to stop all servers.',
                ],
                'host' => [
                    'type' => 'string',
                    'description' => 'Host to bind to (default: 127.0.0.1)',
                ],
                'port' => [
                    'type' => 'integer',
                    'description' => 'Port to bind to (default: 8080, backend: 8081, api: 8082)',
                ],
                'route' => [
                    'type' => 'string',
                    'description' => 'URL path to request, e.g. /api/v1/search (required for "request" action)',
                ],
                'method' => [
                    'type' => 'string',
                    'description' => 'HTTP method for request action (default: GET)',
                    'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Request timeout in seconds (default: 10, max: 30)',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $action = $arguments['action'] ?? '';

        return match ($action) {
            'start'   => $this->handleStart($arguments),
            'stop'    => $this->handleStop($arguments),
            'status'  => $this->handleStatus($arguments),
            'request' => $this->handleRequest($arguments),
            default   => ['error' => "Unknown action: $action. Use start, stop, status, or request."],
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    //  PID file helpers — persist server info across MCP process restarts
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Get the PID file path for an app key.
     */
    private function getPidFilePath(string $appKey): string
    {
        // Include a hash of the project root to avoid collisions between projects
        $projectHash = substr(md5($this->projectRoot ?? $this->basePath), 0, 8);
        $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $appKey);
        return sys_get_temp_dir() . '/' . self::PID_FILE_PREFIX . $projectHash . '_' . $safeKey . '.json';
    }

    /**
     * Write server info to a PID file.
     *
     * @param string $appKey App key
     * @param int $pid Process ID of the `php -S` server
     * @param string $address Bound address (host:port)
     * @param string $stderrFile Path to stderr temp file
     */
    private function writePidFile(string $appKey, int $pid, string $address, string $stderrFile): void
    {
        $data = [
            'pid' => $pid,
            'address' => $address,
            'stderrFile' => $stderrFile,
            'appKey' => $appKey,
            'startedAt' => time(),
        ];
        @file_put_contents($this->getPidFilePath($appKey), json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Read server info from a PID file.
     *
     * @return array<string, mixed>|null Server info or null if missing/invalid
     */
    private function readPidFile(string $appKey): ?array
    {
        $path = $this->getPidFilePath($appKey);
        if (!file_exists($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        if (!is_array($data) || empty($data['pid'])) {
            @unlink($path);
            return null;
        }
        return $data;
    }

    /**
     * Remove the PID file for an app key.
     */
    private function removePidFile(string $appKey): void
    {
        $path = $this->getPidFilePath($appKey);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Discover all PID files for this project.
     *
     * @return array<string, array<string, mixed>> Map of appKey => pid file data
     */
    private function discoverPidFiles(): array
    {
        $projectHash = substr(md5($this->projectRoot ?? $this->basePath), 0, 8);
        $prefix = self::PID_FILE_PREFIX . $projectHash . '_';
        $tmpDir = sys_get_temp_dir();

        $results = [];
        $files = @glob($tmpDir . '/' . $prefix . '*.json');
        if (!$files) {
            return $results;
        }

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $data = json_decode($content, true);
            if (!is_array($data) || empty($data['pid']) || empty($data['appKey'])) {
                @unlink($file);
                continue;
            }
            $results[$data['appKey']] = $data;
        }

        return $results;
    }

    /**
     * Check if a process with the given PID is alive.
     */
    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            exec("tasklist /FI \"PID eq $pid\" 2>&1", $output);
            return count($output) > 1;
        }

        // POSIX: signal 0 checks if the process exists
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        exec("kill -0 $pid 2>/dev/null", $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Kill a process by PID (sends TERM, waits, then KILL).
     */
    private function killProcess(int $pid): void
    {
        if ($pid <= 0 || !$this->isProcessAlive($pid)) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /T /PID $pid 2>&1");
            return;
        }

        // Send SIGTERM first for graceful shutdown
        if (function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);
        } else {
            exec("kill -TERM $pid 2>/dev/null");
        }

        // Wait briefly for graceful shutdown
        usleep(200000); // 200ms

        // php -S doesn't handle SIGTERM well — always follow up with SIGKILL
        if ($this->isProcessAlive($pid)) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGKILL);
            } else {
                exec("kill -KILL $pid 2>/dev/null");
            }
            usleep(100000); // 100ms for kernel to reap
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Action handlers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Start the dev server as a background process.
     *
     * Spawns `php -S` directly (not through `yii serve`) so we own the real PID.
     */
    private function handleStart(array $arguments): array
    {
        $resolved = $this->resolveAppKey($arguments);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        /** @var string $appKey */
        $appKey = $resolved['appKey'];
        /** @var string|null $docroot */
        $docroot = $resolved['docroot'];

        // Check in-memory first, then PID file
        if ($this->isRunning($appKey)) {
            $info = $this->getServerInfo($appKey);
            return [
                'success' => true,
                'message' => 'Server is already running',
                'app' => $appKey,
                'address' => $info['address'] ?? 'unknown',
            ];
        }

        $host = $arguments['host'] ?? self::DEFAULT_HOST;
        $port = (int) ($arguments['port'] ?? (self::APP_PORT_DEFAULTS[$appKey] ?? self::DEFAULT_PORT));

        // Safety: only allow localhost binding
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return ['error' => 'For safety, the server can only bind to localhost (127.0.0.1, localhost, or ::1).'];
        }

        // Validate port range
        if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
            return ['error' => "Port must be between " . self::MIN_PORT . " and " . self::MAX_PORT . "."];
        }

        // Check if the port is already in use
        $sock = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($sock) {
            fclose($sock);
            return ['error' => "Port $port is already in use on $host."];
        }

        // Resolve docroot — for basic apps, default to @app/web
        if ($docroot === null) {
            $docroot = $this->basePath . '/web';
        }

        if (!is_dir($docroot)) {
            return ['error' => "Document root \"$docroot\" does not exist."];
        }

        // Create a temp file for stderr
        $stderrFile = tempnam(sys_get_temp_dir(), 'yii_serve_stderr_');

        // Spawn `php -S` directly — using `exec` so the shell replaces itself
        // and proc_get_status() returns the real PHP PID.
        $cmd = sprintf(
            'exec %s -S %s -t %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg("$host:$port"),
            escapeshellarg($docroot)
        );

        $descriptors = [
            0 => ['pipe', 'r'],            // stdin
            1 => ['pipe', 'w'],            // stdout
            2 => ['file', $stderrFile, 'a'], // stderr -> file
        ];

        $process = proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $this->projectRoot ?? $this->basePath,
            null
        );

        if (!is_resource($process)) {
            return ['error' => 'Failed to start dev server process.'];
        }

        // Make stdout non-blocking so we can check without hanging
        stream_set_blocking($pipes[1], false);

        // Wait briefly for the server to start
        sleep(self::SERVER_STARTUP_WAIT);

        // Verify the process is still running and get its PID
        $status = proc_get_status($process);
        if (!$status['running']) {
            $stderr = (string) file_get_contents($stderrFile);
            $stdout = stream_get_contents($pipes[1]);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            @unlink($stderrFile);
            return [
                'error' => 'Server process exited immediately.',
                'app' => $appKey,
                'stdout' => trim((string) $stdout),
                'stderr' => trim((string) $stderr),
            ];
        }

        $pid = $status['pid'];
        $address = "$host:$port";

        // Store in memory
        self::$servers[$appKey] = [
            'process' => $process,
            'pipes' => $pipes,
            'pid' => $pid,
            'boundAddress' => $address,
            'stderrFile' => $stderrFile,
        ];

        // Persist to PID file so other MCP sessions can find this server
        $this->writePidFile($appKey, $pid, $address, $stderrFile);

        return [
            'success' => true,
            'message' => 'Dev server started',
            'app' => $appKey,
            'address' => $address,
            'url' => "http://$address",
            'pid' => $pid,
        ];
    }

    /**
     * Stop running dev server(s).
     */
    private function handleStop(array $arguments): array
    {
        $app = $arguments['app'] ?? null;

        // Stop all servers
        if ($app === 'all') {
            $stopped = $this->stopAllServers();

            if (empty($stopped)) {
                return [
                    'success' => true,
                    'message' => 'No servers are running (nothing to stop)',
                ];
            }

            return [
                'success' => true,
                'message' => 'All dev servers stopped',
                'stopped' => $stopped,
            ];
        }

        $resolved = $this->resolveAppKey($arguments);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        /** @var string $appKey */
        $appKey = $resolved['appKey'];

        if (!$this->isRunning($appKey)) {
            return [
                'success' => true,
                'message' => 'Server is not running (nothing to stop)',
                'app' => $appKey,
            ];
        }

        $info = $this->getServerInfo($appKey);
        $address = $info['address'] ?? 'unknown';

        $this->cleanup($appKey);

        return [
            'success' => true,
            'message' => 'Dev server stopped',
            'app' => $appKey,
            'address' => $address,
        ];
    }

    /**
     * Report server status.
     */
    private function handleStatus(array $arguments): array
    {
        $app = $arguments['app'] ?? null;

        // No app specified: return status for all servers
        if ($app === null) {
            $result = ['servers' => []];

            // Merge in-memory servers and PID file servers
            $allServers = $this->discoverAllServers();

            foreach ($allServers as $key => $info) {
                $result['servers'][$key] = [
                    'running' => true,
                    'address' => $info['address'],
                    'url' => 'http://' . $info['address'],
                    'pid' => $info['pid'],
                ];
            }

            if (empty($result['servers'])) {
                $result['message'] = 'No servers are running';
            }

            if ($this->isAdvancedApp && $this->projectRoot) {
                $result['available_apps'] = array_keys($this->getServableApps());
            }

            return $result;
        }

        $resolved = $this->resolveAppKey($arguments);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        /** @var string $appKey */
        $appKey = $resolved['appKey'];
        $running = $this->isRunning($appKey);

        $info = $running ? $this->getServerInfo($appKey) : null;

        $result = [
            'app' => $appKey,
            'running' => $running,
            'address' => $info['address'] ?? null,
            'url' => $info ? 'http://' . $info['address'] : null,
            'pid' => $info['pid'] ?? null,
        ];

        // Include recent stderr output if available
        if ($running) {
            $stderrFile = $info['stderrFile'] ?? null;
            if ($stderrFile && file_exists($stderrFile)) {
                $stderr = file_get_contents($stderrFile);
                if ($stderr !== false && $stderr !== '') {
                    $lines = explode("\n", trim($stderr));
                    $result['recent_output'] = implode("\n", array_slice($lines, -50));
                }
            }
        }

        return $result;
    }

    /**
     * Make an HTTP request to the dev server.
     * Auto-starts the server if not running.
     */
    private function handleRequest(array $arguments): array
    {
        $route = $arguments['route'] ?? null;
        if (empty($route)) {
            return ['error' => 'The "route" parameter is required for the request action.'];
        }

        // Ensure route starts with /
        if ($route[0] !== '/') {
            $route = '/' . $route;
        }

        $method = strtoupper($arguments['method'] ?? 'GET');
        $timeout = min((int) ($arguments['timeout'] ?? self::DEFAULT_TIMEOUT), self::MAX_TIMEOUT);
        if ($timeout < 1) {
            $timeout = self::DEFAULT_TIMEOUT;
        }

        $resolved = $this->resolveAppKey($arguments);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        /** @var string $appKey */
        $appKey = $resolved['appKey'];

        // Auto-start server if not running
        if (!$this->isRunning($appKey)) {
            $startResult = $this->handleStart($arguments);
            if (isset($startResult['error'])) {
                return $startResult;
            }
        }

        $info = $this->getServerInfo($appKey);
        $stderrFile = $info['stderrFile'] ?? null;

        // Note the stderr file position before the request
        $stderrBefore = 0;
        if ($stderrFile && file_exists($stderrFile)) {
            $stderrBefore = filesize($stderrFile) ?: 0;
        }

        $url = "http://" . $info['address'] . $route;

        // Make the HTTP request using cURL
        $result = $this->doHttpRequest($url, $method, $timeout);
        $result['app'] = $appKey;

        // Capture any new stderr output (PHP errors/warnings)
        $serverErrors = '';
        if ($stderrFile && file_exists($stderrFile)) {
            clearstatcache(true, $stderrFile);
            $stderrAfter = filesize($stderrFile) ?: 0;
            $readLength = $stderrAfter - $stderrBefore;
            if ($readLength > 0) {
                $fh = fopen($stderrFile, 'r');
                if ($fh) {
                    fseek($fh, $stderrBefore);
                    $serverErrors = (string) fread($fh, $readLength);
                    fclose($fh);
                }
            }
        }

        if (!empty($serverErrors)) {
            $result['server_errors'] = trim($serverErrors);
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Server state management
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Resolve the app key, docroot path, and working directory from arguments.
     *
     * @param array<string, mixed> $arguments Tool arguments
     * @return array<string, mixed> Either ['appKey' => ..., 'docroot' => ..., 'cwd' => ...] or ['error' => ...]
     */
    private function resolveAppKey(array $arguments): array
    {
        $app = $arguments['app'] ?? null;

        // No app parameter: use default (backwards-compatible)
        if ($app === null) {
            // For advanced apps, default to the first servable app's web/ directory
            if ($this->isAdvancedApp && $this->projectRoot) {
                $servableApps = $this->getServableApps();
                $docroot = !empty($servableApps) ? reset($servableApps) : null;
                return [
                    'appKey' => self::DEFAULT_APP_KEY,
                    'docroot' => $docroot,
                    'cwd' => $this->projectRoot,
                ];
            }

            return [
                'appKey' => self::DEFAULT_APP_KEY,
                'docroot' => null,
                'cwd' => $this->basePath,
            ];
        }

        // App parameter on basic template
        if (!$this->isAdvancedApp) {
            return [
                'error' => 'The "app" parameter is only available for advanced template applications. '
                         . 'Your app uses the basic template — just omit the "app" parameter.',
            ];
        }

        if (!$this->projectRoot) {
            return ['error' => 'Project root could not be resolved for advanced app.'];
        }

        $servableApps = $this->getServableApps();

        if (!isset($servableApps[$app])) {
            $available = array_keys($servableApps);
            return [
                'error' => "App \"$app\" is not available for serving. "
                         . 'Available apps: ' . implode(', ', $available) . '.',
            ];
        }

        return [
            'appKey' => $app,
            'docroot' => $servableApps[$app],
            'cwd' => $this->projectRoot,
        ];
    }

    /**
     * Get app names that have a web/ directory and can be served.
     *
     * @return array<string, string> Map of app name => web directory path
     */
    private function getServableApps(): array
    {
        if (!$this->isAdvancedApp || !$this->projectRoot) {
            return [];
        }

        $appDirs = ProjectRootResolver::getAppDirectories($this->projectRoot);
        $servable = [];

        foreach ($appDirs as $name => $path) {
            if (in_array($name, ['common', 'console'], true)) {
                continue;
            }
            $webDir = $path . '/web';
            if (is_dir($webDir)) {
                $servable[$name] = $webDir;
            }
        }

        return $servable;
    }

    /**
     * Check whether a server is still running (in-memory or via PID file).
     */
    private function isRunning(string $appKey = self::DEFAULT_APP_KEY): bool
    {
        // 1. Check in-memory process first
        if (isset(self::$servers[$appKey])) {
            $process = self::$servers[$appKey]['process'];
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    return true;
                }
            }
            // Process died — clean up in-memory entry (but not PID file yet)
            $this->cleanupInMemory($appKey);
        }

        // 2. Check PID file (server may have been started by a previous MCP session)
        $pidData = $this->readPidFile($appKey);
        if ($pidData !== null && $this->isProcessAlive((int) $pidData['pid'])) {
            return true;
        }

        // PID file exists but process is dead — clean up stale PID file
        if ($pidData !== null) {
            $this->removePidFile($appKey);
            // Clean up stale stderr file too
            if (!empty($pidData['stderrFile']) && file_exists($pidData['stderrFile'])) {
                @unlink($pidData['stderrFile']);
            }
        }

        return false;
    }

    /**
     * Get server info from either in-memory or PID file.
     *
     * @return array<string, mixed>|null
     */
    private function getServerInfo(string $appKey): ?array
    {
        // In-memory first
        if (isset(self::$servers[$appKey])) {
            return [
                'pid' => self::$servers[$appKey]['pid'] ?? null,
                'address' => self::$servers[$appKey]['boundAddress'],
                'stderrFile' => self::$servers[$appKey]['stderrFile'],
            ];
        }

        // Fall back to PID file
        return $this->readPidFile($appKey);
    }

    /**
     * Discover all running servers (in-memory + PID files).
     *
     * @return array<string, array<string, mixed>>
     */
    private function discoverAllServers(): array
    {
        $result = [];

        // In-memory servers
        foreach (array_keys(self::$servers) as $key) {
            if ($this->isRunning($key)) {
                $info = $this->getServerInfo($key);
                if ($info) {
                    $result[$key] = $info;
                }
            }
        }

        // PID file servers (from previous MCP sessions)
        foreach ($this->discoverPidFiles() as $key => $data) {
            if (isset($result[$key])) {
                continue; // Already found in-memory
            }
            if ($this->isProcessAlive((int) $data['pid'])) {
                $result[$key] = $data;
            } else {
                // Stale PID file
                $this->removePidFile($key);
                if (!empty($data['stderrFile']) && file_exists($data['stderrFile'])) {
                    @unlink($data['stderrFile']);
                }
            }
        }

        return $result;
    }

    /**
     * Stop all known servers (in-memory + PID files).
     *
     * @return array<string, string> Map of appKey => address for servers that were stopped
     */
    private function stopAllServers(): array
    {
        $stopped = [];
        $allServers = $this->discoverAllServers();

        foreach ($allServers as $key => $info) {
            $stopped[$key] = $info['address'];
            $this->cleanup($key);
        }

        return $stopped;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Cleanup
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Clean up only the in-memory entry (pipes, process resource).
     */
    private function cleanupInMemory(string $appKey): void
    {
        if (!isset(self::$servers[$appKey])) {
            return;
        }

        $server = self::$servers[$appKey];

        // Close pipes
        if (isset($server['pipes']) && is_array($server['pipes'])) {
            foreach ($server['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }

        // Close process resource
        if (isset($server['process']) && is_resource($server['process'])) {
            proc_close($server['process']);
        }

        unset(self::$servers[$appKey]);
    }

    /**
     * Full cleanup: kill the server process, remove PID file, clean up resources.
     */
    private function cleanup(string $appKey = self::DEFAULT_APP_KEY): void
    {
        $pid = null;
        $stderrFile = null;

        // Get PID from in-memory or PID file
        if (isset(self::$servers[$appKey])) {
            $pid = self::$servers[$appKey]['pid'] ?? null;
            $stderrFile = self::$servers[$appKey]['stderrFile'] ?? null;

            // If we don't have pid in memory, try proc_get_status
            if ($pid === null && is_resource(self::$servers[$appKey]['process'])) {
                $status = proc_get_status(self::$servers[$appKey]['process']);
                $pid = $status['pid'];
            }
        }

        // Also check PID file (may have been started by another session)
        $pidData = $this->readPidFile($appKey);
        if ($pidData !== null) {
            $pid = $pid ?? (int) $pidData['pid'];
            $stderrFile = $stderrFile ?? ($pidData['stderrFile'] ?? null);
        }

        // Kill the process
        if ($pid !== null && $pid > 0) {
            $this->killProcess($pid);
        }

        // Clean up in-memory resources
        $this->cleanupInMemory($appKey);

        // Remove PID file
        $this->removePidFile($appKey);

        // Clean up stderr temp file
        if ($stderrFile !== null && file_exists($stderrFile)) {
            @unlink($stderrFile);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  HTTP client
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Perform an HTTP request using cURL.
     */
    private function doHttpRequest(string $url, string $method, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return $this->doHttpRequestFallback($url, $method, $timeout);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY => ($method === 'HEAD'),
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            return [
                'error' => "HTTP request failed: $error (code: $errno)",
                'url' => $url,
                'method' => $method,
            ];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        /** @var string $response */
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Parse response headers
        $headers = [];
        foreach (explode("\r\n", trim($headerStr)) as $line) {
            if (str_contains($line, ':')) {
                [$key, $val] = explode(':', $line, 2);
                $headers[trim($key)] = trim($val);
            }
        }

        // Truncate body if too large
        $truncated = false;
        if (strlen($body) > self::MAX_RESPONSE_LENGTH) {
            $body = substr($body, 0, self::MAX_RESPONSE_LENGTH);
            $truncated = true;
        }

        return [
            'success' => true,
            'url' => $url,
            'method' => $method,
            'http_status' => $httpCode,
            'headers' => $headers,
            'body' => $body,
            'body_length' => strlen($body),
            'truncated' => $truncated,
        ];
    }

    /**
     * Fallback HTTP request using file_get_contents.
     */
    private function doHttpRequestFallback(string $url, string $method, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => false,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return [
                'error' => 'HTTP request failed (file_get_contents)',
                'url' => $url,
                'method' => $method,
            ];
        }

        // Parse response headers from $http_response_header
        $httpCode = 0;
        $headers = [];
        /** @var string[]|null $http_response_header */
        if (!empty($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $line, $m)) {
                    $httpCode = (int) $m[1];
                } elseif (str_contains($line, ':')) {
                    [$key, $val] = explode(':', $line, 2);
                    $headers[trim($key)] = trim($val);
                }
            }
        }

        // Truncate body if too large
        $truncated = false;
        if (strlen($body) > self::MAX_RESPONSE_LENGTH) {
            $body = substr($body, 0, self::MAX_RESPONSE_LENGTH);
            $truncated = true;
        }

        return [
            'success' => true,
            'url' => $url,
            'method' => $method,
            'http_status' => $httpCode,
            'headers' => $headers,
            'body' => $body,
            'body_length' => strlen($body),
            'truncated' => $truncated,
        ];
    }

    /**
     * Destructor - ensure all servers are stopped when the tool is destroyed.
     */
    public function __destruct()
    {
        foreach (array_keys(self::$servers) as $appKey) {
            $this->cleanup($appKey);
        }
    }
}
