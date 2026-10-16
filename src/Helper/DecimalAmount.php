<?php

declare(strict_types=1);

namespace App\Helper;

/**
 * A money amount kept as a decimal string - as it arrived in the request, and on the
 * way back out of the read endpoints.
 * No step here uses float arithmetic: the digits are shifted as text, so the integer
 * that reaches the database is exactly the number that was sent.
 */
final class DecimalAmount
{
    /** A plain decimal number - no exponents, no thousands separators. */
    private const DECIMAL = '/^-?\d+(\.\d+)?$/';

    private function __construct(private readonly string $decimal)
    {
    }

    /**
     * Reads an int, a float or a decimal string, and returns null if the value is not a
     * decimal number. Floats go through json_encode, which prints the shortest string
     * that reads back as the same float, so no digits are invented.
     */
    public static function tryFrom(mixed $value): ?self
    {
        $decimal = match (true) {
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => json_encode($value),
            is_string($value) => $value,
            default => null,
        };

        return is_string($decimal) && 1 === preg_match(self::DECIMAL, $decimal)
            ? new self($decimal)
            : null;
    }

    /** The reverse of toMinorUnits(): 10050 with exponent 2 -> "100.50". */
    public static function fromMinorUnits(int $minorUnits, int $exponent): self
    {
        $sign = $minorUnits < 0 ? '-' : '';
        // one digit more than the exponent, so 5 cents reads as "0.05" and not ".05"
        $digits = str_pad((string) abs($minorUnits), $exponent + 1, '0', \STR_PAD_LEFT);

        return new self(0 === $exponent
            ? $sign.$digits
            : $sign.substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent));
    }

    public function toDecimal(): string
    {
        return $this->decimal;
    }

    public function isPositive(): bool
    {
        // the scale has to be passed or bcmath would read 0.01 as zero
        return bccomp($this->decimal, '0', $this->decimals()) > 0;
    }

    public function decimals(): int
    {
        return strlen($this->fraction());
    }

    /**
     * Shifts the digits by the currency exponent: 100.5 with exponent 2 -> 10050.
     * The caller has checked that decimals() fits the exponent, otherwise the extra
     * digits would be dropped.
     */
    public function toMinorUnits(int $exponent): int
    {
        $whole = explode('.', $this->decimal)[0];

        return (int) ($whole.str_pad($this->fraction(), $exponent, '0'));
    }

    private function fraction(): string
    {
        return explode('.', $this->decimal)[1] ?? '';
    }
}
