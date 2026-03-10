<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use Yii;
use yii\web\UrlRule;
use codechap\yii2boost\Mcp\Tools\Base\BaseTool;

/**
 * Route Inspector Tool
 *
 * Provides complete route mapping including:
 * - URL rules from urlManager
 * - Route → Controller/Action mappings
 * - Module routes with prefixes
 * - RESTful API routes
 * - Default routes
 */
final class RouteInspectorTool extends BaseTool
{
    public function getName(): string
    {
        return 'route_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect application routes and URL rules including module routes and REST endpoints';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'module' => [
                    'type' => 'string',
                    'description' => 'Specific module to inspect (optional)',
                ],
                'include_patterns' => [
                    'type' => 'boolean',
                    'description' => 'Include regex patterns in routes',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $module = $arguments['module'] ?? null;
        $includePatterns = $arguments['include_patterns'] ?? false;

        if ($module) {
            return $this->getModuleRoutes($module, $includePatterns);
        }

        $result = [
            'url_rules' => $this->getUrlRules($includePatterns),
            'modules' => $this->getModuleRoutes(null, $includePatterns),
        ];

        // If no URL rules found (likely running in console context), try parsing web config
        if (empty($result['url_rules'])) {
            $webRules = $this->getWebConfigUrlRules();
            if (!empty($webRules)) {
                $result['url_rules'] = $webRules;
                $result['source'] = 'parsed from web config (MCP server runs in console context)';
            }
        }

        return $result;
    }

    /**
     * Get all URL rules from urlManager
     *
     * @param bool $includePatterns Include regex patterns
     * @return array
     */
    private function getUrlRules(bool $includePatterns = false): array
    {
        $app = Yii::$app;
        if (!$app->has('urlManager')) {
            return [];
        }

        $urlManager = $app->get('urlManager');
        $rules = [];

        foreach ($urlManager->rules as $rule) {
            if ($rule instanceof UrlRule) {
                $ruleData = [
                    'pattern' => $rule->name,
                    'route' => $rule->route,
                ];

                if (!empty($rule->verb)) {
                    $ruleData['verb'] = $rule->verb;
                }

                if ($includePatterns && !empty($rule->pattern)) {
                    $ruleData['regex_pattern'] = $rule->pattern;
                }

                $rules[] = $ruleData;
            } elseif (is_array($rule)) {
                // Array-style rule
                $rules[] = [
                    'pattern' => $rule[0] ?? null,
                    'route' => $rule[1] ?? null,
                ];
            }
        }

        return $rules;
    }

    /**
     * Get routes for a specific module or all module routes
     *
     * @param string|null $moduleName Module name
     * @param bool $includePatterns Include regex patterns
     * @return array
     * @throws \Exception
     */
    private function getModuleRoutes(?string $moduleName = null, bool $includePatterns = false): array
    {
        $app = Yii::$app;
        $result = [];

        if ($moduleName) {
            if (!$app->hasModule($moduleName)) {
                throw new \Exception("Module '$moduleName' not found");
            }

            $module = $app->getModule($moduleName);
            return [
                'module' => $moduleName,
                'routes' => $this->scanModuleControllers($module, $moduleName),
            ];
        }

        // Get routes for all modules
        foreach ($app->getModules() as $id => $moduleConfig) {
            try {
                $module = $app->getModule($id);
                if ($module === null) {
                    continue;
                }
                $result[$id] = $this->scanModuleControllers($module, $id);
            } catch (\Throwable $e) {
                $result[$id] = ['error' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * Scan module directory for controllers and actions
     *
     * @param object $module Module instance
     * @param string $moduleId Module ID
     * @return array
     */
    private function scanModuleControllers(object $module, string $moduleId): array
    {
        $controllersPath = $module->basePath . '/controllers';
        $routes = [];

        if (!is_dir($controllersPath)) {
            return $routes;
        }

        $iterator = new \DirectoryIterator($controllersPath);

        foreach ($iterator as $file) {
            if ($file->isDot() || !$file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $controllerName = substr($file->getFilename(), 0, -4); // Remove .php

            // Convert ControllerName to controller-name route
            $routeName = $this->camelCaseToKebabCase(
                substr($controllerName, 0, -10) // Remove 'Controller' suffix
            );

            $routes[$routeName] = [
                'controller' => $routeName,
                'module' => $moduleId,
                'full_path' => $moduleId . '/' . $routeName,
            ];
        }

        return $routes;
    }

    /**
     * Convert CamelCase to kebab-case
     *
     * @param string $string String to convert
     * @return string
     */
    private function camelCaseToKebabCase(string $string): string
    {
        return strtolower(preg_replace(
            '/([a-z0-9]|(?<=[a-z])[A-Z]|(?<=[A-Z])[A-Z](?=[a-z]))([A-Z])/s',
            '$1-$2',
            $string
        ));
    }

    /**
     * Parse web config files to extract URL rules when running in console context.
     *
     * Searches common Yii2 config locations for urlManager rules.
     *
     * @return array Extracted URL rules
     */
    private function getWebConfigUrlRules(): array
    {
        $basePath = $this->basePath;
        $projectRoot = $this->projectRoot ?: $basePath;

        // Common web config file locations for Yii2 applications
        $candidates = [
            // Basic app config locations
            $basePath . '/config/web.php',
            $basePath . '/config/main.php',
            $basePath . '/config/main-local.php',
        ];

        if ($this->isAdvancedApp) {
            // Advanced app: scan from project root
            $candidates = array_merge($candidates, [
                $projectRoot . '/common/config/main.php',
                $projectRoot . '/common/config/main-local.php',
                $projectRoot . '/common/config/routes.php',
                $projectRoot . '/frontend/config/main.php',
                $projectRoot . '/frontend/config/main-local.php',
                $projectRoot . '/backend/config/main.php',
                $projectRoot . '/backend/config/main-local.php',
                $projectRoot . '/api/config/main.php',
                $projectRoot . '/api/config/main-local.php',
            ]);
        } else {
            // Basic app: try relative paths
            $candidates = array_merge($candidates, [
                $basePath . '/../common/config/main.php',
                $basePath . '/../common/config/main-local.php',
                $basePath . '/../frontend/config/main.php',
                $basePath . '/../backend/config/main.php',
            ]);
        }

        $allRules = [];

        foreach ($candidates as $configFile) {
            if (!file_exists($configFile)) {
                continue;
            }

            $rules = $this->extractUrlRulesFromConfig($configFile);
            if (!empty($rules)) {
                foreach ($rules as $rule) {
                    $rule['config_file'] = basename(dirname($configFile)) . '/' . basename($configFile);
                    $allRules[] = $rule;
                }
            }
        }

        return $allRules;
    }

    /**
     * Extract URL rules from a config file by parsing the returned array.
     *
     * @param string $configFile Absolute path to config file
     * @return array Extracted URL rules
     */
    private function extractUrlRulesFromConfig(string $configFile): array
    {
        try {
            // Safely include the config file — it returns an array
            $config = @include $configFile;

            if (!is_array($config)) {
                return [];
            }

            // Look for urlManager rules in components
            $urlManagerConfig = $config['components']['urlManager'] ?? null;
            if (!is_array($urlManagerConfig)) {
                return [];
            }

            $rawRules = $urlManagerConfig['rules'] ?? [];
            if (empty($rawRules)) {
                return [];
            }

            $rules = [];
            foreach ($rawRules as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    // Simple format: 'pattern' => 'route'
                    $rules[] = [
                        'pattern' => $key,
                        'route' => $value,
                    ];
                } elseif (is_array($value)) {
                    // Array format with class, pattern, route, verb, etc.
                    $rule = [];

                    if (is_string($key)) {
                        $rule['pattern'] = $key;
                    } elseif (isset($value['pattern'])) {
                        $rule['pattern'] = $value['pattern'];
                    }

                    if (isset($value['route'])) {
                        $rule['route'] = $value['route'];
                    }

                    if (isset($value['class'])) {
                        $rule['class'] = $value['class'];
                    }

                    if (isset($value['verb'])) {
                        $rule['verb'] = is_array($value['verb'])
                            ? $value['verb']
                            : explode(',', $value['verb']);
                    }

                    if (isset($value['suffix'])) {
                        $rule['suffix'] = $value['suffix'];
                    }

                    if (!empty($rule)) {
                        $rules[] = $rule;
                    }
                } elseif (is_string($value)) {
                    // Numeric key with string value: just a route pattern
                    $rules[] = [
                        'pattern' => $value,
                    ];
                }
            }

            return $rules;
        } catch (\Throwable $e) {
            // Config file may have dependencies that aren't available
            return [];
        }
    }
}
