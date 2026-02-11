<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Tools;

use codechap\yii2boost\Mcp\Tools\ConsoleCommandInspectorTool;

class ConsoleCommandInspectorToolTest extends ToolTestCase
{
    private ConsoleCommandInspectorTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new ConsoleCommandInspectorTool([
            'basePath' => __DIR__ . '/../../fixtures/app',
        ]);
    }

    public function testGetName(): void
    {
        $this->assertSame('console_command_inspector', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('command', $schema['properties']);
        $this->assertArrayHasKey('action', $schema['properties']);
        $this->assertArrayHasKey('include', $schema['properties']);
    }

    public function testListCommands(): void
    {
        $result = $this->tool->execute([]);
        $this->assertArrayHasKey('commands', $result);
        $this->assertIsArray($result['commands']);
        // The hello controller from fixtures should be discoverable
        $this->assertArrayHasKey('hello', $result['commands']);
    }

    public function testInspectCommand(): void
    {
        $result = $this->tool->execute([
            'command' => 'hello',
            'include' => ['actions'],
        ]);

        $this->assertSame('hello', $result['id']);
        $this->assertSame('app\\commands\\HelloController', $result['class']);
        $this->assertSame('index', $result['default_action']);
        $this->assertArrayHasKey('actions', $result);
        $this->assertArrayHasKey('index', $result['actions']);
        $this->assertArrayHasKey('greet', $result['actions']);
    }

    public function testInspectCommandHelpText(): void
    {
        $result = $this->tool->execute([
            'command' => 'hello',
            'include' => ['help'],
        ]);

        $this->assertArrayHasKey('description', $result);
        $this->assertNotEmpty($result['description']);
    }

    public function testInspectCommandOptions(): void
    {
        $result = $this->tool->execute([
            'command' => 'hello',
            'include' => ['options'],
        ]);

        $this->assertArrayHasKey('options', $result);
        $this->assertArrayHasKey('greeting', $result['options']);
        $this->assertArrayHasKey('uppercase', $result['options']);

        $this->assertArrayHasKey('option_aliases', $result);
        $this->assertSame('greeting', $result['option_aliases']['g']);
        $this->assertSame('uppercase', $result['option_aliases']['u']);
    }

    public function testInspectAction(): void
    {
        $result = $this->tool->execute([
            'command' => 'hello',
            'action' => 'greet',
            'include' => ['help', 'arguments'],
        ]);

        $this->assertSame('hello', $result['command']);
        $this->assertSame('greet', $result['action']);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('arguments', $result);
    }

    public function testActionArguments(): void
    {
        $result = $this->tool->execute([
            'command' => 'hello',
            'action' => 'index',
            'include' => ['arguments'],
        ]);

        $this->assertArrayHasKey('arguments', $result);
        // actionIndex has optional $name parameter
        $this->assertNotEmpty($result['arguments']);
    }

    public function testCommandNotFound(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Console command 'nonexistent' not found");
        $this->tool->execute(['command' => 'nonexistent']);
    }

    public function testActionNotFound(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Action 'nonexistent' not found in command 'hello'");
        $this->tool->execute([
            'command' => 'hello',
            'action' => 'nonexistent',
        ]);
    }

    public function testIncludeAll(): void
    {
        $result = $this->tool->execute([
            'command' => 'hello',
            'include' => ['all'],
        ]);

        $this->assertArrayHasKey('actions', $result);
        $this->assertArrayHasKey('options', $result);
        $this->assertArrayHasKey('option_aliases', $result);
        $this->assertArrayHasKey('description', $result);
    }

    public function testIncludeAllOnAction(): void
    {
        $result = $this->tool->execute([
            'command' => 'hello',
            'action' => 'greet',
            'include' => ['all'],
        ]);

        $this->assertArrayHasKey('arguments', $result);
        $this->assertArrayHasKey('options', $result);
        $this->assertArrayHasKey('option_aliases', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('description', $result);
    }

    public function testListCommandsIncludesDescription(): void
    {
        $result = $this->tool->execute([]);
        $hello = $result['commands']['hello'];

        $this->assertArrayHasKey('description', $hello);
        $this->assertArrayHasKey('default_action', $hello);
        $this->assertSame('index', $hello['default_action']);
    }

    public function testActionInlineType(): void
    {
        $result = $this->tool->execute([
            'command' => 'hello',
            'include' => ['actions'],
        ]);

        $this->assertSame('inline', $result['actions']['index']['type']);
        $this->assertSame('inline', $result['actions']['greet']['type']);
    }
}
