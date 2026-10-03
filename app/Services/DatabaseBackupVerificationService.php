<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackupVerificationService
{
    public function __construct(private AccountingDatabaseFingerprintService $fingerprints)
    {
    }

    public function verify(string $path, string $expectedSha256, string $targetDatabase): array
    {
        $preflight = $this->inspectSqlFile($path, $expectedSha256, $targetDatabase);
        $baseConnection = DB::getDefaultConnection();
        $base = config("database.connections.{$baseConnection}");
        if (!is_array($base) || ($base['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Backup restore verification requires a MySQL target connection.');
        }
        if (DB::connection()->getDatabaseName() !== $targetDatabase) {
            throw new RuntimeException('Backup target identity does not match the active database.');
        }

        $before = $this->fingerprints->capture();
        $temporaryDatabase = config('accounting.restore_verification_database_prefix', 'salepro_restore_verify_')
            .strtolower(str_replace('-', '', Str::uuid()->toString()));
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $temporaryDatabase)) {
            throw new RuntimeException('Generated restore-verification database name is unsafe.');
        }

        $connection = 'accounting_restore_verification';
        $quoted = '`'.$temporaryDatabase.'`';
        DB::statement("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            $this->restore($path, $temporaryDatabase, $base);
            $tempConfig = $base;
            $tempConfig['database'] = $temporaryDatabase;
            config(["database.connections.{$connection}" => $tempConfig]);
            DB::purge($connection);
            $restored = $this->fingerprints->capture($connection);

            $this->assertMatchingFingerprint($before, $restored);

            return [
                'verified' => true,
                'verified_at' => now()->toIso8601String(),
                'path' => realpath($path),
                'size' => $preflight['size'],
                'sha256' => $preflight['sha256'],
                'declared_database' => $preflight['declared_database'],
                'target_database' => $targetDatabase,
                'restore_database' => $temporaryDatabase,
                'target_fingerprint' => $before,
                'restored_fingerprint' => $restored,
            ];
        } finally {
            DB::purge($connection);
            DB::statement("DROP DATABASE IF EXISTS {$quoted}");
        }
    }

    public function inspectSqlFile(string $path, string $expectedSha256, string $targetDatabase): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('A readable SQL backup file is required.');
        }
        $size = filesize($path);
        if ($size === false || $size < 256) {
            throw new RuntimeException('The SQL backup is empty or too small to be a restorable database dump.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $expectedSha256)) {
            throw new RuntimeException('An explicit expected backup SHA-256 is required.');
        }
        $actualHash = hash_file('sha256', $path);
        if (!is_string($actualHash) || !hash_equals(strtolower($expectedSha256), strtolower($actualHash))) {
            throw new RuntimeException('Backup SHA-256 does not match the approved hash.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The SQL backup could not be opened.');
        }
        $sample = fread($handle, min($size, 16 * 1024 * 1024));
        fclose($handle);
        if (!is_string($sample)
            || !str_contains($sample, '-- MySQL dump')
            || !str_contains($sample, 'CREATE TABLE `journal_entries`')
            || !str_contains($sample, 'CREATE TABLE `journal_lines`')
            || !str_contains($sample, 'CREATE TABLE `migrations`')) {
            throw new RuntimeException('Backup is missing required MySQL and accounting schema markers.');
        }
        if (preg_match('/^\s*(?:CREATE\s+DATABASE|USE\s+`?)/mi', $sample)) {
            throw new RuntimeException('Backup must not contain database-switching statements.');
        }
        if (!preg_match('/^-- Host:.*?Database:\s*([^\s]+)\s*$/mi', $sample, $matches)) {
            throw new RuntimeException('Backup does not declare its source database identity.');
        }
        $declared = trim($matches[1], "` \t\r\n");
        if (!hash_equals($targetDatabase, $declared)) {
            throw new RuntimeException('Backup source database does not match the intended target database.');
        }

        return compact('size') + ['sha256' => strtolower($actualHash), 'declared_database' => $declared];
    }

    public function assertMatchingFingerprint(array $target, array $restored): void
    {
        if (!isset($target['sha256'], $restored['sha256'])
            || !hash_equals((string) $target['sha256'], (string) $restored['sha256'])) {
            throw new RuntimeException('Restored backup accounting fingerprint does not match the intended target database.');
        }
    }

    private function restore(string $path, string $database, array $connection): void
    {
        $binary = $this->mysqlBinary();
        $arguments = [
            $binary,
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? '3306'),
            '--user='.(string) ($connection['username'] ?? ''),
            '--database='.$database,
            '--binary-mode=1',
        ];
        $environment = [];
        if ((string) ($connection['password'] ?? '') !== '') {
            $environment['MYSQL_PWD'] = (string) $connection['password'];
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Backup could not be opened for restore verification.');
        }
        try {
            $process = new Process($arguments, base_path(), $environment, $stream, 300);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new RuntimeException('Backup failed to restore into the isolated verification database.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function mysqlBinary(): string
    {
        $configured = config('accounting.mysql_binary');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $matches = glob('C:/laragon/bin/mysql/*/bin/mysql.exe') ?: [];
            rsort($matches, SORT_NATURAL);
            if ($matches) {
                return $matches[0];
            }
        }

        return PHP_OS_FAMILY === 'Windows' ? 'mysql.exe' : 'mysql';
    }
}
