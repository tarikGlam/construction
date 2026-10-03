<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingAmountOverflowException;
use App\Exceptions\CurrencyRateResolutionException;
use App\Exceptions\InvalidAccountingNumericException;
use App\Exceptions\MissingCurrencyMetadataException;

class CurrencyNormalizationService
{
    public const INTERNAL_SCALE = 8;
    public const STORAGE_SCALE = 4;
    public const MAX_MAGNITUDE = '99999999999.9999';

    /**
     * Get the authoritative base currency ID.
     */
    public function getBaseCurrencyId(): int
    {
        $cachedSetting = cache()->get('general_setting');
        $baseId = data_get($cachedSetting, 'currency');
        if ($baseId) {
            return (int) $baseId;
        }

        if (function_exists('gen_setting')) {
            $setting = gen_setting();
            if (isset($setting->currency)) {
                return (int) $setting->currency;
            }
        }

        return 1;
    }

    /**
     * Normalize an amount to the base currency journal storage scale.
     *
     * @param string|int|float $amount
     * @param int|null $currencyId
     * @param string|int|float|null $exchangeRate
     * @return string
     *
     * @throws MissingCurrencyMetadataException
     * @throws CurrencyRateResolutionException
     * @throws InvalidAccountingNumericException
     * @throws AccountingAmountOverflowException
     */
    public function normalize($amount, ?int $currencyId, $exchangeRate): string
    {
        if ($currencyId === null) {
            throw new MissingCurrencyMetadataException("Currency ID is missing on currency-aware accounting source.");
        }

        $cleanAmount = $this->sanitizeAndValidateNumeric($amount);

        $baseCurrencyId = $this->getBaseCurrencyId();

        // Explicit Base Currency: return formatted amount directly without conversion
        if ((int) $currencyId === $baseCurrencyId) {
            return $this->formatStorageAmount($cleanAmount);
        }

        // Explicit Foreign Currency: require valid positive rate
        if ($exchangeRate === null || $exchangeRate === '') {
            throw new CurrencyRateResolutionException("Exchange rate is required for foreign currency ID {$currencyId}.");
        }

        $cleanRate = $this->sanitizeAndValidateNumeric($exchangeRate, 'exchange rate');

        if (bccomp($cleanRate, '0', self::INTERNAL_SCALE) <= 0) {
            throw new CurrencyRateResolutionException("Exchange rate must be strictly greater than zero, got: {$cleanRate}");
        }

        // SalePro convention: base_amount = transaction_amount / exchange_rate
        $converted = bcdiv($cleanAmount, $cleanRate, self::INTERNAL_SCALE);

        return $this->formatStorageAmount($converted);
    }

    /**
     * Explicitly normalize an amount for a base-currency-only workflow (e.g. Deposit, Expense, Income, Payroll).
     *
     * @param string|int|float $amount
     * @return string
     */
    public function normalizeBaseAmount($amount): string
    {
        $cleanAmount = $this->sanitizeAndValidateNumeric($amount);
        return $this->formatStorageAmount($cleanAmount);
    }

    /**
     * Normalize multiple compound lines (such as a Purchase journal) and allocate residual rounding
     * differences to a designated balancing line to guarantee debits == credits exactly.
     *
     * @param array<string, string|int|float> $amounts Associative array of line amounts
     * @param int|null $currencyId
     * @param string|int|float|null $exchangeRate
     * @param array $debitKeys Keys that represent debits
     * @param array $creditKeys Keys that represent credits
     * @param string $balancingKey The key to receive any residual fraction (e.g. Accounts Payable)
     * @return array<string, string>
     */
    public function normalizeCompoundJournal(
        array $amounts,
        ?int $currencyId,
        $exchangeRate,
        array $debitKeys,
        array $creditKeys,
        string $balancingKey
    ): array {
        $normalized = [];
        foreach ($amounts as $key => $amt) {
            $normalized[$key] = $this->normalize($amt, $currencyId, $exchangeRate);
        }

        $totalDebits = '0.0000';
        foreach ($debitKeys as $dKey) {
            if (isset($normalized[$dKey])) {
                $totalDebits = bcadd($totalDebits, $normalized[$dKey], self::STORAGE_SCALE);
            }
        }

        $totalCredits = '0.0000';
        foreach ($creditKeys as $cKey) {
            if (isset($normalized[$cKey])) {
                $totalCredits = bcadd($totalCredits, $normalized[$cKey], self::STORAGE_SCALE);
            }
        }

        $diff = bcsub($totalDebits, $totalCredits, self::STORAGE_SCALE);

        if (bccomp($diff, '0.0000', self::STORAGE_SCALE) !== 0 && isset($normalized[$balancingKey])) {
            if (in_array($balancingKey, $creditKeys, true)) {
                // If balancing credit, adding diff increases credit to match debit
                $normalized[$balancingKey] = bcadd($normalized[$balancingKey], $diff, self::STORAGE_SCALE);
            } elseif (in_array($balancingKey, $debitKeys, true)) {
                // If balancing debit, subtracting diff decreases debit to match credit
                $normalized[$balancingKey] = bcsub($normalized[$balancingKey], $diff, self::STORAGE_SCALE);
            }
        }

        return $normalized;
    }

    /**
     * Format an amount to journal storage scale (4 decimals) using deterministic half-up rounding.
     */
    public function formatStorageAmount(string $amount): string
    {
        $rounded = self::roundHalfUp($amount, self::STORAGE_SCALE);

        // Check overflow
        $abs = ltrim($rounded, '-');
        if (bccomp($abs, self::MAX_MAGNITUDE, self::STORAGE_SCALE) > 0) {
            throw new AccountingAmountOverflowException("Amount {$amount} exceeds maximum capacity " . self::MAX_MAGNITUDE);
        }

        return $rounded;
    }

    /**
     * Deterministic half-up rounding for decimal strings using BCMath.
     * Preserves sign, handles negative values correctly, and outputs exact scale.
     */
    public static function roundHalfUp(string $number, int $scale = 4): string
    {
        $clean = trim($number);
        if ($clean === '' || $clean === '-0') {
            $clean = '0';
        }

        $isNegative = str_starts_with($clean, '-');
        $abs = ltrim($clean, '-');

        if (!str_contains($abs, '.')) {
            $abs .= '.0';
        }

        // Add 5 at position ($scale + 1)
        $half = '0.' . str_repeat('0', $scale) . '5';
        $roundedAbs = bcadd($abs, $half, $scale);

        $parts = explode('.', $roundedAbs);
        $intPart = $parts[0] !== '' ? $parts[0] : '0';
        $decPart = isset($parts[1]) ? str_pad($parts[1], $scale, '0') : str_repeat('0', $scale);

        $formatted = $scale > 0 ? "{$intPart}.{$decPart}" : $intPart;

        if ($isNegative && bccomp($formatted, '0', $scale) !== 0) {
            return "-{$formatted}";
        }

        return $formatted;
    }

    /**
     * Sanitize input and validate numeric string format.
     */
    private function sanitizeAndValidateNumeric($value, string $fieldName = 'amount'): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            throw new InvalidAccountingNumericException("Invalid {$fieldName}: expected numeric or string value.");
        }

        $clean = trim($value);

        // Remove thousand commas if present
        $clean = str_replace(',', '', $clean);

        if (!preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            throw new InvalidAccountingNumericException("Invalid numeric string for {$fieldName}: '{$value}'.");
        }

        return $clean;
    }
}
