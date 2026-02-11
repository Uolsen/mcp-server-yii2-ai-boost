<?php

declare(strict_types=1);

namespace app\commands;

use yii\console\Controller;
use yii\console\ExitCode;

/**
 * A greeting command for testing console command discovery.
 *
 * This controller provides greeting actions for the console command inspector tests.
 */
class HelloController extends Controller
{
    /**
     * @var string The greeting word to use.
     */
    public $greeting = 'Hello';

    /**
     * @var bool Whether to output in uppercase.
     */
    public $uppercase = false;

    /**
     * Displays a greeting message.
     *
     * Shows a simple greeting to the given name, or "World" if no name is provided.
     *
     * @param string $name The name to greet.
     * @return int Exit code
     */
    public function actionIndex(string $name = 'World'): int
    {
        $message = $this->greeting . ', ' . $name . '!';
        if ($this->uppercase) {
            $message = strtoupper($message);
        }
        $this->stdout($message . "\n");
        return ExitCode::OK;
    }

    /**
     * Greets a person multiple times.
     *
     * Repeats the greeting the specified number of times.
     *
     * @param string $name The name to greet.
     * @param int $times How many times to repeat the greeting.
     * @return int Exit code
     */
    public function actionGreet(string $name, int $times = 1): int
    {
        for ($i = 0; $i < $times; $i++) {
            $message = $this->greeting . ', ' . $name . '!';
            if ($this->uppercase) {
                $message = strtoupper($message);
            }
            $this->stdout($message . "\n");
        }
        return ExitCode::OK;
    }

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'greeting',
            'uppercase',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'g' => 'greeting',
            'u' => 'uppercase',
        ]);
    }
}
