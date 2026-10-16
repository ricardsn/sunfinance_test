<?php

declare(strict_types=1);

namespace App\Helper;

use Symfony\Component\Intl\Currencies;

/**
 * Minor units back to the decimal string the API answers with: 10050 EUR -> "100.50",
 * 100 JPY -> "100". The one place that reads the currency exponent on the way out.
 */
final readonly class MoneyFormatter
{
    public function toDecimal(int $minorUnits, string $currency): string
    {
        return DecimalAmount::fromMinorUnits($minorUnits, Currencies::getFractionDigits($currency))->toDecimal();
    }
}
