<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use Yii;
use codechap\yii2boost\Mcp\Tools\Base\BaseTool;
use yii\console\Controller;

/**
 * Console Command Inspector Tool
 *
 * Discovers and inspects Yii2 console commands (./yii commands) including:
 * - All registered console controllers
 * - Controller actions, options, and arguments
 * - Help text from PHPDoc annotations
 */
final class ConsoleCommandInspectorTool extends BaseTool
{
    public function getName(): string
    {
        return 'console_command_inspector';
    }

    public function getDescription(): string
    {
        return 'Discover and inspect Yii2 console commands, their actions, options, and arguments';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Controller ID to inspect (e.g. "migrate", "cache"). Omit to list all commands.',
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'Action ID within a command (e.g. "up"). Requires command.',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'What to include: actions, options, arguments, help, all. '
                        . 'Defaults to [actions, help].',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $command = $arguments['command'] ?? null;
        $action = $arguments['action'] ?? null;
        $include = $arguments['include'] ?? ['actions', 'help'];

        if (in_array('all', $include, true)) {
            $include = ['actions', 'options', 'arguments', 'help'];
        }

        if ($command === null) {
            return $this->listCommands();
        }

        if ($action !== null) {
            return $this->inspectAction($command, $action, $include);
        }

        return $this->inspectCommand($command, $include);
    }

    /**
     * List all discoverable console commands
     *
     * @return array
     */
    private function listCommands(): array
    {
        $commands = [];

        // Source 1: controllerMap
        $controllerMap = Yii::$app->controllerMap;
        foreach ($controllerMap as $id => $config) {
            $className = is_array($config)
                ? ($config['class'] ?? 'unknown')
                : (is_string($config) ? $config : 'unknown');
            $info = [
                'id' => $id,
                'class' => $className,
                'source' => 'controllerMap',
            ];

            $controller = $this->createController($id);
            if ($controller !== null) {
                $info['class'] = get_class($controller);
                $info['description'] = $controller->getHelpSummary();
                $info['default_action'] = $controller->defaultAction;
            }

            $commands[$id] = $info;
        }

        // Source 2: controllers in app command namespace directory
        $this->discoverNamespaceControllers($commands);

        // Source 3: module controllers
        $this->discoverModuleControllers($commands);

        return ['commands' => $commands];
    }

    /**
     * Inspect a specific console command
     *
     * @param string $commandId Controller ID
     * @param array $include Sections to include
     * @return array
     * @throws \Exception
     */
    private function inspectCommand(string $commandId, array $include): array
    {
        $controller = $this->createController($commandId);
        if ($controller === null) {
            throw new \Exception("Console command '$commandId' not found");
        }

        $result = [
            'id' => $commandId,
            'class' => get_class($controller),
            'default_action' => $controller->defaultAction,
        ];

        if (in_array('help', $include, true)) {
            $result['description'] = $controller->getHelpSummary();
        }

        if (in_array('actions', $include, true)) {
            $result['actions'] = $this->getControllerActions($controller);
        }

        if (in_array('options', $include, true)) {
            $result['options'] = $this->getControllerOptions($controller);
            $result['option_aliases'] = $controller->optionAliases();
        }

        return $result;
    }

    /**
     * Inspect a specific action within a command
     *
     * @param string $commandId Controller ID
     * @param string $actionId Action ID
     * @param array $include Sections to include
     * @return array
     * @throws \Exception
     */
    private function inspectAction(string $commandId, string $actionId, array $include): array
    {
        $controller = $this->createController($commandId);
        if ($controller === null) {
            throw new \Exception("Console command '$commandId' not found");
        }

        // Verify action exists
        $actions = $this->getControllerActions($controller);
        if (!isset($actions[$actionId])) {
            throw new \Exception("Action '$actionId' not found in command '$commandId'");
        }

        $result = [
            'command' => $commandId,
            'action' => $actionId,
            'class' => get_class($controller),
        ];

        $actionObject = $controller->createAction($actionId);
        if ($actionObject === null) {
            throw new \Exception("Cannot create action '$actionId' for command '$commandId'");
        }

        if (in_array('help', $include, true)) {
            $result['summary'] = $controller->getActionHelpSummary($actionObject);
            $result['description'] = $controller->getActionHelp($actionObject);
        }

        if (in_array('arguments', $include, true)) {
            $result['arguments'] = $controller->getActionArgsHelp($actionObject);
        }

        if (in_array('options', $include, true)) {
            $options = $controller->options($actionId);
            $result['options'] = [];
            $optionsHelp = $controller->getActionOptionsHelp($actionObject);
            foreach ($options as $option) {
                $result['options'][$option] = $optionsHelp[$option] ?? [];
            }
            $result['option_aliases'] = $controller->optionAliases();
        }

        return $result;
    }

    /**
     * Discover controllers from the application's controller namespace directory
     *
     * @param array &$commands Commands array to populate
     */
    private function discoverNamespaceControllers(array &$commands): void
    {
        $app = Yii::$app;

        try {
            $controllerPath = $app->getControllerPath();
        } catch (\Exception $e) {
            return;
        }

        if (!is_dir($controllerPath)) {
            return;
        }

        $this->scanControllerDirectory($controllerPath, '', $commands);
    }

