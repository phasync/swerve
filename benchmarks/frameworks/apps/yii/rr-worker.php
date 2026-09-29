<?php
// Yii 3 on RoadRunner: yiisoft/yii-runner-roadrunner, booted as public/index.php boots the app.

declare(strict_types=1);

use App\Environment;
use Yiisoft\Yii\Runner\RoadRunner\RoadRunnerHttpApplicationRunner;

require __DIR__ . '/src/bootstrap.php';

(new RoadRunnerHttpApplicationRunner(
    rootPath: __DIR__,
    debug: Environment::appDebug(),
    checkEvents: Environment::appDebug(),
    environment: Environment::appEnv(),
))->run();
