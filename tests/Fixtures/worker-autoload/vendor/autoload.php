<?php

// What Composer's vendor/autoload.php does, in short: every package's classes from the generated
// maps, and every package's files once (marked in $GLOBALS['__composer_autoload_files'])
$dir = __DIR__ . '/composer';
$classMap = require "$dir/autoload_classmap.php";
$psr4     = require "$dir/autoload_psr4.php";
spl_autoload_register(static function (string $class) use ($classMap, $psr4) {
    if (isset($classMap[$class])) {
        require $classMap[$class];

        return;
    }
    foreach ($psr4 as $prefix => $dirs) {
        if (str_starts_with($class, $prefix)) {
            foreach ($dirs as $d) {
                if (is_file($file = $d . '/' . strtr(substr($class, strlen($prefix)), '\\', '/') . '.php')) {
                    require $file;

                    return;
                }
            }
        }
    }
});
foreach (require "$dir/autoload_files.php" as $id => $file) {
    if (empty($GLOBALS['__composer_autoload_files'][$id])) {
        $GLOBALS['__composer_autoload_files'][$id] = true;
        require $file;
    }
}
