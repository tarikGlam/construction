<?php

namespace App\Services;

use App\Exceptions\HealthCheckExecutionException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class HealthCheckProcessRunner
{
    public function __construct(private ?string $phpBinary = null)
    {
    }

    public function run(int $timeoutSeconds): array
    {
        $connection = (string) config('database.default');
        $databaseConfig = (array) config("database.connections.{$connection}", []);
        $database = (string) ($databaseConfig['database'] ?? '');
        $databaseUrl = $databaseConfig['url'] ?? null;
        $artisanPath = base_path('artisan');
        $diagnostics = $this->baseDiagnostics($artisanPath, $database);

        if (!function_exists('proc_open') || $diagnostics['proc_open_disabled']) {
            throw new HealthCheckExecutionException(
                'worker_unavailable',
                'worker_bootstrap',
                'The isolated accounting health worker is unavailable on this server.',
                null,
                $diagnostics + ['internal_reason' => 'proc_open is unavailable or disabled.']
            );
        }

        try {
            $resolvedBinary = $this->resolveCliPhpBinary();
        } catch (Throwable $exception) {
            throw new HealthCheckExecutionException(
                $exception instanceof HealthCheckExecutionException ? $exception->failureCode : 'php_cli_unavailable',
                'worker_bootstrap',
                'A PHP CLI executable could not be located for the accounting health worker.',
                $exception,
                $diagnostics + [
                    'internal_exception_class' => $exception::class,
                    'internal_reason' => $this->sanitizeDiagnostic($exception->getMessage(), $database),
                ]
            );
        }

        $command = [$resolvedBinary, $artisanPath, 'accounting:health-quick', '--json'];
        $diagnostics = array_merge($diagnostics, [
            'resolved_cli_php_binary' => $resolvedBinary,
            'resolved_binary_exists' => is_file($resolvedBinary),
            'resolved_binary_executable' => is_executable($resolvedBinary),
            'attempted_command' => $command,
        ]);
        $platformEnvironment = [];
        if (PHP_OS_FAMILY === 'Windows') {
            // Symfony narrows inherited variables to keys present in $_SERVER.
            // With variables_order=GPCS this can omit SystemRoot and prevent
            // Winsock/PDO from opening the child process's TCP connection.
            foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'PATH', 'PATHEXT'] as $name) {
                $value = getenv($name);
                if (is_string($value) && $value !== '') {
                    $platformEnvironment[$name] = $value;
                }
            }
        }
        $process = null;
        try {
            $process = new Process($command, base_path(), array_merge($platformEnvironment, [
            // On Windows, a CLI child launched from the built-in web server can
            // otherwise reload the normal .env file instead of the web runtime's
            // selected environment. Propagate only the resolved identity needed
            // to keep the bounded read-only worker on the same database.
            'APP_ENV' => app()->environment(),
            'DB_CONNECTION' => $connection,
            'DB_HOST' => (string) ($databaseConfig['host'] ?? ''),
            'DB_PORT' => (string) ($databaseConfig['port'] ?? ''),
            'DB_DATABASE' => $database,
            'DB_USERNAME' => (string) ($databaseConfig['username'] ?? ''),
            'DB_PASSWORD' => (string) ($databaseConfig['password'] ?? ''),
            'DB_SOCKET' => (string) ($databaseConfig['unix_socket'] ?? ''),
            'DATABASE_URL' => is_string($databaseUrl) && $databaseUrl !== '' ? $databaseUrl : false,
            'SALEPRO_HEALTH_EXPECTED_DATABASE' => $database,
            ]), null, $timeoutSeconds);
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            // Symfony terminates the child before surfacing this exception.
            throw new HealthCheckExecutionException(
                'worker_timeout',
                'quick_health_worker',
                'The bounded accounting health check timed out and was cancelled.',
                $exception,
                $this->processDiagnostics($diagnostics, $process, true, $database, $exception)
            );
        } catch (Throwable $exception) {
            throw new HealthCheckExecutionException(
                'worker_unavailable',
                'worker_bootstrap',
                'The isolated accounting health worker could not be started.',
                $exception,
                $this->processDiagnostics($diagnostics, $process, false, $database, $exception)
            );
        }

        if (!$process->isSuccessful()) {
            $message = $this->safeFailureMessage($process);
            $diagnostics = $this->processDiagnostics($diagnostics, $process, false, $database);
            $diagnostics['internal_reason'] = $this->sanitizeDiagnostic($message, $database);
            $workerError = json_decode(trim($process->getOutput()), true);
            if (is_array($workerError)) {
                $diagnostics['worker_database_id'] = $workerError['worker_database_id'] ?? null;
                $diagnostics['configured_database_id'] = $workerError['configured_database_id'] ?? null;
                $diagnostics['worker_error'] = $workerError['worker_error'] ?? null;
            }
            $schemaFailure = str_contains(strtolower($message), 'base table or view not found')
                || str_contains(strtolower($message), "doesn't exist");
            throw new HealthCheckExecutionException($schemaFailure ? 'schema_incompatible' : 'worker_failed',
                $schemaFailure ? 'schema_preflight' : 'quick_health_worker', $message, null, $diagnostics);
        }

        $output = trim($process->getOutput());
        $payload = json_decode($output, true);
        if (!is_array($payload) || !isset($payload['status'], $payload['health'])) {
            $invalidResponseDiagnostics = $this->processDiagnostics($diagnostics, $process, false, $database);
            $invalidResponseDiagnostics['internal_reason'] = 'Worker returned invalid or empty JSON.';

            throw new HealthCheckExecutionException('invalid_worker_response', 'quick_health_worker',
                'The bounded accounting health worker returned an invalid response.',
                null,
                $invalidResponseDiagnostics
            );
        }

        $expectedDatabaseId = $this->databaseIdentifier($database);
        $workerDatabaseId = $payload['worker_database_id'] ?? null;
        if ($expectedDatabaseId === null || !is_string($workerDatabaseId)
            || !hash_equals($expectedDatabaseId, $workerDatabaseId)) {
            $identityDiagnostics = $this->processDiagnostics($diagnostics, $process, false, $database) + [
                'worker_database_id' => is_string($workerDatabaseId) ? $workerDatabaseId : null,
            ];
            $identityDiagnostics['internal_reason'] = 'Worker database identifier did not match the expected identifier.';

            throw new HealthCheckExecutionException(
                'database_identity_mismatch',
                'worker_database_identity',
                'The accounting health worker database identity could not be verified.',
                null,
                $identityDiagnostics
            );
        }

        unset($payload['worker_database_id']);

        return $payload;
    }

    private function resolveCliPhpBinary(): string
    {
        if ($this->phpBinary !== null) {
            $normalized = $this->normalizePhpBinary($this->phpBinary);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $binary = PHP_BINARY;
        $basename = strtolower(pathinfo($binary, PATHINFO_FILENAME));

        if ($basename === 'php') {
            return $binary;
        }
        if ($basename === 'php-cgi') {
            $normalized = $this->normalizePhpBinary($binary);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        // Under Apache mod_php, PHP_BINARY is the Apache executable (httpd),
        // not the PHP CLI. The loaded php.ini is the most reliable pointer to
        // the active Laragon PHP installation without hard-coding its version.
        $executable = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $candidates = [];
        $loadedIni = php_ini_loaded_file();
        if (is_string($loadedIni) && $loadedIni !== '') {
            $candidates[] = dirname($loadedIni).DIRECTORY_SEPARATOR.$executable;
        }
        $candidates[] = PHP_BINDIR.DIRECTORY_SEPARATOR.$executable;

        if (PHP_OS_FAMILY !== 'Windows') {
            $version = PHP_MAJOR_VERSION.PHP_MINOR_VERSION;
            $candidates[] = "/opt/cpanel/ea-php{$version}/root/usr/bin/php";
            foreach (glob('/opt/cpanel/ea-php*/root/usr/bin/php') ?: [] as $candidate) {
                $candidates[] = $candidate;
            }
        }

        $path = getenv('PATH');
        if (is_string($path)) {
            foreach (explode(PATH_SEPARATOR, $path) as $directory) {
                if ($directory !== '') {
                    $candidates[] = rtrim($directory, "\\/").DIRECTORY_SEPARATOR.$executable;
                }
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new HealthCheckExecutionException('php_cli_unavailable', 'worker_bootstrap',
            'A PHP CLI executable could not be located for the accounting health worker.');
    }

    private function normalizePhpBinary(string $binary): ?string
    {
        if (strtolower(pathinfo($binary, PATHINFO_FILENAME)) === 'php-cgi') {
            $candidate = dirname($binary).DIRECTORY_SEPARATOR.'php'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            if (is_file($candidate)) {
                return $candidate;
            }

            return null;
        }

        return $binary;
    }

    private function safeFailureMessage(Process $process): string
    {
        $diagnostic = trim($process->getErrorOutput());
        if ($diagnostic === '') {
            $diagnostic = trim($process->getOutput());
        }

        $database = (string) config('database.connections.'.config('database.default').'.database', '');
        $diagnostic = $this->sanitizeDiagnostic($diagnostic, $database);

        return 'The bounded accounting health worker failed'.($diagnostic !== '' ? ': '.$diagnostic : '.');
    }

    private function baseDiagnostics(string $artisanPath, string $database): array
    {
        $disabledFunctions = (string) ini_get('disable_functions');

        return [
            'php_sapi' => PHP_SAPI,
            'php_binary' => PHP_BINARY,
            'resolved_cli_php_binary' => null,
            'resolved_binary_exists' => null,
            'resolved_binary_executable' => null,
            'artisan_path' => $artisanPath,
            'artisan_path_exists' => is_file($artisanPath),
            'application_base_path' => base_path(),
            'working_directory' => base_path(),
            'proc_open_exists' => function_exists('proc_open'),
            'proc_open_disabled' => $this->isFunctionDisabled('proc_open', $disabledFunctions),
            'attempted_command' => null,
            'process_started' => false,
            'process_exit_code' => null,
            'process_timed_out' => false,
            'stderr_tail' => null,
            'stdout_tail' => null,
            'expected_database_id' => $this->databaseIdentifier($database),
            'worker_database_id' => null,
        ];
    }

    private function processDiagnostics(
        array $diagnostics,
        ?Process $process,
        bool $timedOut,
        string $database,
        ?Throwable $exception = null
    ): array {
        $started = $process?->isStarted() ?? false;
        $diagnostics['process_started'] = $started;
        $diagnostics['process_exit_code'] = $started ? $process->getExitCode() : null;
        $diagnostics['process_timed_out'] = $timedOut;
        $diagnostics['stderr_tail'] = $started
            ? $this->sanitizeDiagnostic($process->getErrorOutput(), $database)
            : null;
        $diagnostics['stdout_tail'] = $started
            ? $this->sanitizeDiagnostic($process->getOutput(), $database)
            : null;
        $diagnostics['internal_reason'] = $exception
            ? $this->sanitizeDiagnostic($exception->getMessage(), $database)
            : null;
        $diagnostics['internal_exception_class'] = $exception ? $exception::class : null;

        return $diagnostics;
    }

    private function sanitizeDiagnostic(string $value, string $database): string
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}", []);
        foreach (['password', 'username', 'url'] as $key) {
            $secret = (string) ($config[$key] ?? '');
            if ($secret !== '') {
                $value = str_replace($secret, '[redacted]', $value);
            }
        }
        if ($database !== '') {
            $value = str_replace($database, '[database:'.$this->databaseIdentifier($database).']', $value);
        }

        $value = preg_replace('/([A-Z_]*PASSWORD|password)\s*[=:]\s*[^\s;]+/i', '$1=[redacted]', $value) ?? '';
        $value = preg_replace('#([a-z][a-z0-9+.-]*://)[^\s/@:]+:[^\s/@]+@#i', '$1[redacted]@', $value) ?? '';
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return mb_substr($value, -1000);
    }

    private function databaseIdentifier(string $database): ?string
    {
        return $database === '' ? null : substr(hash('sha256', $database), 0, 16);
    }

    private function isFunctionDisabled(string $function, ?string $disabledFunctions = null): bool
    {
        $disabled = array_filter(array_map('trim', explode(',', $disabledFunctions ?? (string) ini_get('disable_functions'))));

        return in_array(strtolower($function), array_map('strtolower', $disabled), true);
    }
}
