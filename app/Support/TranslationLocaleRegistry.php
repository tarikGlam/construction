<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TranslationLocaleRegistry
{
    private const DISPLAY_NAMES = [
        'en' => 'English',
        'bn' => 'Bangla',
        'ar' => 'Arabic',
        'al' => 'Albanian',
        'az' => 'Azerbaijani',
        'bg' => 'Bulgarian',
        'de' => 'German',
        'es' => 'Spanish',
        'fr' => 'French',
        'id' => 'Indonesian',
        'tr' => 'Turkish',
        'vi' => 'Vietnamese',
        'pt' => 'Portuguese',
        'ms' => 'Malay',
        'sr' => 'Serbian',
        'it' => 'Italian',
        'ru' => 'Russian',
        'sw' => 'Swahili',
    ];

    /**
     * Add missing language rows for every shipped translation file.
     * Existing rows, display names, and default-language choices are preserved.
     */
    public static function ensureDatabaseRows(string $directory, ?string $specificLocale = null): int
    {
        if (!Schema::hasTable('languages')) {
            return 0;
        }

        $inserted = 0;

        foreach (glob(rtrim($directory, '/\\') . '/*.php') ?: [] as $file) {
            $locale = basename($file, '.php');

            if ($specificLocale && $specificLocale !== $locale) {
                continue;
            }

            if (DB::table('languages')->where('language', $locale)->exists()) {
                continue;
            }

            DB::table('languages')->insert([
                'language' => $locale,
                'name' => self::DISPLAY_NAMES[$locale] ?? strtoupper($locale),
                'is_default' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $inserted++;
        }

        return $inserted;
    }
}
