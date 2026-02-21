<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use Yii;
use codechap\yii2boost\Mcp\Tools\Base\BaseTool;

/**
 * Database Query Tool
 *
 * Execute SQL queries against the database and return results.
 * Intended for development use - allows AI assistants to explore
 * and query data during debugging and development.
 */
final class DatabaseQueryTool extends BaseTool
{
    /**
     * Default maximum rows to return
     */
    private const DEFAULT_LIMIT = 100;

    /**
     * Absolute maximum rows allowed
     */
    private const MAX_LIMIT = 1000;

    public function getName(): string
    {
        return 'database_query';
    }

    public function getDescription(): string
    {
        return 'Execute SQL queries against the database and return results. '
             . 'IMPORTANT: Always use database_schema tool first to inspect table columns before writing queries. '
             . 'Do not guess column names.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'SQL query to execute',
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'Bound parameters for the query (e.g., {":id": 1})',
                    'additionalProperties' => true,
                ],
                'db' => [
                    'type' => 'string',
                    'description' => 'Database connection name (default: db)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum rows to return (default: 100, max: 1000)',
                ],
            ],
            'required' => ['sql'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $sql = $arguments['sql'] ?? '';
        $params = $arguments['params'] ?? [];
        $dbName = $arguments['db'] ?? 'db';
        $limit = $arguments['limit'] ?? self::DEFAULT_LIMIT;

        // Validate SQL is not empty
        if (empty(trim($sql))) {
            throw new \Exception('SQL query cannot be empty');
        }

        // Cap the limit
        $limit = min(max(1, (int) $limit), self::MAX_LIMIT);

        // Get database connection
        if (!Yii::$app->has($dbName)) {
            throw new \Exception("Database connection '$dbName' not found");
        }

        $db = Yii::$app->get($dbName);

        // Add LIMIT if not present and query appears to be a SELECT
        $sql = $this->ensureLimit($sql, $limit);

        try {
            $command = $db->createCommand($sql);

            // Bind parameters if provided
            if (!empty($params)) {
                foreach ($params as $name => $value) {
                    $command->bindValue($name, $value);
                }
            }

            $startTime = microtime(true);

            // Detect query type: SELECT uses queryAll(), everything else uses execute()
            if ($this->isSelectQuery($sql)) {
                $rows = $command->queryAll();
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                $result = [
                    'success' => true,
                    'connection' => $this->getDbConnectionInfo($dbName),
                    'row_count' => count($rows),
                    'duration_ms' => $duration,
                    'rows' => $this->sanitize($rows),
                ];

                // Warn if results were likely truncated
                if (count($rows) === $limit) {
                    $result['warning'] = "Results may be truncated at $limit rows. "
                        . "Use 'limit' parameter to increase.";
                }
            } else {
                $affectedRows = $command->execute();
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                $result = [
                    'success' => true,
                    'connection' => $this->getDbConnectionInfo($dbName),
                    'affected_rows' => $affectedRows,
                    'duration_ms' => $duration,
                ];
            }

            return $result;
        } catch (\Exception $e) {
            $errorResult = [
                'success' => false,
                'error' => $e->getMessage(),
                'sql' => $sql,
            ];

            // On column/table errors, include schema hints so the AI can self-correct
            $errorMsg = $e->getMessage();
            if (
                stripos($errorMsg, 'Unknown column') !== false
                || stripos($errorMsg, 'Column not found') !== false
                || stripos($errorMsg, 'no such column') !== false
                || stripos($errorMsg, 'Unknown table') !== false
                || stripos($errorMsg, 'Table') !== false && stripos($errorMsg, "doesn't exist") !== false
                || stripos($errorMsg, 'Base table or view not found') !== false
            ) {
                $errorResult['hint'] = 'Use the database_schema tool to inspect table columns before writing queries.';
                $errorResult['available_columns'] = $this->getColumnsForTablesInQuery($sql, $db);
            }

            return $errorResult;
        }
    }

    /**
     * Check if a SQL statement is a SELECT query
     *
     * @param string $sql SQL query
     * @return bool
     */
    private function isSelectQuery(string $sql): bool
    {
        return (bool) preg_match('/^\s*SELECT\s/i', trim($sql));
    }

    /**
     * Extract table names from SQL and return their columns for error hints.
     *
     * @param string $sql The SQL query
     * @param object $db Database connection
     * @return array Table => column list mapping
     */
    private function getColumnsForTablesInQuery(string $sql, object $db): array
    {
        $columns = [];

        // Extract table names from FROM and JOIN clauses
        // Matches: FROM tablename [alias], JOIN tablename [alias]
        $pattern = '/(?:FROM|JOIN)\s+`?(\w+)`?(?:\s+(?:AS\s+)?`?(\w+)`?)?/i';
        if (!preg_match_all($pattern, $sql, $matches)) {
            return $columns;
        }

        $schema = $db->getSchema();
        foreach ($matches[1] as $i => $tableName) {
            $alias = !empty($matches[2][$i]) ? $matches[2][$i] : null;
            $label = $alias ? "$tableName ($alias)" : $tableName;

            // Skip if we already got this table
            if (isset($columns[$label])) {
                continue;
            }

            try {
                $tableSchema = $schema->getTableSchema($tableName);
                if ($tableSchema) {
                    $columns[$label] = array_keys($tableSchema->columns);
                }
            } catch (\Exception $e) {
                // Table doesn't exist or other error
                $columns[$label] = 'table not found';
            }
        }

        return $columns;
    }

    /**
     * Ensure SELECT queries have a LIMIT clause
     *
     * @param string $sql SQL query
     * @param int $limit Row limit
     * @return string Modified SQL
     */
    private function ensureLimit(string $sql, int $limit): string
    {
        $trimmedSql = trim($sql);

        // Only add LIMIT to SELECT statements that don't already have one
        if (
            $this->isSelectQuery($trimmedSql) &&
            !preg_match('/\sLIMIT\s+\d+/i', $trimmedSql)
        ) {
            // Remove trailing semicolon if present
            $trimmedSql = rtrim($trimmedSql, ';');
            return $trimmedSql . ' LIMIT ' . $limit;
        }

        return $trimmedSql;
    }
}
