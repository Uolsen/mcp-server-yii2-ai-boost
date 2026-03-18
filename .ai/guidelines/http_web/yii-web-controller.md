# Yii2 Web Controller (Advanced Template)

## Controller Placement
- **`frontend/controllers/`** - Public-facing controllers (`frontend\controllers\SiteController`)
- **`backend/controllers/`** - Admin controllers (`backend\controllers\SiteController`)

Each app has its own `controllerNamespace` set in its config.

## Controller Class API
```php
namespace yii\web;

class Controller extends \yii\base\Controller
{
    /** @var bool enable CSRF validation (default: true) */
    public $enableCsrfValidation = true;

    // Rendering
    public function render($view, $params = []);        // With layout
    public function renderPartial($view, $params = []); // Without layout
    public function renderAjax($view, $params = []);    // For AJAX (injects JS/CSS)
    public function renderContent($content);             // Raw string with layout

    // Response
    public function asJson($data);                       // JSON response
    public function asXml($data);                        // XML response

    // Redirects
    public function redirect($url, $statusCode = 302);
    public function goBack($defaultUrl = null);
    public function goHome();
    public function refresh();

    // External actions
    public function actions()
    {
        return [
            'error' => ['class' => 'yii\web\ErrorAction'],
            'captcha' => ['class' => 'yii\captcha\CaptchaAction'],
        ];
    }
}
```

## Frontend Controller Example
```php
namespace frontend\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use common\models\Post;

class PostController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['create', 'update', 'delete'],
                'rules' => [
                    ['allow' => true, 'roles' => ['@']],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['delete' => ['POST']],
            ],
        ];
    }

    public function actionIndex()
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Post::find()->where(['status' => Post::STATUS_PUBLISHED]),
        ]);

        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView($id)
    {
        $model = $this->findModel($id);
        return $this->render('view', ['model' => $model]);
    }

    public function actionCreate()
    {
        $model = new Post();
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('create', ['model' => $model]);
    }

    protected function findModel($id)
    {
        if (($model = Post::findOne($id)) !== null) {
            return $model;
        }
        throw new \yii\web\NotFoundHttpException('Page not found.');
    }
}
```

## Backend Controller Example
```php
namespace backend\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\AccessControl;
use common\models\User;
use backend\models\UserSearch;

class UserController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'roles' => ['admin']],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        $searchModel = new UserSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }
}
```

## RESTful Controllers
```php
namespace frontend\controllers;

use yii\rest\ActiveController;

class ApiPostController extends ActiveController
{
    public $modelClass = 'common\models\Post';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => \yii\filters\auth\HttpBearerAuth::class,
        ];
        return $behaviors;
    }
}
```

## Request Handling
```php
$request = Yii::$app->request;

// GET parameters
$id = $request->get('id');
$page = $request->get('page', 1);

// POST data
$data = $request->post('User');
$raw = $request->getRawBody();

// Request info
$request->isAjax;
$request->isPost;
$request->isPut;
$request->userIP;
$request->url;
$request->absoluteUrl;
```
