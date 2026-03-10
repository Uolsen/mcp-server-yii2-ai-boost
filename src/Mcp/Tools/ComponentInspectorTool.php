<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use Yii;
use codechap\yii2boost\Mcp\Tools\Base\BaseTool;

/**
 * Component Inspector Tool
 *
 * Provides application component introspection including:
 * - All registered components
 * - Component class names and configurations
 * - Bootstrap status
 * - Singleton vs new instance behavior
 */
final class ComponentInspectorTool extends BaseTool
{
    public function getName(): string
    {
        return 'component_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect application components including their classes, configurations, and properties';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'component' => [
                    'type' => 'string',
                    'description' => 'Specific component to inspect (optional)',
                ],
                'include_config' => [
                    'type' => 'boolean',
                    'description' => 'Include full configuration (default: true)',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $component = $arguments['component'] ?? null;
        $includeConfig = isset($arguments['include_config']) ? $arguments['include_config'] : true;

        if ($component) {
            return $this->getComponentDetails($component, $includeConfig);
        }

        return $this->listComponents($includeConfig);
    }

    /**
     * List all components
     *
     * @param bool $includeConfig Include configuration details
     * @return array
     */
    private function listComponents(bool $includeConfig = true): array
    {
        $app = Yii::$app;
        $components = [];

        try {
            foreach ($app->getComponents() as $id => $componentDef) {
                try {
                    $components[$id] = $this->getComponentSummary($id, $componentDef, $includeConfig);
                } catch (\Throwable $e) {
                    $components[$id] = [
                        'id' => $id,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            throw $e;
        }

        return ['components' => $components];
    }

    /**
     * Get a summary of a component without forcing instantiation.
     * Used in listing mode to avoid triggering infrastructure connections.
     *
     * @param string $id Component ID
     * @param mixed $componentDef Component definition (config array, object, or string)
     * @param bool $includeConfig Include configuration details
     * @return array
     */
    private function getComponentSummary(string $id, mixed $componentDef, bool $includeConfig): array
    {
        $details = ['id' => $id];

        if (is_object($componentDef)) {
            // Already instantiated
            $details['class'] = get_class($componentDef);
            $details['is_loaded'] = true;
        } elseif (is_array($componentDef)) {
            $details['class'] = $componentDef['class'] ?? 'unknown';
            $details['is_loaded'] = false;
        } elseif (is_string($componentDef)) {
            $details['class'] = $componentDef;
            $details['is_loaded'] = false;
        } else {
            $details['class'] = 'unknown';
            $details['is_loaded'] = false;
        }

        if ($includeConfig && is_array($componentDef)) {
            $details['config'] = $this->sanitize($componentDef);
        }

        return $details;
    }

    /**
     * Get details for a specific component
     *
     * @param string $id Component ID
     * @param bool $includeConfig Include configuration details
     * @return array
     * @throws \Exception
     */
    private function getComponentDetails(string $id, bool $includeConfig = true): array
    {
        $app = Yii::$app;

        if (!$app->has($id)) {
            throw new \Exception("Component '$id' not found");
        }

        // Get the component definition (config)
        $componentDef = $app->components[$id] ?? [];

        // Get the loaded instance (if available)
        $component = null;
        try {
            $component = $app->get($id);
        } catch (\Throwable $e) {
            // Component couldn't be loaded (e.g. infrastructure dependency unavailable)
        }

        // Determine class name from component instance or definition
        $className = 'unknown';
        if ($component) {
            $className = get_class($component);
        } elseif (is_array($componentDef)) {
            $className = $componentDef['class'] ?? 'unknown';
        }

        $details = [
            'id' => $id,
            'class' => $className,
            'is_loaded' => $component !== null,
        ];

        // Add configuration if requested
        if ($includeConfig && is_array($componentDef)) {
            $details['config'] = $this->sanitize($componentDef);
        }

        // Add public properties if component is loaded
        if ($component) {
            $details['properties'] = $this->getComponentProperties($component);
        }

        return $details;
    }

    /**
     * Get public properties of a component
     *
     * @param object $component Component instance
     * @return array
     */
    private function getComponentProperties(object $component): array
    {
        $properties = [];

        try {
            $reflection = new \ReflectionClass($component);

            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if (!$property->isStatic()) {
                    $name = $property->getName();
                    try {
                        $value = $property->getValue($component);

                        // Try to get a readable value
                        if (is_object($value)) {
                            $properties[$name] = get_class($value);
                        } elseif (is_array($value)) {
                            $properties[$name] = '[array with ' . count($value) . ' items]';
                        } else {
                            $properties[$name] = $value;
                        }
                    } catch (\Exception $e) {
                        $properties[$name] = '[unable to read]';
                    }
                }
            }
        } catch (\Exception $e) {
            // Cannot reflect component
        }

        return $properties;
    }
}
