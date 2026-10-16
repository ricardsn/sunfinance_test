<?php

declare(strict_types=1);

namespace App\Tests\Unit\Factory;

use App\Enum\EventType;
use App\Exception\InvalidJsonException;
use App\Exception\ValidationFailedException;
use App\Factory\RecordEventRequestFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class RecordEventRequestFactoryTest extends TestCase
{
    private RecordEventRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RecordEventRequestFactory(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }

    public function testValidPayload(): void
    {
        $request = $this->factory->create(self::json());

        self::assertSame('evt_123', $request->eventId());
        self::assertSame(EventType::PaymentReceived, $request->type());
        self::assertSame(10000, $request->amountInMinorUnits());
        self::assertSame('EUR', $request->currency());
        self::assertSame('2026-01-01T00:00:00+00:00', $request->occurredAt()->format(\DATE_ATOM));
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('amounts')]
    public function testAmountIsConvertedToMinorUnits(array $overrides, int $expected): void
    {
        self::assertSame($expected, $this->factory->create(self::json($overrides))->amountInMinorUnits());
    }

    /** @return array<string, array{array<string, mixed>, int}> */
    public static function amounts(): array
    {
        return [
            // the number formats themselves are covered by DecimalAmountTest; these check
            // that the exponent comes from the currency
            'two decimals' => [['amount' => 100.50], 10050],
            'integer' => [['amount' => 100], 10000],
            'decimal string' => [['amount' => '100.00'], 10000],
            'currency without decimals' => [['amount' => 100, 'currency' => 'JPY'], 100],
            'currency with three decimals' => [['amount' => 1.234, 'currency' => 'KWD'], 1234],
        ];
    }

    #[DataProvider('invalidJson')]
    public function testInvalidJsonIsRejected(string $body): void
    {
        $this->expectException(InvalidJsonException::class);

        $this->factory->create($body);
    }

    /** @return array<string, array{string}> */
    public static function invalidJson(): array
    {
        return [
            'empty body' => [''],
            'truncated object' => ['{"event_id": "evt_123"'],
            'single quotes' => ["{'event_id': 'evt_123'}"],
            'trailing comma' => ['{"event_id": "evt_123",}'],
            'json array' => ['[{"event_id": "evt_123"}]'],
            'json scalar' => ['"evt_123"'],
            'json null' => ['null'],
        ];
    }

    /**
     * One case per invalid field. The expected key is the JSON field name the error is
     * reported under, so the HTTP layer can hand it straight back to the client.
     *
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidFields')]
    public function testInvalidFieldIsRejected(array $overrides, string $expectedField): void
    {
        try {
            $this->factory->create(self::json($overrides));
            self::fail('Expected the payload to be rejected.');
        } catch (ValidationFailedException $e) {
            self::assertSame([$expectedField], array_keys($e->getErrors()));
            self::assertNotEmpty($e->getErrors()[$expectedField]);
        }
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidFields(): array
    {
        return [
            'event_id missing' => [['event_id' => null], 'event_id'],
            'event_id empty' => [['event_id' => ''], 'event_id'],
            'event_id blank' => [['event_id' => '   '], 'event_id'],
            'event_id too long' => [['event_id' => str_repeat('e', 129)], 'event_id'],
            'event_id not a string' => [['event_id' => 42], 'event_id'],
            'event_id an object' => [['event_id' => ['evt_123']], 'event_id'],

            'type missing' => [['type' => null], 'type'],
            'type empty' => [['type' => ''], 'type'],
            'type unknown' => [['type' => 'payment_refunded'], 'type'],
            'type wrong case' => [['type' => 'PAYMENT_RECEIVED'], 'type'],
            'type not a string' => [['type' => 1], 'type'],

            'amount missing' => [['amount' => null], 'amount'],
            'amount zero' => [['amount' => 0], 'amount'],
            'amount zero with decimals' => [['amount' => 0.00], 'amount'],
            'amount negative' => [['amount' => -100.00], 'amount'],
            'amount not a number' => [['amount' => 'one hundred'], 'amount'],
            'amount in exponent notation' => [['amount' => '1e3'], 'amount'],
            'amount an object' => [['amount' => ['value' => 100]], 'amount'],
            'amount too precise for the currency' => [['amount' => 100.001], 'amount'],
            'amount with decimals in a currency without them' => [['amount' => 100.5, 'currency' => 'JPY'], 'amount'],

            'currency missing' => [['currency' => null], 'currency'],
            'currency empty' => [['currency' => ''], 'currency'],
            'currency too short' => [['currency' => 'EU'], 'currency'],
            'currency too long' => [['currency' => 'EURO'], 'currency'],
            'currency unknown code' => [['currency' => 'XYZ'], 'currency'],
            'currency lowercase' => [['currency' => 'eur'], 'currency'],
            'currency not a string' => [['currency' => 978], 'currency'],

            'timestamp missing' => [['timestamp' => null], 'timestamp'],
            'timestamp empty' => [['timestamp' => ''], 'timestamp'],
            'timestamp not a date' => [['timestamp' => 'yesterday'], 'timestamp'],
            'timestamp date only' => [['timestamp' => '2026-01-01'], 'timestamp'],
            'timestamp without a zone' => [['timestamp' => '2026-01-01T00:00:00'], 'timestamp'],
            'timestamp with an impossible date' => [['timestamp' => '2026-02-30T00:00:00Z'], 'timestamp'],
            'timestamp with an impossible month' => [['timestamp' => '2026-13-01T00:00:00Z'], 'timestamp'],
            'timestamp as a unix time' => [['timestamp' => 1767225600], 'timestamp'],
        ];
    }

    public function testEveryInvalidFieldIsReported(): void
    {
        try {
            $this->factory->create('{}');
            self::fail('Expected the payload to be rejected.');
        } catch (ValidationFailedException $e) {
            self::assertSame(
                ['event_id', 'type', 'amount', 'currency', 'timestamp'],
                array_keys($e->getErrors()),
            );
        }
    }

    #[DataProvider('acceptedTimestamps')]
    public function testTimestampIsParsed(string $timestamp, string $expected): void
    {
        $request = $this->factory->create(self::json(['timestamp' => $timestamp]));

        self::assertSame($expected, $request->occurredAt()->format(\DATE_ATOM));
    }

    /** @return array<string, array{string, string}> */
    public static function acceptedTimestamps(): array
    {
        return [
            'zulu' => ['2026-01-01T00:00:00Z', '2026-01-01T00:00:00+00:00'],
            'offset' => ['2026-01-01T03:00:00+03:00', '2026-01-01T03:00:00+03:00'],
            'fractional seconds' => ['2026-01-01T00:00:00.123Z', '2026-01-01T00:00:00+00:00'],
            'leap day' => ['2028-02-29T12:00:00Z', '2028-02-29T12:00:00+00:00'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private static function json(array $overrides = []): string
    {
        return json_encode($overrides + [
            'event_id' => 'evt_123',
            'type' => 'payment_received',
            'amount' => 100.00,
            'currency' => 'EUR',
            'timestamp' => '2026-01-01T00:00:00Z',
        ], \JSON_THROW_ON_ERROR);
    }
}
