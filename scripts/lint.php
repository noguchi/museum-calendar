<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$count = 0;
foreach (['plugins', 'scripts', 'tests'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        if (str_contains($file->getPathname(), '/vendor/')) {
            continue;
        }
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            fwrite(STDERR, implode("\n", $output) . "\n");
            exit(1);
        }
        $output = [];
        $count++;
    }
}
echo "PHP syntax: {$count} files passed.\n";
