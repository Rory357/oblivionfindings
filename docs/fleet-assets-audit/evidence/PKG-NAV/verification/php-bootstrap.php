<?php
$root = getcwd();
require 'C:/Users/steph/Herd/oblivionfindings/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['App\\' => '/app/', 'Tests\\' => '/tests/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $root.$directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) require $file;
            return;
        }
    }
}, true, true);
