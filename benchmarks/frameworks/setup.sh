#!/bin/bash
# setup.sh: builds set 2's applications on black, in ~/bench/fw (run there, after syncing swerve to
# ~/bench/swerve and each adapter to ~/bench/fw/swerve-<fw>, both without vendor/ and .git/, and
# this directory to ~/bench/fw). Each framework's application is its adapter's test application
# (tests/create-app.sh), production settings as the scaling benchmark (../scaling/setup.sh), plus
# the server integrations, so the code under test is the same on every server.
set -eu
export PATH=~/bench/bin:$PATH COMPOSER_NO_INTERACTION=1
B=~/bench F=~/bench/fw I=~/bench/fw/ini

# PHP settings per server kind, loaded with PHP_INI_SCAN_DIR=:<dir> (srv.sh)
mkdir -p $I/jit $I/rr $I/swoole $I/ext $I/react $I/franken-intl
printf 'opcache.enable=1\nopcache.enable_cli=1\nopcache.validate_timestamps=0\nopcache.jit=1054\nopcache.jit_buffer_size=128M\n' > $I/jit/90-bench.ini
for k in rr swoole ext react franken-intl; do cp $I/jit/90-bench.ini $I/$k/; done
echo "extension=$B/ext/protobuf.so" > $I/rr/80-protobuf.ini
echo "extension=$B/ext/swoole.so" > $I/swoole/80-swoole.ini
echo "extension=$B/phasync.so" > $I/ext/80-phasync.ini
echo "extension=$B/ext/ev.so" > $I/react/80-ev.ini
echo "extension=$F/franken-intl/lib/intl.so" > $I/franken-intl/80-intl.ini

# FrankenPHP: set 1's binary (the Docker image's, run natively with its loader), as a command
R=$B/franken-deb/rootfs
cat > $F/frankenphp <<EOF
#!/bin/sh
# The official Docker image's FrankenPHP (glibc, no ext-parallel), run natively with its own loader.
# FRANKEN_EXTRA_LIB: one more library directory (the image's ICU, for intl.so)
exec $R/lib64/ld-linux-x86-64.so.2 --library-path $R/usr/local/lib:$R/usr/lib/x86_64-linux-gnu:$R/lib/x86_64-linux-gnu\${FRANKEN_EXTRA_LIB:+:\$FRANKEN_EXTRA_LIB} $R/usr/local/bin/frankenphp "\$@"
EOF
chmod +x $F/frankenphp
# CodeIgniter needs ext-intl, which the image lacks: the image's own install-php-extensions
# builds it; intl.so and the image's ICU 76 are copied out (the binary is the same, same sha256)
mkdir -p $F/franken-intl/lib && cd $F/franken-intl
printf 'FROM dunglas/frankenphp:1.12.7-php8.5\nRUN install-php-extensions intl\n' > Dockerfile
docker build -q -t bench-franken-intl .
c=$(docker create bench-franken-intl)
docker cp $c:/usr/local/lib/php/extensions/no-debug-zts-20250925/intl.so lib/
for l in libicuio libicui18n libicuuc libicudata; do docker cp -L $c:/usr/lib/x86_64-linux-gnu/$l.so.76 lib/; done
docker rm $c

# Laravel 13: swerve-laravel's app; Octane (roadrunner, swoole, frankenphp)
cd $F/swerve-laravel && tests/create-app.sh 13
cd tests/Fixtures/app
composer require -W phasync/swerve:0.1.0-alpha20 laravel/octane spiral/roadrunner-cli spiral/roadrunner-http
sed -i 's/^APP_ENV=.*/APP_ENV=production/; s/^APP_DEBUG=.*/APP_DEBUG=false/; s/^LOG_LEVEL=.*/LOG_LEVEL=error/' .env
PHP_INI_SCAN_DIR=:$I/swoole php artisan octane:install --server=swoole -n
ln -sf $B/rr/rr rr
ln -sf $F/frankenphp frankenphp
# Octane's Swoole server as set 1's Swoole: SWOOLE_BASE, no compression, a deep backlog; and a
# second config cache with Octane's default SWOOLE_PROCESS (srv.sh's swoole-process)
php -r '$f = "config/octane.php"; $s = file_get_contents($f); if (!str_contains($s, "max_connection")) { $s = substr($s, 0, strrpos($s, "];")) . "    \x27swoole\x27 => [\n        \x27mode\x27 => (int) env(\x27OCTANE_SWOOLE_MODE\x27, 1),\n        \x27options\x27 => [\n            \x27http_compression\x27 => false,\n            \x27backlog\x27 => 65535,\n            \x27max_connection\x27 => 1000000,\n        ],\n    ],\n\n];\n"; file_put_contents($f, $s); }'
php artisan optimize
OCTANE_SWOOLE_MODE=2 APP_CONFIG_CACHE=bootstrap/cache/config-process.php php artisan config:cache

# Symfony 7.4: swerve-symfony's app; runtime/roadrunner-symfony-nyholm, runtime/swoole (with the
# front controller apps/symfony/swoole.php), and symfony/runtime 7.4's own FrankenPHP worker runner
cd $F/swerve-symfony && tests/create-app.sh 7.4
cd tests/Fixtures/app
composer require -W phasync/swerve:0.1.0-alpha20
composer require -W --ignore-platform-req=ext-swoole runtime/roadrunner-symfony-nyholm runtime/swoole
cp $F/apps/symfony/swoole.php public/swoole.php
rm -rf var/cache && bin/console cache:warmup

# Slim 4: apps/slim, one application file and an entry file per server
rsync -a $F/apps/slim/ $F/slim/ && cd $F/slim && mkdir -p var && composer update

# Spiral 3: swerve-spiral's benchmark app (its benchmarks/setup.sh: production settings); the
# skeleton already runs on RoadRunner through spiral/roadrunner-bridge
cd $F/swerve-spiral && tests/create-app.sh 3
(cd tests/Fixtures/app && composer require -W phasync/swerve:0.1.0-alpha20)
benchmarks/setup.sh

# Yii 3: swerve-yii's app; yiisoft/yii-runner-roadrunner with apps/yii/rr-worker.php
cd $F/swerve-yii && tests/create-app.sh 3
cd tests/Fixtures/app
composer require -W phasync/swerve:0.1.0-alpha20 yiisoft/yii-runner-roadrunner
cp $F/apps/yii/rr-worker.php rr-worker.php

# CodeIgniter 4.7: swerve-codeigniter's app (production: no .env, CI_ENVIRONMENT's default);
# its own FrankenPHP worker mode (public/frankenphp-worker.php from `spark worker:install`)
cd $F/swerve-codeigniter && tests/create-app.sh 4
cd tests/Fixtures/app
composer require -W phasync/swerve:0.1.0-alpha20
php spark worker:install
