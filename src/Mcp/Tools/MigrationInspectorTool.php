<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use Yii;
use codechap\yii2boost\Mcp\Tools\Base\BaseTool;

/**
 * Migration Inspector Tool
 *
 * Inspects Yii2 database migrations including:
 * - Migration status summary (applied, pending, total)
 * - Applied migration history with timestamps
 * - Pending migration discovery from migration directories
 * - Individual migration source code viewing
 */
final class MigrationInspectorTool extends BaseTool
{
    public function getName(): string
    {
        return 'migration_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect database migrations: status, history, pending, and source code';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'migration' => [
                    'type' => 'string',
                    'description' => 'Specific migration name to view details/source. Omit for overview.',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'What to include: status, history, pending, source, all. '
                        . 'Defaults to [status, history].',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Limit number of history/pending results. Default: 50.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $migration = $arguments['migration'] ?? null;
        $include = $arguments['include'] ?? ['status', 'history'];
        $limit = $arguments['limit'] ?? 50;

        if (in_array('all', $include, true)) {
            $include = ['status', 'history', 'pending', 'source'];
        }

        if ($migration !== null) {
            return $this->viewMigration($migration);
        }

        $result = [];

        if (in_array('status', $include, true)) {
            $result['status'] = $this->getStatus();
        }

        if (in_array('history', $include, true)) {
            $result['history'] = $this->getHistory($limit);
        }

        if (in_array('pending', $include, true)) {
            $result['pending'] = $this->getPending($limit);
        }

        return $result;
    }

    /**
     * Get migration status summary
     *
     * @return array
     */
    private function getStatus(): array
    {
        $applied = $this->getAppliedMigrations();
        $allMigrations = $this->getAllMigrationNames();
        $appliedNames = array_keys($applied);
        $pending = array_diff($allMigrations, $appliedNames);

        $result = [
            'total' => count($appliedNames) + count($pending),
            'applied' => count($appliedNames),
            'pending' => count($pending),
            'migration_paths' => $this->getMigrationPaths(),
            'migration_table' => $this->getMigrationTableName(),
        ];

        if (!empty($applied)) {
            // Get the most recently applied migration (excluding base)
            $filtered = $applied;
            unset($filtered['m000000_000000_base']);
            if (!empty($filtered)) {
                arsort($filtered);
                $lastName = array_key_first($filtered);
                $result['last_applied'] = [
                    'version' => $lastName,
                    'applied_at' => date('Y-m-d H:i:s', $filtered[$lastName]),
                ];
            }
        }

        return $result;
    }

    /**
     * Get migration history (applied migrations)
     *
     * @param int $limit Maximum number of results
     * @return array
     */
    private function getHistory(int $limit): array
    {
        $applied = $this->getAppliedMigrations();
        unset($applied['m000000_000000_base']);

        // Sort by apply_time descending (most recent first)
        arsort($applied);

        $history = [];
        $count = 0;
        foreach ($applied as $version => $applyTime) {
            if ($count >= $limit) {
                break;
            }
            $history[] = [
                'version' => $version,
                'applied_at' => date('Y-m-d H:i:s', $applyTime),
            ];
            $count++;
        }

        return $history;
    }

    /**
     * Get pending (unapplied) migrations
     *
     * @param int $limit Maximum number of results
     * @return array
     */
    private function getPending(int $limit): array
    {
        $applied = $this->getAppliedMigrations();
        $appliedNames = array_keys($applied);
        $allMigrations = $this->getAllMigrationNames();

        $pending = array_diff($allMigrations, $appliedNames);
        sort($pending);

        $result = [];
        $count = 0;
        foreach ($pending as $name) {
            if ($count >= $limit) {
                break;
            }
            $entry = ['version' => $name];
            $filePath = $this->findMigrationFile($name);
            if ($filePath !== null) {
                $entry['file'] = $filePath;
            }
            $result[] = $entry;
            $count++;
        }

        return $result;
    }

    /**
     * View details of a specific migration
     *
     * @param string $name Migration name
     * @return array
     * @throws \Exception
     */
    private function viewMigration(string $name): array
    {
        $result = [
            'version' => $name,
        ];

        // Check if applied
        $applied = $this->getAppliedMigrations();
        if (isset($applied[$name])) {
            $result['applied'] = true;
            $result['applied_at'] = date('Y-m-d H:i:s', $applied[$name]);
        } else {
            $result['applied'] = false;
        }

        // Find and read source file
        $filePath = $this->findMigrationFile($name);
        if ($filePath !== null) {
            $result['file'] = $filePath;
            $source = file_get_contents($filePath);
            if ($source !== false) {
                $result['source'] = $source;
            }
        } else {
            $result['file'] = null;
            $result['note'] = 'Migration file not found in configured migration paths. '
                . 'It may have been applied from a different location or removed.';
        }

        return $result;
    }

    /**
     * Get all applied migrations from the migration table
     *
     * @return array Map of version => apply_time (int)
     */
    private function getAppliedMigrations(): array
    {
        $db = Yii::$app->db;
        $tableName = $this->getMigrationTableName();

        try {
            $schema = $db->getSchema();
            $tableSchema = $schema->getTableSchema($tableName);
            if ($tableSchema === null) {
                return [];
            }

            $rows = (new \yii\db\Query())
                ->select(['version', 'apply_time'])
                ->from($tableName)
                ->all($db);

            $result = [];
            foreach ($rows as $row) {
                $result[$row['version']] = (int) $row['apply_time'];
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get all migration names from migration directories
     *
     * @return array List of migration names
     */
    private function getAllMigrationNames(): array
    {
        $paths = $this->getMigrationPaths();
        $migrations = [];

        foreach ($paths as $path) {
            $resolved = Yii::getAlias($path, false);
            if ($resolved === false || !is_dir($resolved)) {
                continue;
            }
            $migrations = array_merge($migrations, $this->scanMigrationDirectory($resolved));
        }

        return array_unique($migrations);
    }

    /**
     * Scan a directory for migration files
     *
     * @param string $path Absolute directory path
     * @return array List of migration names
     */
    private function scanMigrationDirectory(string $path): array
    {
        $files = glob($path . '/m[0-9][0-9][0-9][0-9][0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9]_*.php');
        if ($files === false) {
            return [];
        }

        $names = [];
        foreach ($files as $file) {
            $names[] = basename($file, '.php');
        }

        return $names;
    }

    /**
     * Find the file path for a migration by name
     *
     * @param string $name Migration name
     * @return string|null Absolute file path or null
     */
    private function findMigrationFile(string $name): ?string
    {
        $paths = $this->getMigrationPaths();

        foreach ($paths as $path) {
            $resolved = Yii::getAlias($path, false);
            if ($resolved === false || !is_dir($resolved)) {
                continue;
            }
            $file = $resolved . '/' . $name . '.php';
            if (file_exists($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Get configured migration paths
     *
     * @return array List of migration path aliases/paths
     */
    private function getMigrationPaths(): array
    {
        $paths = ['@app/migrations'];

        // Check for additional migration paths in params
        $params = Yii::$app->params;
        if (isset($params['migrationPath'])) {
            $extra = $params['migrationPath'];
            if (is_array($extra)) {
                $paths = array_merge($paths, $extra);
            } else {
                $paths[] = $extra;
            }
        }

        return array_unique($paths);
    }

    /**
     * Get the migration table name
     *
     * @return string
     */
    private function getMigrationTableName(): string
    {
        return '{{%migration}}';
    }
}
