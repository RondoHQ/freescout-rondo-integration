<?php

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$errors = 0;
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (!$file->isFile() || $file->getExtension() !== 'php' || strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
        continue;
    }
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1';
    exec($command, $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, implode("\n", $output) . "\n");
        $errors++;
    }
    $output = [];
}
foreach (['module.json', 'composer.json'] as $json) {
    if (!is_array(json_decode(file_get_contents($root . '/' . $json), true))) {
        fwrite(STDERR, "Invalid JSON: {$json}\n");
        $errors++;
    }
}
exit($errors ? 1 : 0);