    /**
     * Discover controllers from application modules
     *
     * @param array &$commands Commands array to populate
     */
    private function discoverModuleControllers(array &$commands): void
    {
        $modules = Yii::$app->getModules();

        foreach ($modules as $moduleId => $moduleConfig) {
            try {
                $module = Yii::$app->getModule($moduleId);
                if ($module === null) {
                    continue;
                }

                $modulePath = $module->getControllerPath();
                if (!is_dir($modulePath)) {
                    continue;
                }

                $this->scanControllerDirectory($modulePath, $moduleId . '/', $commands);
            } catch (\Exception $e) {
                // Module may not be instantiable
                continue;
            }
        }
    }

    /**
     * Scan a directory for controller files
     *
     * @param string $path Directory path
     * @param string $prefix Command ID prefix (e.g. "module/")
     * @param array &$commands Commands array to populate
     */
    private function scanControllerDirectory(string $path, string $prefix, array &$commands): void
    {
        $files = glob($path . '/*Controller.php');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $basename = basename($file, 'Controller.php');
            $id = $prefix . $this->camelCaseToId($basename);

            if (isset($commands[$id])) {
                continue;
            }

            $controller = $this->createController($id);
            if ($controller !== null) {
                $commands[$id] = [
                    'id' => $id,
                    'class' => get_class($controller),
                    'description' => $controller->getHelpSummary(),
                    'default_action' => $controller->defaultAction,
                    'source' => $prefix !== '' ? 'module' : 'namespace',
                ];
            }
        }
    }

    /**
     * Get all actions for a controller
     *
     * @param Controller $controller
     * @return array
     */
    private function getControllerActions(Controller $controller): array
    {
        $actions = [];

        // External actions defined in actions() method
        foreach ($controller->actions() as $id => $config) {
            if (is_array($config)) {
                $className = $config['class'];
            } elseif (is_string($config)) {
                $className = $config;
            } else {
                $className = 'unknown';
            }
            $actionObj = $controller->createAction($id);
            $actions[$id] = [
                'type' => 'external',
                'class' => $className,
                'description' => $actionObj !== null ? $controller->getActionHelpSummary($actionObj) : '',
            ];
        }

        // Inline actions from actionXxx methods
        $reflection = new \ReflectionClass($controller);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (strpos($name, 'action') === 0 && $name !== 'actions' && strlen($name) > 6) {
                $actionId = $this->camelCaseToId(substr($name, 6));
                if (!isset($actions[$actionId])) {
                    $actionObj = $controller->createAction($actionId);
                    $actions[$actionId] = [
                        'type' => 'inline',
                        'description' => $actionObj !== null ? $controller->getActionHelpSummary($actionObj) : '',
                    ];
                }
            }
        }

        return $actions;
    }

    /**
     * Get controller-level options
     *
     * @param Controller $controller
     * @return array
     */
    private function getControllerOptions(Controller $controller): array
    {
        $options = [];
        $optionNames = $controller->options('');

        foreach ($optionNames as $name) {
            $options[$name] = [
                'type' => $this->getPropertyType($controller, $name),
                'default' => $this->getPropertyDefault($controller, $name),
            ];
        }

        return $options;
    }

    /**
     * Get property type via reflection
     *
     * @param Controller $controller
     * @param string $property
     * @return string
     */
    private function getPropertyType(Controller $controller, string $property): string
    {
        try {
            $reflection = new \ReflectionProperty($controller, $property);
            $type = $reflection->getType();
            if ($type instanceof \ReflectionNamedType) {
                return $type->getName();
            }
        } catch (\Exception $e) {
            // Property may not exist on the class directly
        }

        return 'mixed';
    }

    /**
     * Get property default value via reflection
     *
     * @param Controller $controller
     * @param string $property
     * @return mixed
     */
    private function getPropertyDefault(Controller $controller, string $property): mixed
    {
        try {
            $reflection = new \ReflectionProperty($controller, $property);
            if ($reflection->hasDefaultValue()) {
                return $reflection->getDefaultValue();
            }
        } catch (\Exception $e) {
            // Property may not exist on the class directly
        }

        return null;
    }

    /**
     * Try to create a controller instance for the given command ID
     *
     * @param string $id Command ID
     * @return Controller|null
     */
    private function createController(string $id): ?Controller
    {
        try {
            $result = Yii::$app->createController($id);
            if ($result !== false) {
                [$controller, ] = $result;
                if ($controller instanceof Controller) {
                    return $controller;
                }
            }
        } catch (\Exception $e) {
            fwrite(STDERR, "[ConsoleCommandInspector] Cannot instantiate controller '$id': " . $e->getMessage() . "\n");
        }

        return null;
    }

    /**
     * Convert CamelCase to hyphenated-id (e.g. "HelloWorld" -> "hello-world")
     *
     * @param string $name CamelCase name
     * @return string Hyphenated ID
     */
    private function camelCaseToId(string $name): string
    {
        $result = preg_replace('/(?<!^)[A-Z]/', '-$0', $name);
        return strtolower($result !== null ? $result : $name);
    }
}
