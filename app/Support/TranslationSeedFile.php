<?php

namespace App\Support;

use UnexpectedValueException;

final class TranslationSeedFile
{
    /**
     * Load the canonical translation seed row contract.
     *
     * @return array<int, array{key: string, value: string}>
     */
    public static function load(string $file): array
    {
        $rows = require $file;

        if (!is_array($rows)) {
            throw new UnexpectedValueException("Translation seed must return an array: {$file}");
        }

        $validated = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (!is_int($index) || !is_array($row) || !array_key_exists('key', $row) || !array_key_exists('value', $row)
                || !is_string($row['key']) || trim($row['key']) === ''
                || !is_string($row['value']) || trim($row['value']) === '') {
                throw new UnexpectedValueException("Malformed translation seed row {$index}: {$file}");
            }

            // Legacy locale files contain repeated defaults. Normalize them at
            // this single boundary using the first-established value.
            if (isset($seen[$row['key']])) {
                continue;
            }

            $seen[$row['key']] = true;
            $validated[] = ['key' => $row['key'], 'value' => $row['value']];
        }

        return $validated;
    }
}
