<?php

declare(strict_types=1);

namespace codechap\yii2boost\Helpers;

use Yii;

/**
 * Resolves the true project root directory for both basic and advanced Yii2 applications.
 *
 * In basic apps, @app points to the project root.
 * In advanced apps, @app points to one of the sub-applications (e.g. console/),
 * so we need to detect this and resolve to the actual project root (the parent directory).
 */
final class ProjectRootResolver
{
    /**
     * Directories that indicate an advanced Yii2 application structure
     * when found as siblings of the @app directory.
     */
    private const ADVANCED_INDICATORS = ['common', 'console', 'backend', 'frontend'];

    /**
     * Minimum number of indicator directories that must exist to confirm advanced structure.
     */
    private const MIN_INDICATORS = 2;

    /**
     * Resolve the project root directory.
     *
     * Strategy:
     * 1. Check if @root alias is defined (common convention in advanced apps)
     * 2. Detect advanced structure by checking for sibling directories
     * 3. Fall back to @app
     *
     * @return string Absolute path to the project root
     */
    public static function resolve(): string
    {
        // Strategy 1: Check @root alias (many advanced apps define this)
        $root = Yii::getAlias('@root', false);
        if ($root !== false && is_dir($root)) {
            return rtrim($root, '/');
        }

        $appPath = Yii::getAlias('@app');

        // Strategy 2: Detect advanced app structure
        if (self::isAdvancedApp($appPath)) {
            return dirname($appPath);
        }

        // Strategy 3: Fall back to @app (basic app)
        return $appPath;
    }

    /**
     * Detect if the application is using Yii2 advanced template structure.
     *
     * Checks if the parent directory of @app contains the characteristic
     * directories of an advanced app (common/, console/, backend/, frontend/).
     *
     * @param string $appPath The @app path
     * @return bool True if advanced structure is detected
     */
    public static function isAdvancedApp(?string $appPath = null): bool
    {
        if ($appPath === null) {
            $appPath = Yii::getAlias('@app');
        }

        $parentDir = dirname($appPath);
        $found = 0;

        foreach (self::ADVANCED_INDICATORS as $dir) {
            if (is_dir($parentDir . '/' . $dir)) {
                $found++;
            }
        }

        return $found >= self::MIN_INDICATORS;
    }

    /**
     * Get all application directories in an advanced app.
     *
     * Returns paths to backend, frontend, console, api, and any other
     * application directories found in the project root.
     *
     * @param string $projectRoot The project root directory
     * @return array<string, string> Map of app name => absolute path
     */
    public static function getAppDirectories(string $projectRoot): array
    {
        $apps = [];
        $knownApps = ['backend', 'frontend', 'console', 'common', 'api'];

        foreach ($knownApps as $appName) {
            $path = $projectRoot . '/' . $appName;
            if (is_dir($path)) {
                $apps[$appName] = $path;
            }
        }

        return $apps;
    }

    /**
     * Get all model directories across all applications in an advanced app.
     *
     * Scans common/models/, backend/models/, frontend/models/, api/models/, etc.
     *
     * @param string $projectRoot The project root directory
     * @return array<string> List of absolute paths to model directories
     */
    public static function getModelDirectories(string $projectRoot): array
    {
        $dirs = [];
        $appDirs = self::getAppDirectories($projectRoot);

        foreach ($appDirs as $appPath) {
            $modelsPath = $appPath . '/models';
            if (is_dir($modelsPath)) {
                $dirs[] = $modelsPath;
            }
        }

        return $dirs;
    }

    /**
     * Get the path to the yii console entry script.
     *
     * In basic apps: @app/yii
     * In advanced apps: projectRoot/yii
     *
     * @param string $projectRoot The project root directory
     * @return string|null Path to yii script, or null if not found
     */
    public static function getYiiScriptPath(string $projectRoot): ?string
    {
        $path = $projectRoot . '/yii';
        return file_exists($path) ? $path : null;
    }
}
