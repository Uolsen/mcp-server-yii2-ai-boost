<?php

declare(strict_types=1);

namespace codechap\yii2boost\Commands;

use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Boost Command Controller
 *
 * Main controller that delegates to specific boost command handlers.
 * This controller is automatically registered by Bootstrap.php when
 * the package is installed via Composer.
 *
 * Available commands:
 *   php yii boost                (display help - default action)
 *   php yii boost/install        (run installation wizard)
 *   php yii boost/mcp            (start MCP server)
 *   php yii boost/info           (display package information)
 *   php yii boost/update         (update guidelines, skills & search index)
 *   php yii boost/sync-rules     (sync rules to Cursor/Zed editors)
 */
class BoostController extends Controller
{
    /**
     * Display Yii2 AI Boost help (default action)
     *
     * @return int
     */
    public function actionIndex()
    {
        $this->stdout("Yii2 AI Boost CLI\n\n", 36);
        $this->stdout("Available commands:\n");
        $this->stdout("  yii boost/install    - Install guidelines, skills & MCP config\n");
        $this->stdout("  yii boost/update     - Update guidelines, skills & search index\n");
        $this->stdout("  yii boost/info       - Show installation status\n");
        $this->stdout("  yii boost/mcp        - Run the MCP server (stdio mode)\n");
        $this->stdout("  yii boost/sync-rules - Sync rules to Cursor/Zed editors\n");
        $this->stdout("\n");
        $this->stdout("Architecture:\n", 33);
        $this->stdout("  Guidelines  - Compact rules embedded in CLAUDE.md (always loaded)\n");
        $this->stdout("  Skills      - Detailed references in .claude/skills/ (on-demand)\n");
        $this->stdout("  MCP Tools   - Live introspection of your Yii2 application\n");
        return ExitCode::OK;
    }

    /**
     * Run boost install wizard
     *
     * @return int
     */
    public function actionInstall(): int
    {
        $controller = new InstallController('boost/install', \Yii::$app);
        return $controller->runAction('index');
    }

    /**
     * Start MCP server
     *
     * @return int
     */
    public function actionMcp(): int
    {
        $controller = new McpController('boost/mcp', \Yii::$app);
        return $controller->runAction('index');
    }

    /**
     * Display Yii2 AI Boost information
     *
     * @return int
     */
    public function actionInfo(): int
    {
        $controller = new InfoController('boost/info', \Yii::$app);
        return $controller->runAction('index');
    }

    /**
     * Sync AI guidelines to editor rules
     *
     * @return int
     */
    public function actionSyncRules(): int
    {
        $controller = new SyncRulesController('boost/sync-rules', \Yii::$app);
        return $controller->runAction('index');
    }

    /**
     * Update Yii2 AI Boost components
     *
     * @return int
     */
    public function actionUpdate(): int
    {
        $controller = new UpdateController('boost/update', \Yii::$app);
        return $controller->runAction('index');
    }
}
