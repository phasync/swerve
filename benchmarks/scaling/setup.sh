#!/bin/bash
# Builds the benchmarked applications on the server (black), in ~/bench/<adapter>, each as its
# adapter's own benchmarks/run.sh prepares it for production. Idempotent enough to re-run.
#
#   ssh black 'export PATH=~/bench/bin:$PATH; bash ~/bench/scaling/setup.sh [adapter...]'
set -eu
export PATH=~/bench/bin:$PATH COMPOSER_NO_INTERACTION=1
b=~/bench
apps=${*:-plain laravel symfony yii cakephp spiral codeigniter laminas}

for a in $apps; do
    echo "=== $a"
    case $a in
    plain)
        # The FPM twin of swerve's tests/Fixtures/app.php routes /hello and /usleep
        mkdir -p $b/scaling/plain
        cat > $b/scaling/plain/index.php <<'PHP'
<?php
switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/hello':
        header('Content-Type: text/plain');
        echo 'Hello';
        break;
    case '/usleep':
        $start = microtime(true);
        usleep((int) $_GET['ms'] * 1000);
        echo sprintf('%.2f', microtime(true) - $start);
        break;
    default:
        http_response_code(404);
}
PHP
        ;;
    laravel)
        cd $b/swerve-laravel && tests/create-app.sh 13
        cd tests/Fixtures/app
        sed -i 's/^APP_ENV=.*/APP_ENV=production/; s/^APP_DEBUG=.*/APP_DEBUG=false/; s/^LOG_LEVEL=.*/LOG_LEVEL=error/' .env
        php artisan optimize
        ;;
    symfony)
        cd $b/swerve-symfony && tests/create-app.sh 7.4
        ;;
    yii)
        cd $b/swerve-yii && tests/create-app.sh 3
        ;;
    cakephp)
        cd $b/swerve-cakephp && tests/create-app.sh 5.4
        cd tests/Fixtures/app
        rm -rf tmp/cache/*/*
        sed -i "s/env('DEBUG', true)/env('DEBUG', false)/" config/app_local.php
        sed -i "s|env('APP_FULL_BASE_URL', false)|'http://192.168.10.15'|" config/app.php
        ;;
    spiral)
        cd $b/swerve-spiral && tests/create-app.sh 3 && benchmarks/setup.sh
        ;;
    codeigniter)
        cd $b/swerve-codeigniter && tests/create-app.sh 4
        ;;
    laminas)
        # laminas-mvc declares PHP up to 8.4; the adapter benchmarks it on 8.5 too
        cd $b/swerve-laminas && COMPOSER_IGNORE_PLATFORM_REQ=php tests/create-app.sh 3.8
        cd tests/Fixtures/app
        # As benchmarks/run.sh: the skeleton's own session setup, the config cache, an optimized autoloader
        sed -i '/function onBootstrap/,/^    }/d' module/SwerveTest/src/Module.php
        rm -f data/cache/*.php
        composer dump-autoload -q --optimize
        ;;
    esac
done
