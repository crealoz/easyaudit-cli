<?php

namespace Vendor\Module\Helper;

/**
 * Good example: Simple utility class without AbstractHelper
 * Pure utility, no framework dependencies
 */
class PriceUtility
{
    public static function format(float $price): string
    {
        return '$' . number_format($price, 2);
    }

    public static function calculate(float $price, float $taxRate): float
    {
        return $price * (1 + $taxRate);
    }
}
