<?php

namespace App\Services;

use Illuminate\Translation\Translator as BaseTranslator;

class SaleProTranslator extends BaseTranslator
{
    /**
     * Add translation lines to the given locale without splitting keys on dots.
     *
     * In SalePro's database translation architecture, translation items may contain
     * sentence periods (e.g. "Data inserted successfully. Please setup your...").
     * The default Laravel Translator implementation uses Arr::set, which treats
     * periods as array nesting delimiters. That would overwrite scalar translation
     * entries with associative arrays (causing [object Object] toast defects).
     *
     * Storing the item as a literal dictionary key preserves both short and long
     * phrases without array collision.
     *
     * @param  array  $lines
     * @param  string  $locale
     * @param  string  $namespace
     * @return void
     */
    public function addLines(array $lines, $locale, $namespace = '*')
    {
        foreach ($lines as $key => $value) {
            $parts = explode('.', $key, 2);
            if (count($parts) === 2) {
                [$group, $item] = $parts;
            } else {
                $group = '*';
                $item = $key;
            }

            $this->loaded[$namespace][$group][$locale][$item] = $value;
        }
    }
}
