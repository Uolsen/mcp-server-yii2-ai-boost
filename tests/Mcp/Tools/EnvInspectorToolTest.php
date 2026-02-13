<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Tools;

use codechap\yii2boost\Mcp\Tools\EnvInspectorTool;

class EnvInspectorToolTest extends ToolTestCase
{
    private EnvInspectorTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new EnvInspectorTool([
            'basePath' => __DIR__ . '/../../fixtures/app',
        ]);
    }

    public function testGetName(): void
    {
        $this->assertSame('env_inspector', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('include', $schema['properties']);
        $this->assertArrayHasKey('filter', $schema['properties']);
    }

    public function testDefaultMode(): void
    {
        $result = $this->tool->execute([]);

        $this->assertArrayHasKey('env_vars', $result);
        $this->assertArrayHasKey('extensions', $result);
        $this->assertArrayHasKey('php_config', $result);
        $this->assertArrayNotHasKey('system', $result);
    }

    public function testEnvVars(): void
    {
        $result = $this->tool->execute(['include' => ['env_vars']]);

        $this->assertArrayHasKey('env_vars', $result);
        $this->assertIsArray($result['env_vars']);
    }

    public function testEnvVarsSanitized(): void
    {
        // Set a sensitive env var for testing
        putenv('TEST_SECRET_KEY=my-secret-value');

        try {
            $result = $this->tool->execute(['include' => ['env_vars']]);

            // The key contains "secret" and "key", so it should be redacted
            $this->assertArrayHasKey('TEST_SECRET_KEY', $result['env_vars']);
            $this->assertSame('***REDACTED***', $result['env_vars']['TEST_SECRET_KEY']);
        } finally {
            putenv('TEST_SECRET_KEY');
        }
    }

    public function testEnvVarsFilter(): void
    {
        putenv('BOOST_TEST_VAR=hello');
        putenv('BOOST_OTHER_VAR=world');

        try {
            $result = $this->tool->execute([
                'include' => ['env_vars'],
                'filter' => 'BOOST_',
            ]);

            $envVars = $result['env_vars'];
            $this->assertArrayHasKey('BOOST_TEST_VAR', $envVars);
            $this->assertArrayHasKey('BOOST_OTHER_VAR', $envVars);

            // Ensure non-matching vars are excluded
            foreach ($envVars as $key => $value) {
                $this->assertStringStartsWith('BOOST_', $key);
            }
        } finally {
            putenv('BOOST_TEST_VAR');
            putenv('BOOST_OTHER_VAR');
        }
    }

    public function testExtensions(): void
    {
        $result = $this->tool->execute(['include' => ['extensions']]);

        $this->assertArrayHasKey('extensions', $result);
        $extensions = $result['extensions'];
        $this->assertArrayHasKey('count', $extensions);
        $this->assertArrayHasKey('list', $extensions);
        $this->assertGreaterThan(0, $extensions['count']);
        $this->assertContains('json', $extensions['list']);
        $this->assertContains('PDO', $extensions['list']);

        // Verify sorted
        $sorted = $extensions['list'];
        sort($sorted);
        $this->assertSame($sorted, $extensions['list']);
    }

    public function testPhpConfig(): void
    {
        $result = $this->tool->execute(['include' => ['php_config']]);

        $this->assertArrayHasKey('php_config', $result);
        $config = $result['php_config'];
        $this->assertArrayHasKey('memory_limit', $config);
        $this->assertArrayHasKey('max_execution_time', $config);
        $this->assertArrayHasKey('upload_max_filesize', $config);
        $this->assertArrayHasKey('post_max_size', $config);
        $this->assertArrayHasKey('error_reporting', $config);
        $this->assertArrayHasKey('display_errors', $config);
        $this->assertArrayHasKey('date.timezone', $config);
        $this->assertArrayHasKey('max_input_vars', $config);
        $this->assertArrayHasKey('opcache.enable', $config);
    }

    public function testSystemInfo(): void
    {
        $result = $this->tool->execute(['include' => ['system']]);

        $this->assertArrayHasKey('system', $result);
        $system = $result['system'];
        $this->assertArrayHasKey('os', $system);
        $this->assertArrayHasKey('os_detail', $system);
        $this->assertArrayHasKey('architecture', $system);
        $this->assertArrayHasKey('cwd', $system);
        $this->assertNotEmpty($system['os']);
        $this->assertNotEmpty($system['cwd']);
    }

    public function testIncludeAll(): void
    {
        $result = $this->tool->execute(['include' => ['all']]);

        $this->assertArrayHasKey('env_vars', $result);
        $this->assertArrayHasKey('extensions', $result);
        $this->assertArrayHasKey('php_config', $result);
        $this->assertArrayHasKey('system', $result);
    }

    public function testIncludeSpecific(): void
    {
        $result = $this->tool->execute(['include' => ['extensions', 'system']]);

        $this->assertArrayHasKey('extensions', $result);
        $this->assertArrayHasKey('system', $result);
        $this->assertArrayNotHasKey('env_vars', $result);
        $this->assertArrayNotHasKey('php_config', $result);
    }
}
