<?php

$root = dirname(__DIR__);
$disks = $root.'/.phpunit-disks';
$tmp = $disks.'/tmp';
$views = $disks.'/views';

foreach ([$tmp, $views] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        fwrite(STDERR, "Unable to create PHPUnit directory at {$directory}\n");
        exit(1);
    }
}

putenv('TMPDIR='.$tmp);
$_ENV['TMPDIR'] = $tmp;
$_SERVER['TMPDIR'] = $tmp;

putenv('VIEW_COMPILED_PATH='.$views);
$_ENV['VIEW_COMPILED_PATH'] = $views;
$_SERVER['VIEW_COMPILED_PATH'] = $views;

require $root.'/vendor/autoload.php';
