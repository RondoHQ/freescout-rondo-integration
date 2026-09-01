<?php

namespace Modules\RondoIntegration\Services;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class UpdateBackupService
{
    public function create($version)
    {
        $root = storage_path('app/rondo-updates/' . gmdate('YmdHis') . '-' . preg_replace('/[^0-9A-Za-z.-]/', '', $version));
        File::makeDirectory($root, 0700, true);
        $modulePath = \Module::getPath() . DIRECTORY_SEPARATOR . 'RondoIntegration';
        if (!File::copyDirectory($modulePath, $root . '/RondoIntegration')) {
            throw new \RuntimeException('module_backup_failed');
        }
        $this->databaseDump($root . '/database.sql', $root);
        return $root;
    }

    public function restore($root)
    {
        $modulePath = \Module::getPath() . DIRECTORY_SEPARATOR . 'RondoIntegration';
        File::deleteDirectory($modulePath);
        if (!File::copyDirectory($root . '/RondoIntegration', $modulePath)) {
            throw new \RuntimeException('module_restore_failed');
        }
        $this->databaseRestore($root . '/database.sql', $root);
    }

    private function databaseDump($destination, $root)
    {
        $database = config('database.connections.' . config('database.default'));
        if (!is_array($database) || ($database['driver'] ?? null) !== 'mysql') {
            throw new \RuntimeException('mysql_backup_required');
        }
        $defaults = $this->defaultsFile($database, $root);
        $command = 'mysqldump --defaults-extra-file=' . escapeshellarg($defaults)
            . ' --single-transaction --skip-lock-tables ' . escapeshellarg($database['database'])
            . ' > ' . escapeshellarg($destination);
        $process = new Process($command);
        $process->setTimeout(null);
        $process->run();
        @unlink($defaults);
        if (!$process->isSuccessful() || !is_file($destination) || filesize($destination) === 0) {
            throw new \RuntimeException('database_backup_failed');
        }
        @chmod($destination, 0600);
    }

    private function databaseRestore($source, $root)
    {
        $database = config('database.connections.' . config('database.default'));
        $defaults = $this->defaultsFile($database, $root);
        $command = 'mysql --defaults-extra-file=' . escapeshellarg($defaults)
            . ' ' . escapeshellarg($database['database']) . ' < ' . escapeshellarg($source);
        $process = new Process($command);
        $process->setTimeout(null);
        $process->run();
        @unlink($defaults);
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('database_restore_failed');
        }
    }

    private function defaultsFile(array $database, $root)
    {
        $path = $root . '/mysql-' . bin2hex(random_bytes(8)) . '.cnf';
        $content = "[client]\n"
            . 'host=' . $this->optionValue($database['host'] ?? '127.0.0.1') . "\n"
            . 'port=' . (int) ($database['port'] ?? 3306) . "\n"
            . 'user=' . $this->optionValue($database['username'] ?? '') . "\n"
            . 'password=' . $this->optionValue($database['password'] ?? '') . "\n";
        file_put_contents($path, $content, LOCK_EX);
        chmod($path, 0600);
        return $path;
    }

    private function optionValue($value)
    {
        $value = (string) $value;
        if (strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
            throw new \RuntimeException('invalid_database_option');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
