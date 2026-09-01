<?php

$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/module.json'), true);
if (!is_array($manifest) || empty($manifest['version'])) {
    fwrite(STDERR, "Invalid module.json\n");
    exit(1);
}
$build = $root . '/build';
if (is_dir($build)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($build, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($build);
}
mkdir($build, 0755, true);
$zipPath = $build . '/rondo-integration.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$excluded = ['.git/', 'build/', 'tests/', 'scripts/', '.github/', 'provision/', 'vendor/'];
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $skip = false;
    foreach ($excluded as $prefix) {
        if (strpos($relative, $prefix) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip || in_array($relative, ['composer.lock', 'phpunit.xml', '.phpunit.result.cache', '.gitignore'], true)) {
        continue;
    }
    $files[$relative] = $file->getPathname();
}
ksort($files, SORT_STRING);
foreach ($files as $relative => $path) {
    $name = 'RondoIntegration/' . $relative;
    $zip->addFile($path, $name);
    if (method_exists($zip, 'setMtimeName')) {
        $zip->setMtimeName($name, 0);
    }
}
$zip->close();
copy($root . '/module.json', $build . '/module.json');
$hash = hash_file('sha256', $zipPath);
file_put_contents($build . '/SHA256SUMS', $hash . "  rondo-integration.zip\n");
$sbom = [
    'spdxVersion' => 'SPDX-2.3',
    'dataLicense' => 'CC0-1.0',
    'SPDXID' => 'SPDXRef-DOCUMENT',
    'name' => 'rondo-integration-' . $manifest['version'],
    'documentNamespace' => 'https://github.com/RondoHQ/freescout-rondo-integration/releases/tag/v' . $manifest['version'] . '#spdx',
    'creationInfo' => ['created' => gmdate('Y-m-d\TH:i:s\Z'), 'creators' => ['Organization: RondoHQ']],
    'packages' => [[
        'name' => 'Rondo Integration',
        'SPDXID' => 'SPDXRef-Package',
        'versionInfo' => $manifest['version'],
        'downloadLocation' => 'https://github.com/RondoHQ/freescout-rondo-integration/releases/download/v' . $manifest['version'] . '/rondo-integration.zip',
        'filesAnalyzed' => false,
        'licenseConcluded' => 'AGPL-3.0-only',
        'licenseDeclared' => 'AGPL-3.0-only',
        'checksums' => [['algorithm' => 'SHA256', 'checksumValue' => $hash]],
    ]],
];
file_put_contents($build . '/rondo-integration.spdx.json', json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo $hash . "\n";
