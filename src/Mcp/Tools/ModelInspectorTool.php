<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use codechap\yii2boost\Mcp\Tools\Base\BaseTool;

/**
 * Model Inspector Tool
 *
 * Provides Active Record model analysis including:
 * - Attributes with types, labels, and hints
 * - Relations (hasOne/hasMany) with link details
 * - Attached behaviors
 * - Scenarios with active attributes
 * - Fields and extra fields for API serialization
 */
final class ModelInspectorTool extends BaseTool
{
    public function getName(): string
    {
        return 'model_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect Active Record models including attributes, relations, behaviors, scenarios, and fields';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'model' => [
                    'type' => 'string',
                    'description' => 'Model class name or short name (e.g., "User" or "app\\models\\User")',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'What to include: attributes, relations, behaviors, scenarios, fields, all',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $modelName = $arguments['model'] ?? '';
        $include = $arguments['include'] ?? ['attributes', 'relations'];

        if (empty($modelName)) {
            return ['models' => $this->getActiveRecordModels()];
        }

        if (in_array('all', $include)) {
            $include = ['attributes', 'relations', 'behaviors', 'scenarios', 'fields'];
        }

        $className = $this->resolveModelClass($modelName);

        try {
            $instance = new $className();
        } catch (\Throwable $e) {
            throw new \Exception("Cannot instantiate model '$className': " . $e->getMessage());
        }

        $result = [
            'class' => $className,
            'table' => $className::tableName(),
        ];

        try {
            $result['primary_key'] = $className::primaryKey();
        } catch (\Throwable $e) {
            // primaryKey() may require DB on some drivers
            $result['primary_key'] = null;
            $result['_warnings'][] = 'Could not determine primary key: ' . $e->getMessage();
        }

        if (in_array('attributes', $include)) {
            try {
                $result['attributes'] = $this->getAttributes($instance);
            } catch (\Throwable $e) {
                $result['attributes'] = $this->getAttributesFallback($instance);
                $result['_warnings'][] = 'Attributes loaded without DB schema: ' . $e->getMessage();
            }
        }

        if (in_array('relations', $include)) {
            try {
                $result['relations'] = $this->getRelations($instance);
            } catch (\Throwable $e) {
                $result['relations'] = [];
                $result['_warnings'][] = 'Could not inspect relations: ' . $e->getMessage();
            }
        }

        if (in_array('behaviors', $include)) {
            try {
                $result['behaviors'] = $this->inspectBehaviors($instance);
            } catch (\Throwable $e) {
                $result['behaviors'] = [];
                $result['_warnings'][] = 'Could not inspect behaviors: ' . $e->getMessage();
            }
        }

        if (in_array('scenarios', $include)) {
            try {
                $result['scenarios'] = $this->getScenarios($instance);
            } catch (\Throwable $e) {
                $result['scenarios'] = [];
                $result['_warnings'][] = 'Could not inspect scenarios: ' . $e->getMessage();
            }
        }

        if (in_array('fields', $include)) {
            try {
                $result['fields'] = $this->getFields($instance);
            } catch (\Throwable $e) {
                $result['fields'] = [];
                $result['_warnings'][] = 'Could not inspect fields: ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Get model attributes with types from table schema, labels, and hints
     *
     * @param object $instance Model instance
     * @return array
     */
    private function getAttributes(object $instance): array
    {
        $attributes = $instance->attributes();
        $labels = $instance->attributeLabels();
        $hints = $instance->attributeHints();

        $tableSchema = null;
        if (method_exists($instance, 'getTableSchema')) {
            try {
                $tableSchema = $instance::getTableSchema();
            } catch (\Exception $e) {
                // Table may not exist
            }
        }

        $result = [];
        foreach ($attributes as $name) {
            $attr = [
                'label' => $labels[$name] ?? null,
                'hint' => $hints[$name] ?? null,
            ];

            if ($tableSchema && isset($tableSchema->columns[$name])) {
                $column = $tableSchema->columns[$name];
                $attr['type'] = $column->type;
                $attr['db_type'] = $column->dbType;
                $attr['php_type'] = $column->phpType;
                $attr['size'] = $column->size;
                $attr['allow_null'] = $column->allowNull;
                $attr['default'] = $column->defaultValue;
                $attr['auto_increment'] = $column->autoIncrement;
            }

            $result[$name] = $attr;
        }

        return $result;
    }

    /**
     * Fallback attribute extraction when DB is unavailable.
     * Uses rules(), attributeLabels() and attributeHints() which don't require DB.
     *
     * @param object $instance Model instance
     * @return array
     */
    private function getAttributesFallback(object $instance): array
    {
        $result = [];

        // Extract attribute names from rules() — this never touches the DB
        $attributeNames = [];
        try {
            $rules = $instance->rules();
            foreach ($rules as $rule) {
                if (is_array($rule) && isset($rule[0])) {
                    $attrs = (array) $rule[0];
                    foreach ($attrs as $attr) {
                        $attributeNames[$attr] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // rules() should not fail, but be safe
        }

        $labels = [];
        try {
            $labels = $instance->attributeLabels();
        } catch (\Throwable $e) {
        }

        // Merge attribute names from labels too
        foreach (array_keys($labels) as $attr) {
            $attributeNames[$attr] = true;
        }

        $hints = [];
        try {
            $hints = $instance->attributeHints();
        } catch (\Throwable $e) {
        }

        foreach (array_keys($attributeNames) as $name) {
            $result[$name] = [
                'label' => $labels[$name] ?? null,
                'hint' => $hints[$name] ?? null,
                'source' => 'rules/labels (no DB connection)',
            ];
        }

        return $result;
    }

    /**
     * Discover model relations by inspecting getter methods that return ActiveQuery
     *
     * @param object $instance Model instance
     * @return array
     */
    private function getRelations(object $instance): array
    {
        $relations = [];

        $reflection = new \ReflectionClass($instance);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->getName();

            // Only consider get*() methods with no required parameters
            if (strpos($methodName, 'get') !== 0 || strlen($methodName) <= 3) {
                continue;
            }
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            // Skip methods declared in framework classes
            $declaringClass = $method->getDeclaringClass()->getName();
            if (strpos($declaringClass, 'yii\\') === 0) {
                continue;
            }

            // Try to call the method and check if it returns an ActiveQuery
            try {
                $returnValue = $instance->{$methodName}();

                if (!($returnValue instanceof \yii\db\ActiveQueryInterface)) {
                    continue;
                }

                // Extract relation name from method name (getOrders -> orders)
                $relationName = lcfirst(substr($methodName, 3));

                $relation = [
                    'name' => $relationName,
                    'type' => $returnValue->multiple ? 'hasMany' : 'hasOne',
                ];

                if (isset($returnValue->modelClass)) {
                    $relation['model_class'] = $returnValue->modelClass;
                }

                if (isset($returnValue->link)) {
                    $relation['link'] = $returnValue->link;
                }

                if (!empty($returnValue->via)) {
                    $relation['via'] = is_string($returnValue->via)
                        ? $returnValue->via
                        : '[junction]';
                }

                $relations[$relationName] = $relation;
            } catch (\Exception $e) {
                // Method threw an exception — skip it
            }
        }

        return $relations;
    }

    /**
     * Get attached behaviors with their class and configuration
     *
     * @param object $instance Model instance
     * @return array
     */
    private function inspectBehaviors(object $instance): array
    {
        $behaviors = [];

        try {
            $attachedBehaviors = $instance->getBehaviors();
        } catch (\Exception $e) {
            return $behaviors;
        }

        foreach ($attachedBehaviors as $name => $behavior) {
            $behaviorInfo = [
                'class' => get_class($behavior),
            ];

            // Get public properties specific to this behavior
            try {
                $reflection = new \ReflectionClass($behavior);
                $props = [];
                foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                    if ($property->isStatic()) {
                        continue;
                    }
                    // Skip properties inherited from base Behavior class
                    if ($property->getDeclaringClass()->getName() === 'yii\\base\\Behavior') {
                        continue;
                    }
                    $propName = $property->getName();
                    try {
                        $value = $property->getValue($behavior);
                        if (is_object($value)) {
                            $props[$propName] = get_class($value);
                        } elseif (is_array($value)) {
                            $props[$propName] = $value;
                        } else {
                            $props[$propName] = $value;
                        }
                    } catch (\Exception $e) {
                        $props[$propName] = '[unable to read]';
                    }
                }
                if (!empty($props)) {
                    $behaviorInfo['properties'] = $this->sanitize($props);
                }
            } catch (\Exception $e) {
                // Cannot reflect behavior
            }

            $behaviors[$name] = $behaviorInfo;
        }

        return $behaviors;
    }

    /**
     * Get all defined scenarios with their active and safe attributes
     *
     * @param object $instance Model instance
     * @return array
     */
    private function getScenarios(object $instance): array
    {
        $scenarios = $instance->scenarios();
        $result = [];

        foreach ($scenarios as $scenarioName => $attributes) {
            $safe = [];
            $all = [];

            foreach ($attributes as $attribute) {
                if (strpos($attribute, '!') === 0) {
                    $all[] = substr($attribute, 1);
                } else {
                    $safe[] = $attribute;
                    $all[] = $attribute;
                }
            }

            $result[$scenarioName] = [
                'attributes' => $all,
                'safe_attributes' => $safe,
            ];
        }

        return $result;
    }

    /**
     * Get fields and extra fields for API serialization
     *
     * @param object $instance Model instance
     * @return array
     */
    private function getFields(object $instance): array
    {
        // Populate attributes so BaseActiveRecord::fields() returns column names
        // (fields() uses _attributes, which is empty on a fresh instance)
        $attributes = $instance->attributes();
        foreach ($attributes as $attr) {
            $instance->setAttribute($attr, $instance->getAttribute($attr));
        }

        $result = [
            'fields' => array_keys($instance->fields()),
        ];

        if (method_exists($instance, 'extraFields')) {
            $result['extra_fields'] = $instance->extraFields();
        }

        return $result;
    }
}
