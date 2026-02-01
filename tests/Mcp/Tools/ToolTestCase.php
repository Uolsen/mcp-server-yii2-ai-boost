<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use codechap\yii2boost\tests\fixtures\SchemaSetupTrait;

/**
 * Base test case for tool tests requiring Yii2 application context.
 */
abstract class ToolTestCase extends TestCase
{
    use SchemaSetupTrait;

    /**
     * @var bool Whether the Yii2 app has been bootstrapped
     */
    private static bool $appBootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$appBootstrapped) {
            require_once __DIR__ . '/../../yii_bootstrap.php';
            self::$appBootstrapped = true;
        }

        $this->createTestSchema();
    }

    protected function tearDown(): void
    {
        try {
            $this->dropTestSchema();
        } catch (\Exception $e) {
            // Tables may not exist if test failed before creation
        }

        parent::tearDown();
    }
}
