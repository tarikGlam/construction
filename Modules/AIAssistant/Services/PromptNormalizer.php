<?php

namespace Modules\AIAssistant\Services;

class PromptNormalizer
{
    /**
     * Normalizes a user prompt string in a conservative, Unicode-safe manner.
     *
     * Rules:
     * 1. Converts Eastern Arabic / Persian digits to standard ASCII digits.
     * 2. Removes apostrophes and single/double quotation marks (e.g. "today's" -> "todays", "d'aujourd'hui" -> "daujourdhui").
     * 3. Replaces remaining punctuation and symbols with whitespace, preserving all Unicode letters and numbers.
     * 4. Collapses multiple whitespace characters and trims.
     * 5. Lowercases the string using UTF-8 mb_strtolower.
     */
    public static function normalize(string $prompt): string
    {
        if (trim($prompt) === '') {
            return '';
        }

        // 1. Convert Eastern Arabic (٠-٩) and Persian (۰-۹) numerals to standard 0-9
        $easternArabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $standardDigits      = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $normalized = str_replace($easternArabicDigits, $standardDigits, $prompt);

        // 2. Remove apostrophes, backticks, quotes (so today's -> todays, d'achat -> dachat)
        $normalized = preg_replace('/[\'"`’‘“”«»]/u', '', $normalized);

        // 3. Remove punctuation / symbols conservatively, keeping letters, numbers, and whitespace
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);

        // 4. Collapse whitespace
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        // 5. UTF-8 lowercase and trim
        return mb_strtolower(trim($normalized), 'UTF-8');
    }
}
