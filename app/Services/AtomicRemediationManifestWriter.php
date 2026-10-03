<?php

namespace App\Services;

use RuntimeException;

class AtomicRemediationManifestWriter
{
    public function write(string $relativePath, array $payload): array
    {
        $staged = $this->stage($relativePath, $payload);
        $this->finalize($staged['temp_path'], $staged['path'], $staged['sha256']);

        return $staged;
    }

    public function stage(string $relativePath, array $payload): array
    {
        $relativePath = $this->safeRelativePath($relativePath);
        $path = storage_path('app'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Remediation manifest directory could not be created.');
        }

        $json = $this->serialize($payload);
        $staged = $this->stageSerialized($path, $json, hash('sha256', $json));

        return ['relative_path' => $relativePath] + $staged;
    }

    public function serialize(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            throw new RuntimeException('Remediation manifest could not be encoded.');
        }

        return $json;
    }

    public function stageSerialized(string $path, string $json, string $expectedSha256): array
    {
        $path = $this->safeAbsolutePath($path);
        if (!hash_equals($expectedSha256, hash('sha256', $json))) {
            throw new RuntimeException('Durable remediation manifest payload hash does not match the audit record.');
        }

        $directory = dirname($path);
        $temp = tempnam($directory, '.salepro-manifest-');
        if ($temp === false || file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) {
            if (is_string($temp) && is_file($temp)) {
                @unlink($temp);
            }
            throw new RuntimeException('Remediation manifest could not be staged durably.');
        }

        return [
            'path' => $path,
            'temp_path' => $temp,
            'sha256' => $expectedSha256,
            'payload_json' => $json,
        ];
    }

    public function finalize(string $tempPath, string $path, string $expectedSha256): void
    {
        $tempPath = $this->safeAbsolutePath($tempPath);
        $path = $this->safeAbsolutePath($path);
        if (!$this->isValid($tempPath, $expectedSha256)) {
            throw new RuntimeException('Staged remediation manifest is missing or has changed.');
        }

        if ($this->isValid($path, $expectedSha256)) {
            if ($tempPath !== $path) {
                @unlink($tempPath);
            }
            return;
        }

        if (file_exists($path) && !is_file($path)) {
            throw new RuntimeException('Remediation was committed but the manifest target is not a regular file; recovery is required before another run.');
        }

        $invalidPath = null;
        if (is_file($path)) {
            $invalidPath = $path.'.invalid-'.bin2hex(random_bytes(8));
            if (!@rename($path, $invalidPath)) {
                throw new RuntimeException('Existing invalid remediation manifest could not be quarantined safely.');
            }
        }

        if (!@rename($tempPath, $path)) {
            if ($invalidPath !== null && !file_exists($path)) {
                @rename($invalidPath, $path);
            }
            throw new RuntimeException('Remediation was committed but external manifest finalization failed; recovery is required before another run.');
        }

        if (!$this->isValid($path, $expectedSha256)) {
            if ($invalidPath !== null) {
                @unlink($path);
                @rename($invalidPath, $path);
            }
            throw new RuntimeException('Final remediation manifest failed durable hash validation.');
        }

        if ($invalidPath !== null) {
            @unlink($invalidPath);
        }
    }

    public function isValid(?string $path, string $expectedSha256): bool
    {
        if (!is_string($path) || $path === '') {
            return false;
        }
        try {
            $path = $this->safeAbsolutePath($path);
        } catch (RuntimeException) {
            return false;
        }

        return is_file($path) && hash_equals($expectedSha256, (string) hash_file('sha256', $path));
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/(^|\/)\.\.($|\/)/', $path)) {
            throw new RuntimeException('Manifest path must be a safe relative storage/app path.');
        }

        return $path;
    }

    private function safeAbsolutePath(string $path): string
    {
        $root = realpath(storage_path('app'));
        $directory = realpath(dirname($path));
        if ($root === false || $directory === false) {
            throw new RuntimeException('Remediation manifest directory is unavailable.');
        }

        $rootPrefix = rtrim($root, '\\/').DIRECTORY_SEPARATOR;
        $directoryPrefix = rtrim($directory, '\\/').DIRECTORY_SEPARATOR;
        if (strncasecmp($directoryPrefix, $rootPrefix, strlen($rootPrefix)) !== 0) {
            throw new RuntimeException('Remediation manifest path is outside managed storage.');
        }

        return rtrim($directory, '\\/').DIRECTORY_SEPARATOR.basename($path);
    }
}
