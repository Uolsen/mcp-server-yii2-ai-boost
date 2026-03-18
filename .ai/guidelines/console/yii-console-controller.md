# Yii2 Console Controller (Advanced Template)

## Controller Placement
Console controllers live in `console/controllers/` with namespace `console\controllers`.
Run via: `php yii <controller-id>/<action-id>`

## Controller Class API
```php
namespace yii\console;

class Controller extends \yii\base\Controller
{
    /** @var bool run interactively */
    public $interactive = true;

    /** @var bool|null enable ANSI colors */
    public $color;

    /** @var string default action ID */
    public $defaultAction = 'index';

    // Define available CLI options (public properties)
    public function options($actionID)
    {
        return ['color', 'interactive', 'help'];
    }

    // Define short aliases: ['v' => 'verbose']
    public function optionAliases()
    {
        return [];
    }

    // Output methods
    public function stdout($string);           // Print to STDOUT
    public function stderr($string);           // Print to STDERR

    // Input methods
    public function prompt($text, $options = []);       // Get text input
    public function confirm($message, $default = false); // Yes/no confirmation
    public function select($message, $options = []);    // Choose from options

    // Exit codes
    const EXIT_CODE_NORMAL = 0;
    const EXIT_CODE_ERROR = 1;
}
```

## Usage Example
```php
namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use common\models\User;

class UserController extends Controller
{
    public $verbose = false;
    public $limit = 100;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['verbose', 'limit']);
    }

    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'v' => 'verbose',
            'l' => 'limit',
        ]);
    }

    /**
     * Deactivates users who haven't logged in for a year.
     */
    public function actionCleanup()
    {
        $expiry = time() - 365 * 24 * 3600;
        $users = User::find()
            ->where(['status' => User::STATUS_ACTIVE])
            ->andWhere(['<', 'last_login_at', $expiry])
            ->limit($this->limit)
            ->all();

        $count = 0;
        foreach ($users as $user) {
            $user->status = User::STATUS_INACTIVE;
            if ($user->save(false)) {
                $count++;
                if ($this->verbose) {
                    $this->stdout("Deactivated: {$user->username}\n");
                }
            }
        }

        $this->stdout("Done. Deactivated {$count} users.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Creates a new admin user.
     * @param string $username
     * @param string $email
     */
    public function actionCreateAdmin($username, $email)
    {
        $password = $this->prompt('Enter password:', [
            'required' => true,
            'validator' => function ($input) {
                return strlen($input) >= 8 ? '' : 'Password must be at least 8 characters.';
            },
        ]);

        $user = new User();
        $user->username = $username;
        $user->email = $email;
        $user->setPassword($password);
        $user->generateAuthKey();
        $user->status = User::STATUS_ACTIVE;

        if (!$user->save()) {
            $this->stderr("Error: " . implode(', ', $user->getFirstErrors()) . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("User '{$username}' created successfully.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
```

Run:
```bash
php yii user/cleanup --verbose --limit=50
php yii user/cleanup -v -l 50
php yii user/create-admin john john@example.com
```

## Console Helper Colors
```php
use yii\helpers\Console;

$this->stdout("Success\n", Console::FG_GREEN);
$this->stdout("Warning\n", Console::FG_YELLOW);
$this->stderr("Error\n", Console::FG_RED);
$this->stdout("Bold\n", Console::BOLD);

// Progress bar
Console::startProgress(0, $total);
Console::updateProgress($i, $total);
Console::endProgress();
```

## beforeAction / afterAction Hooks
```php
public function beforeAction($action)
{
    if (!parent::beforeAction($action)) {
        return false;
    }
    $this->stdout("Starting {$action->id}...\n");
    return true;
}
```

## Console Configuration
```php
// console/config/main.php
return [
    'id' => 'app-console',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'console\controllers',
    'bootstrap' => ['log'],
    'controllerMap' => [
        'migrate' => [
            'class' => 'yii\console\controllers\MigrateController',
        ],
    ],
];
```
