# Yii2 AI Boost Guidelines

You are working in a Yii2 application with AI Boost MCP tools available.

## Application Structure (Advanced Template)

This is a Yii2 advanced template with separated applications:
- `frontend/` — Public-facing web app (`frontend\controllers\`, `frontend\models\`, `frontend\views\`)
- `backend/` — Admin panel web app (`backend\controllers\`, `backend\models\`, `backend\views\`)
- `console/` — CLI commands, cron jobs, migrations (`console\controllers\`, `console\migrations\`)
- `common/` — Shared code across all apps (`common\models\`, `common\components\`, `common\mail\`)

### Path Aliases
`@common`, `@frontend`, `@backend`, `@console` — defined in `common/config/bootstrap.php`
`@app` — current application base path, `@runtime`, `@webroot`, `@web`, `@vendor`

### Configuration Merging (later overrides earlier)
1. `common/config/main.php` + `common/config/main-local.php`
2. `{app}/config/main.php` + `{app}/config/main-local.php`

Environment-specific files (`*-local.php`) are gitignored. Use `php init` to generate them.

### Where to Put Code
- **Shared models** (User, AR models used by multiple apps) → `common/models/`
- **App-specific models** (SignupForm, SearchModel) → `frontend/models/` or `backend/models/`
- **Shared components/services** → `common/components/`
- **Migrations** → `console/migrations/`
- **Email templates** → `common/mail/`
- **Shared config** (db, cache, mailer) → `common/config/main.php`
- **Credentials/secrets** → `common/config/main-local.php` (never committed)

## Coding Conventions

### Security (MANDATORY)
- Always use parameter binding: `User::find()->where(['id' => $id])` — NEVER concatenate SQL
- Always encode output: `Html::encode($userInput)` — prevents XSS
- CSRF protection is enabled by default for POST/PUT/DELETE
- Never commit credentials — use `*-local.php` config files

### Active Record
- Always check `save()` return value: `if (!$model->save()) { /* handle errors */ }`
- Use eager loading to avoid N+1: `User::find()->with('profile')->all()`
- Use `safeUp()`/`safeDown()` for transactional migrations
- Use table prefix syntax: `{{%table_name}}`

### Controllers
- Return responses, never echo: `return $this->render('view', [...])`
- Use `load()` + `save()` pattern: `if ($model->load($request->post()) && $model->save())`
- Use AccessControl and VerbFilter behaviors
- Frontend/backend have separate user components with separate sessions/cookies

### General
- Use `Yii::$app->params['key']` for config values — never hardcode
- Use `Yii::t('app', 'message')` for translatable strings
- Log with categories: `Yii::error('msg', 'app\payment')`
- Use `Yii::$app->cache->getOrSet()` for caching

## Available MCP Tools

Use these tools to inspect the application before making changes:

- `application_info` — App structure, environment, modules, components
- `database_schema` — Table schemas, columns, indexes, foreign keys
- `database_query` — Execute read-only SQL queries (SELECT only)
- `model_inspector` — AR model attributes, relations, behaviors, scenarios
- `validation_rules` — Model validation rules and constraints
- `route_inspector` — All registered routes and URL rules
- `component_inspector` — Configured application components
- `config_access` — Application configuration values
- `console_command_inspector` — Available console commands
- `migration_inspector` — Migration status, history, pending
- `widget_inspector` — Widget classes, properties, methods
- `log_inspector` — Recent application log entries
- `performance_profiler` — EXPLAIN plans, index analysis
- `env_inspector` — Environment variables, PHP extensions
- `semantic_search` — Search framework guidelines and Yii2 guide
- `tinker` — Execute PHP code in application context

### Workflow
1. Before modifying code, use `model_inspector` or `database_schema` to understand the current state
2. Use `semantic_search` to find relevant Yii2 patterns and best practices
3. Use `route_inspector` and `component_inspector` to understand the app architecture
4. Use `validation_rules` before changing model logic
