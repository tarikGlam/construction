<?php

namespace App\Services\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class CommissionMoney
{
    public static function amount($value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HALF_UP);
    }

    public static function percentage($base, $rate): string
    {
        $rate = BigDecimal::of((string) $rate);
        if ($rate->isLessThan(0) || $rate->isGreaterThan(100)) {
            throw new InvalidArgumentException('Commission rate must be between 0 and 100.');
        }
        return (string) BigDecimal::of((string) $base)->multipliedBy($rate)
            ->dividedBy(100, 2, RoundingMode::HALF_UP);
    }

    public static function profit($netProfit, $addback): string
    {
        return self::amount(BigDecimal::max(0, BigDecimal::of((string) $netProfit)->plus((string) $addback)));
    }
}
