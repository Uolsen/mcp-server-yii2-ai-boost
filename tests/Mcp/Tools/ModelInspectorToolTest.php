<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Tools;

use codechap\yii2boost\Mcp\Tools\ModelInspectorTool;

class ModelInspectorToolTest extends ToolTestCase
{
    private ModelInspectorTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new ModelInspectorTool([
            'basePath' => __DIR__ . '/../../fixtures/app',
        ]);
    }

    public function testGetName(): void
    {
        $this->assertSame('model_inspector', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('model', $schema['properties']);
        $this->assertArrayHasKey('include', $schema['properties']);
    }

    public function testListModelsWhenNoModelSpecified(): void
    {
        $result = $this->tool->execute([]);
        $this->assertArrayHasKey('models', $result);
        $this->assertIsArray($result['models']);
    }

    public function testGetAttributesForUserModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['attributes'],
        ]);

        $this->assertArrayHasKey('attributes', $result);
        $this->assertArrayHasKey('username', $result['attributes']);
        $this->assertArrayHasKey('email', $result['attributes']);

        // Check attribute has expected keys
        $username = $result['attributes']['username'];
        $this->assertArrayHasKey('label', $username);
        $this->assertArrayHasKey('type', $username);
        $this->assertSame('Username', $username['label']);
    }

    public function testAttributeLabelsAndHints(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['attributes'],
        ]);

        $email = $result['attributes']['email'];
        $this->assertSame('Email Address', $email['label']);
        $this->assertSame('Your primary email address', $email['hint']);

        $username = $result['attributes']['username'];
        $this->assertSame('Choose a unique username', $username['hint']);
    }

    public function testGetRelationsForUserModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['relations'],
        ]);

        $this->assertArrayHasKey('relations', $result);
        $this->assertArrayHasKey('posts', $result['relations']);

        $posts = $result['relations']['posts'];
        $this->assertSame('hasMany', $posts['type']);
        $this->assertSame('app\\models\\Post', $posts['model_class']);
        $this->assertSame(['user_id' => 'id'], $posts['link']);
    }

    public function testGetRelationsForPostModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Post',
            'include' => ['relations'],
        ]);

        $this->assertArrayHasKey('user', $result['relations']);
        $this->assertSame('hasOne', $result['relations']['user']['type']);

        $this->assertArrayHasKey('category', $result['relations']);
        $this->assertSame('hasOne', $result['relations']['category']['type']);
    }

    public function testModelWithNoRelations(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Category',
            'include' => ['relations'],
        ]);

        $this->assertArrayHasKey('relations', $result);
        $this->assertEmpty($result['relations']);
    }

    public function testGetBehaviorsForUserModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['behaviors'],
        ]);

        $this->assertArrayHasKey('behaviors', $result);
        $this->assertArrayHasKey('timestamp', $result['behaviors']);
        $this->assertSame(
            'yii\\behaviors\\TimestampBehavior',
            $result['behaviors']['timestamp']['class']
        );
    }

    public function testModelWithNoBehaviors(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Category',
            'include' => ['behaviors'],
        ]);

        $this->assertArrayHasKey('behaviors', $result);
        $this->assertEmpty($result['behaviors']);
    }

    public function testGetScenariosForUserModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['scenarios'],
        ]);

        $this->assertArrayHasKey('scenarios', $result);
        $this->assertArrayHasKey('register', $result['scenarios']);
        $this->assertArrayHasKey('update', $result['scenarios']);

        $register = $result['scenarios']['register'];
        $this->assertContains('username', $register['attributes']);
        $this->assertContains('email', $register['attributes']);
        $this->assertContains('password_hash', $register['attributes']);
    }

    public function testGetFieldsForUserModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['fields'],
        ]);

        $this->assertArrayHasKey('fields', $result);
        $fields = $result['fields']['fields'];
        $this->assertNotContains('password_hash', $fields);
        $this->assertContains('username', $fields);

        $this->assertContains('posts', $result['fields']['extra_fields']);
    }

    public function testIncludeAll(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['all'],
        ]);

        $this->assertArrayHasKey('attributes', $result);
        $this->assertArrayHasKey('relations', $result);
        $this->assertArrayHasKey('behaviors', $result);
        $this->assertArrayHasKey('scenarios', $result);
        $this->assertArrayHasKey('fields', $result);
    }

    public function testModelNotFoundThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->tool->execute(['model' => 'NonExistentModel']);
    }

    public function testShortNameResolution(): void
    {
        $result = $this->tool->execute([
            'model' => 'User',
            'include' => ['attributes'],
        ]);

        $this->assertSame('app\\models\\User', $result['class']);
    }

    public function testPrimaryKeyAndTableName(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['attributes'],
        ]);

        $this->assertSame('user', $result['table']);
        $this->assertSame(['id'], $result['primary_key']);
    }

    public function testClassAlwaysInResult(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Post',
            'include' => ['attributes'],
        ]);

        $this->assertArrayHasKey('class', $result);
        $this->assertArrayHasKey('table', $result);
        $this->assertArrayHasKey('primary_key', $result);
        $this->assertSame('app\\models\\Post', $result['class']);
    }
}
