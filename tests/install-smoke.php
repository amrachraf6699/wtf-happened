<?php

// Configure only a disposable application explicitly provided by the caller.
$directory = realpath($argv[1] ?? '');
$root = realpath(__DIR__.'/..');
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Smoke application must be inside this package build directory.');
}
$file = $directory.'/composer.json';
$config = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$config['repositories'][] = ['type' => 'path', 'url' => str_replace('\\', '/', $root), 'options' => ['symlink' => false, 'versions' => ['amrachraf6699/wtf-happened' => 'dev-main']]];
file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
