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

    /** @return iterable<string, array{mixed, int, int, int}> */
    public static function decimals(): iterable
    {
        yield 'integer' => [100, 0, 2, 10000];
        // 100.50 and 100.5 are the same float, and json_encode(100.0) is "100",
        // so a float carries only the decimals it actually needs
        yield 'float with trailing zero' => [100.50, 1, 2, 10050];
        yield 'float with one decimal' => [100.5, 1, 2, 10050];
        yield 'whole float' => [100.0, 0, 2, 10000];
        yield 'smallest unit' => [0.01, 2, 2, 1];
        yield 'decimal string' => ['100.00', 2, 2, 10000];
        yield 'integer string' => ['100', 0, 2, 10000];
        yield 'zero' => [0, 0, 2, 0];
        yield 'no exponent' => [100, 0, 0, 100];
        yield 'three decimals' => [1.234, 3, 3, 1234];
        yield 'near the bigint ceiling' => [92233720368547.75, 2, 2, 9223372036854775];
        yield 'long decimal string keeps every digit' => ['0.123456789', 9, 9, 123456789];
    }

    #[DataProvider('notNumbers')]
    public function testNonNumberIsRejected(mixed $value): void
    {
        self::assertNull(DecimalAmount::tryFrom($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function notNumbers(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'words' => ['one hundred'];
        yield 'exponent notation' => ['1e3'];
        yield 'thousands separator' => ['1,000.00'];
        yield 'trailing dot' => ['100.'];
        yield 'leading plus' => ['+100.00'];
        yield 'whitespace' => [' 100.00 '];
        yield 'bool' => [true];
        yield 'array' => [[100]];
        yield 'object' => [new \stdClass()];
        yield 'infinite float' => [\INF];
        yield 'not a number float' => [\NAN];
    }

    #[DataProvider('signs')]
    public function testSignIsReported(mixed $value, bool $positive): void
    {
        $amount = DecimalAmount::tryFrom($value);

        self::assertNotNull($amount);
        self::assertSame($positive, $amount->isPositive());
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function signs(): iterable
    {
        yield 'positive' => [100.00, true];
        yield 'smallest positive' => ['0.000001', true];
        yield 'zero' => [0, false];
        yield 'zero with decimals' => [0.00, false];
        yield 'negative zero' => ['-0.0', false];
        yield 'negative' => [-100.00, false];
    }
}
