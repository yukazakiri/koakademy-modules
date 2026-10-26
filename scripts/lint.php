<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [];
foreach (['scripts', 'tests'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);
foreach ($files as $file) {
    $process = proc_open([PHP_BINARY, '-l', $file], [1 => STDOUT, 2 => STDERR], $pipes);
    if (! is_resource($process) || proc_close($process) !== 0) {
        exit(1);
    }
}
