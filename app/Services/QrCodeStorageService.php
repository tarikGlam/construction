<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class QrCodeStorageService
{
    private const PRIVATE_DIRECTORY = 'qrcodes';

    private const LEGACY_DIRECTORY = 'images/qrcodes';

    public function storeReplacement(string $content, string $type, int $id, string $extension): string
    {
        abort_unless(in_array($type, ['warehouse', 'table'], true), 400);
        abort_unless($id > 0, 404);
        abort_unless(in_array($extension, ['png', 'svg'], true), 500);

        $path = sprintf(
            '%s/qr_%s_%d_%s.%s',
            self::PRIVATE_DIRECTORY,
            $type,
            $id,
            Str::uuid(),
            $extension
        );

        $disk = Storage::disk('local');
        $written = $disk->put($path, $content);

        if (!$written || !$disk->exists($path) || $disk->size($path) !== strlen($content)) {
            $disk->delete($path);
            throw new RuntimeException('QR image could not be stored safely.');
        }

        return $path;
    }

    public function absolutePath(string $path): string
    {
        $normalized = $this->validatedPath($path);

        if (str_starts_with($normalized, self::PRIVATE_DIRECTORY.'/')) {
            return Storage::disk('local')->path($normalized);
        }

        return public_path($normalized);
    }

    public function exists(string $path): bool
    {
        return is_file($this->absolutePath($path));
    }

    public function delete(?string $path): void
    {
        if (!$path) {
            return;
        }

        $normalized = $this->validatedPath($path);

        if (str_starts_with($normalized, self::PRIVATE_DIRECTORY.'/')) {
            Storage::disk('local')->delete($normalized);

            return;
        }

        File::delete(public_path($normalized));
    }

    public function mimeType(string $path): string
    {
        return str_ends_with(strtolower($this->validatedPath($path)), '.svg')
            ? 'image/svg+xml'
            : 'image/png';
    }

    private function validatedPath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $safeFile = '[A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|svg)';

        abort_unless(
            preg_match('#^(?:'.self::PRIVATE_DIRECTORY.'|'.self::LEGACY_DIRECTORY.")/{$safeFile}$#D", $normalized) === 1,
            404
        );

        return $normalized;
    }
}
