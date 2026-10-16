<?php

declare(strict_types=1);

namespace App\Tests\Unit\Helper;

use App\Helper\DecimalAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalAmountTest extends TestCase
{
    #[DataProvider('decimals')]
    public function testValueIsRead(mixed $value, int $decimals, int $exponent, int $minorUnits): void
    {
        $amount = DecimalAmount::tryFrom($value);

        self::assertNotNull($amount);
        self::assertSame($decimals, $amount->decimals());
        self::assertSame($minorUnits, $amount->toMinorUnits($exponent));
    }

    /** @return array<string, array{mixed, int, int, int}> */
    public static function decimals(): array
    {
        return [
            'integer' => [100, 0, 2, 10000],
            // 100.50 and 100.5 are the same float, and json_encode(100.0) is "100",
            // so a float carries only the decimals it actually needs
            'float with trailing zero' => [100.50, 1, 2, 10050],
            'float with one decimal' => [100.5, 1, 2, 10050],
            'whole float' => [100.0, 0, 2, 10000],
            'smallest unit' => [0.01, 2, 2, 1],
            'decimal string' => ['100.00', 2, 2, 10000],
            'integer string' => ['100', 0, 2, 10000],
            'zero' => [0, 0, 2, 0],
            'no exponent' => [100, 0, 0, 100],
            'three decimals' => [1.234, 3, 3, 1234],
            'near the bigint ceiling' => [92233720368547.75, 2, 2, 9223372036854775],
            'long decimal string keeps every digit' => ['0.123456789', 9, 9, 123456789],
        ];
    }

    #[DataProvider('minorUnits')]
    public function testMinorUnitsAreReadBack(int $minorUnits, int $exponent, string $decimal): void
    {
        self::assertSame($decimal, DecimalAmount::fromMinorUnits($minorUnits, $exponent)->toDecimal());
    }

    /** @return array<string, array{int, int, string}> */
    public static function minorUnits(): array
    {
        return [
            'two decimals' => [10050, 2, '100.50'],
            'whole amount' => [10000, 2, '100.00'],
            'smallest unit' => [1, 2, '0.01'],
            'under one unit' => [50, 2, '0.50'],
            'zero' => [0, 2, '0.00'],
            'negative' => [-10050, 2, '-100.50'],
            'negative under one unit' => [-1, 2, '-0.01'],
            'currency without decimals' => [100, 0, '100'],
            'zero without decimals' => [0, 0, '0'],
            'three decimals' => [1234, 3, '1.234'],
            'near the bigint ceiling' => [9223372036854775, 2, '92233720368547.75'],
        ];
    }

    #[DataProvider('decimals')]
    public function testTheConversionIsReversible(mixed $value, int $decimals, int $exponent, int $minorUnits): void
    {
        $roundTripped = DecimalAmount::fromMinorUnits($minorUnits, $exponent)->toMinorUnits($exponent);

        self::assertSame($minorUnits, $roundTripped);
    }

    #[DataProvider('notNumbers')]
    public function testNonNumberIsRejected(mixed $value): void
    {
        self::assertNull(DecimalAmount::tryFrom($value));
    }

    /** @return array<string, array{mixed}> */
    public static function notNumbers(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'words' => ['one hundred'],
            'exponent notation' => ['1e3'],
            'thousands separator' => ['1,000.00'],
            'trailing dot' => ['100.'],
            'leading plus' => ['+100.00'],
            'whitespace' => [' 100.00 '],
            'bool' => [true],
            'array' => [[100]],
            'object' => [new \stdClass()],
            'infinite float' => [\INF],
            'not a number float' => [\NAN],
        ];
    }

    #[DataProvider('signs')]
    public function testSignIsReported(mixed $value, bool $positive): void
    {
        $amount = DecimalAmount::tryFrom($value);

        self::assertNotNull($amount);
        self::assertSame($positive, $amount->isPositive());
    }

    /** @return array<string, array{mixed, bool}> */
    public static function signs(): array
    {
        return [
            'positive' => [100.00, true],
            'smallest positive' => ['0.000001', true],
            'zero' => [0, false],
            'zero with decimals' => [0.00, false],
            'negative zero' => ['-0.0', false],
            'negative' => [-100.00, false],
        ];
    }
}
