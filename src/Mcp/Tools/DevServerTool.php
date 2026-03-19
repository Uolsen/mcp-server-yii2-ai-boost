<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use codechap\yii2boost\Helpers\ProjectRootResolver;
use codechap\yii2boost\Mcp\Tools\Base\BaseTool;

/**
 * DevServerTool - Start, stop, and make requests to the Yii2 built-in dev server.
 *
 * Allows AI agents to spin up `php yii serve`, hit routes, and capture
 * the HTTP response together with any PHP errors/warnings from stderr.
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

    /** @var array<string, int> Default port assignments per app */
    private const APP_PORT_DEFAULTS = [
        '_default'  => 8080,
        'frontend'  => 8080,
        'backend'   => 8081,
        'api'       => 8082,
    ];

    /**
     * Registry of running server instances, keyed by app key.
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

    /**
     * Start the dev server as a background process.
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
        /** @var string $cwd */
        $cwd = $resolved['cwd'];

        if ($this->isRunning($appKey)) {
            $server = self::$servers[$appKey];
            return [
                'success' => true,
                'message' => 'Server is already running',
                'app' => $appKey,
                'address' => $server['boundAddress'],
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

        // Create a temp file for stderr
        $stderrFile = tempnam(sys_get_temp_dir(), 'yii_serve_stderr_');

        $cmd = sprintf(
            'php yii serve %s',
            escapeshellarg("$host:$port")
        );

        if ($docroot !== null) {
            $cmd .= sprintf(' --docroot=%s', escapeshellarg($docroot));
        }

        $descriptors = [
            0 => ['pipe', 'r'],            // stdin
            1 => ['pipe', 'w'],            // stdout
            2 => ['file', $stderrFile, 'a'], // stderr -> file
        ];

        $process = proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $cwd,
            null
        );

        if (!is_resource($process)) {
            return ['error' => 'Failed to start dev server process.'];
        }

        // Make stdout non-blocking so we can check without hanging
        stream_set_blocking($pipes[1], false);

        self::$servers[$appKey] = [
            'process' => $process,
            'pipes' => $pipes,
            'boundAddress' => "$host:$port",
            'stderrFile' => $stderrFile,
        ];

        // Wait briefly for the server to start
        sleep(self::SERVER_STARTUP_WAIT);

        // Verify the process is still running
        $status = proc_get_status($process);
        if (!$status['running']) {
            $stderr = (string) file_get_contents($stderrFile);
            $stdout = stream_get_contents($pipes[1]);
            $this->cleanup($appKey);
            return [
                'error' => 'Server process exited immediately.',
                'app' => $appKey,
                'stdout' => trim((string) $stdout),
                'stderr' => trim((string) $stderr),
            ];
        }

        return [
            'success' => true,
            'message' => 'Dev server started',
            'app' => $appKey,
            'address' => "$host:$port",
            'url' => "http://$host:$port",
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
            if (empty(self::$servers)) {
                return [
                    'success' => true,
                    'message' => 'No servers are running (nothing to stop)',
                ];
            }

            $stopped = [];
            foreach (array_keys(self::$servers) as $key) {
                if ($this->isRunning($key)) {
                    $stopped[$key] = self::$servers[$key]['boundAddress'];
                }
                $this->cleanup($key);
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

        $address = self::$servers[$appKey]['boundAddress'];
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

            foreach (array_keys(self::$servers) as $key) {
                $running = $this->isRunning($key);
                if ($running) {
                    $server = self::$servers[$key];
                    $result['servers'][$key] = [
                        'running' => true,
                        'address' => $server['boundAddress'],
                        'url' => 'http://' . $server['boundAddress'],
                    ];
                }
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

        $result = [
            'app' => $appKey,
            'running' => $running,
            'address' => $running ? self::$servers[$appKey]['boundAddress'] : null,
            'url' => $running ? 'http://' . self::$servers[$appKey]['boundAddress'] : null,
        ];

        // Include recent stderr output if available
        if ($running) {
            $stderrFile = self::$servers[$appKey]['stderrFile'];
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

        $server = self::$servers[$appKey];
        $stderrFile = $server['stderrFile'];

        // Note the stderr file position before the request
        $stderrBefore = 0;
        if ($stderrFile && file_exists($stderrFile)) {
            $stderrBefore = filesize($stderrFile) ?: 0;
        }

        $url = "http://" . $server['boundAddress'] . $route;

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
            // For advanced apps, use project root as CWD (yii script lives there)
            // and default to the first servable app's web/ directory
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
     * Check whether a server process is still running.
     */
    private function isRunning(string $appKey = self::DEFAULT_APP_KEY): bool
    {
        if (!isset(self::$servers[$appKey])) {
            return false;
        }

        $process = self::$servers[$appKey]['process'];
        if (!is_resource($process)) {
            $this->cleanup($appKey);
            return false;
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            $this->cleanup($appKey);
            return false;
        }

        return true;
    }

    /**
     * Kill a server process and clean up resources.
     */
    private function cleanup(string $appKey = self::DEFAULT_APP_KEY): void
    {
        if (!isset(self::$servers[$appKey])) {
            return;
        }

        $server = self::$servers[$appKey];
        $process = $server['process'];
        $pipes = $server['pipes'];
        $stderrFile = $server['stderrFile'];

        if (is_resource($process)) {
            $status = proc_get_status($process);
            $pid = $status['pid'];

            // Close pipes first to unblock proc_close
            if (is_array($pipes)) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }

            // Kill the process tree
            if ($pid > 0 && $status['running']) {
                if (PHP_OS_FAMILY === 'Windows') {
                    exec("taskkill /F /T /PID $pid 2>&1");
                } else {
                    exec("pkill -TERM -P $pid 2>/dev/null");
                    usleep(100000); // 100ms grace
                    exec("pkill -KILL -P $pid 2>/dev/null");
                    if (function_exists('posix_kill')) {
                        posix_kill($pid, SIGTERM);
                    } else {
                        exec("kill -TERM $pid 2>/dev/null");
                    }
                    usleep(100000);
                    if (function_exists('posix_kill')) {
                        posix_kill($pid, SIGKILL);
                    } else {
                        exec("kill -KILL $pid 2>/dev/null");
                    }
                }
            }

            proc_close($process);
        } else {
            // No valid process, just clean up pipes
            if (is_array($pipes)) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
        }

        // Clean up stderr temp file
        if ($stderrFile !== null && file_exists($stderrFile)) {
            @unlink($stderrFile);
        }

        unset(self::$servers[$appKey]);
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
