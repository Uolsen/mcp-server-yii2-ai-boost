<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Tools;

use codechap\yii2boost\Mcp\Tools\ValidationRulesTool;

class ValidationRulesToolTest extends ToolTestCase
{
    private ValidationRulesTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new ValidationRulesTool([
            'basePath' => __DIR__ . '/../../fixtures/app',
        ]);
    }

    public function testGetName(): void
    {
        $this->assertSame('validation_rules', $this->tool->getName());
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
        $this->assertArrayHasKey('scenario', $schema['properties']);
        $this->assertArrayHasKey('include', $schema['properties']);
    }

    public function testEmptyModelListsModels(): void
    {
        $result = $this->tool->execute([]);
        $this->assertArrayHasKey('models', $result);
        $this->assertIsArray($result['models']);
    }

    public function testGetRulesForPostModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Post',
            'include' => ['rules'],
        ]);

        $this->assertArrayHasKey('rules', $result);
        $rules = $result['rules'];
        $this->assertNotEmpty($rules);

        // Find the required rule
        $requiredRule = null;
        foreach ($rules as $rule) {
            if ($rule['validator'] === 'required') {
                $requiredRule = $rule;
                break;
            }
        }
        $this->assertNotNull($requiredRule);
        $this->assertContains('title', $requiredRule['attributes']);
        $this->assertContains('body', $requiredRule['attributes']);
        $this->assertContains('user_id', $requiredRule['attributes']);
    }

    public function testGetRulesForUserModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['rules'],
        ]);

        $rules = $result['rules'];
        $validatorTypes = array_column($rules, 'validator');
        $this->assertContains('required', $validatorTypes);
        $this->assertContains('string', $validatorTypes);
        $this->assertContains('email', $validatorTypes);
        $this->assertContains('unique', $validatorTypes);
        $this->assertContains('integer', $validatorTypes);
        $this->assertContains('in', $validatorTypes);
    }

    public function testRuleParamsExtracted(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Post',
            'include' => ['rules'],
        ]);

        // Find the string rule for title with min/max
        $stringRule = null;
        foreach ($result['rules'] as $rule) {
            if (
                $rule['validator'] === 'string'
                && in_array('title', $rule['attributes'])
                && isset($rule['params'])
            ) {
                $stringRule = $rule;
                break;
            }
        }

        $this->assertNotNull($stringRule, 'String rule for title with params should exist');
        $this->assertSame(3, $stringRule['params']['min']);
        $this->assertSame(255, $stringRule['params']['max']);
    }

    public function testCustomMessageExtracted(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Post',
            'include' => ['rules'],
        ]);

        // Find the match rule with custom message
        $matchRule = null;
        foreach ($result['rules'] as $rule) {
            if ($rule['validator'] === 'match' && isset($rule['message'])) {
                $matchRule = $rule;
                break;
            }
        }

        $this->assertNotNull($matchRule, 'Match rule with custom message should exist');
        $this->assertStringContainsString('letters, numbers', $matchRule['message']);
    }

    public function testBuiltinVsCustomClassification(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Post',
            'include' => ['rules'],
        ]);

        foreach ($result['rules'] as $rule) {
            // All Post rules use built-in validators
            $this->assertTrue($rule['builtin'], "Validator '{$rule['validator']}' should be built-in");
        }
    }

    public function testConstraintSummaryForPostModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Post',
            'include' => ['constraints'],
        ]);

        $this->assertArrayHasKey('constraints', $result);
        $constraints = $result['constraints'];

        // Check required constraints exist
        $this->assertArrayHasKey('required', $constraints);
        $requiredAttrs = array_column($constraints['required'], 'attribute');
        $this->assertContains('title', $requiredAttrs);
        $this->assertContains('body', $requiredAttrs);

        // Check in constraints exist
        $this->assertArrayHasKey('in', $constraints);
    }

    public function testConstraintSummaryForUserModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['constraints'],
        ]);

        $constraints = $result['constraints'];

        // unique constraint
        $this->assertArrayHasKey('unique', $constraints);
        $uniqueAttrs = array_column($constraints['unique'], 'attribute');
        $this->assertContains('username', $uniqueAttrs);

        // email constraint
        $this->assertArrayHasKey('email', $constraints);
        $emailAttrs = array_column($constraints['email'], 'attribute');
        $this->assertContains('email', $emailAttrs);
    }

    public function testMessagesForPostModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Post',
            'include' => ['messages'],
        ]);

        $this->assertArrayHasKey('messages', $result);
        $this->assertArrayHasKey('title', $result['messages']);

        // title should have multiple validators
        $this->assertGreaterThan(1, count($result['messages']['title']));
    }

    public function testSafeAttributesForUserModel(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['safe_attributes'],
        ]);

        $this->assertArrayHasKey('safe_attributes', $result);
        $safeAttrs = $result['safe_attributes'];

        $this->assertArrayHasKey('register', $safeAttrs);
        $this->assertContains('username', $safeAttrs['register']);
        $this->assertContains('email', $safeAttrs['register']);
        $this->assertContains('password_hash', $safeAttrs['register']);

        $this->assertArrayHasKey('update', $safeAttrs);
        $this->assertContains('status', $safeAttrs['update']);
    }

    public function testSafeAttributesForSpecificScenario(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'scenario' => 'register',
            'include' => ['safe_attributes'],
        ]);

        $this->assertArrayHasKey('register', $result['safe_attributes']);
        $this->assertArrayNotHasKey('update', $result['safe_attributes']);
    }

    public function testIncludeAll(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'include' => ['all'],
        ]);

        $this->assertArrayHasKey('rules', $result);
        $this->assertArrayHasKey('messages', $result);
        $this->assertArrayHasKey('constraints', $result);
        $this->assertArrayHasKey('safe_attributes', $result);
    }

    public function testModelNotFoundThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->tool->execute(['model' => 'NonExistentModel']);
    }

    public function testMinimalModelRules(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Category',
            'include' => ['rules', 'constraints'],
        ]);

        // Category has 3 simple rules
        $this->assertNotEmpty($result['rules']);

        $validatorTypes = array_column($result['rules'], 'validator');
        $this->assertContains('required', $validatorTypes);
        $this->assertContains('string', $validatorTypes);
    }

    public function testClassAndTableInResult(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Post',
            'include' => ['rules'],
        ]);

        $this->assertSame('app\\models\\Post', $result['class']);
        $this->assertSame('post', $result['table']);
    }

    public function testScenarioIncludedInResult(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\User',
            'scenario' => 'register',
            'include' => ['rules'],
        ]);

        $this->assertSame('register', $result['scenario']);
    }

    public function testExistValidatorDetails(): void
    {
        $result = $this->tool->execute([
            'model' => 'app\\models\\Post',
            'include' => ['constraints'],
        ]);

        $this->assertArrayHasKey('exist', $result['constraints']);
        $existConstraint = $result['constraints']['exist'][0];
        $this->assertSame('user_id', $existConstraint['attribute']);
        $this->assertSame('app\\models\\User', $existConstraint['target_class']);
    }
}
