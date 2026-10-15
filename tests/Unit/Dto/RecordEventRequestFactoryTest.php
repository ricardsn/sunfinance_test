<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\RecordEventRequestFactory;
use App\Enum\EventType;
use App\Exception\InvalidJsonException;
use App\Exception\ValidationFailedException;
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
        $request = $this->factory->fromJson(self::json());

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
        self::assertSame($expected, $this->factory->fromJson(self::json($overrides))->amountInMinorUnits());
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function amounts(): iterable
    {
        // the number formats themselves are covered by DecimalAmountTest; these check
        // that the exponent comes from the currency
        yield 'two decimals' => [['amount' => 100.50], 10050];
        yield 'integer' => [['amount' => 100], 10000];
        yield 'decimal string' => [['amount' => '100.00'], 10000];
        yield 'currency without decimals' => [['amount' => 100, 'currency' => 'JPY'], 100];
        yield 'currency with three decimals' => [['amount' => 1.234, 'currency' => 'KWD'], 1234];
    }

    #[DataProvider('invalidJson')]
    public function testInvalidJsonIsRejected(string $body): void
    {
        $this->expectException(InvalidJsonException::class);

        $this->factory->fromJson($body);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidJson(): iterable
    {
        yield 'empty body' => [''];
        yield 'truncated object' => ['{"event_id": "evt_123"'];
        yield 'single quotes' => ["{'event_id': 'evt_123'}"];
        yield 'trailing comma' => ['{"event_id": "evt_123",}'];
        yield 'json array' => ['[{"event_id": "evt_123"}]'];
        yield 'json scalar' => ['"evt_123"'];
        yield 'json null' => ['null'];
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
            $this->factory->fromJson(self::json($overrides));
            self::fail('Expected the payload to be rejected.');
        } catch (ValidationFailedException $e) {
            self::assertSame([$expectedField], array_keys($e->getErrors()));
            self::assertNotEmpty($e->getErrors()[$expectedField]);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidFields(): iterable
    {
        yield 'event_id missing' => [['event_id' => null], 'event_id'];
        yield 'event_id empty' => [['event_id' => ''], 'event_id'];
        yield 'event_id blank' => [['event_id' => '   '], 'event_id'];
        yield 'event_id too long' => [['event_id' => str_repeat('e', 129)], 'event_id'];
        yield 'event_id not a string' => [['event_id' => 42], 'event_id'];
        yield 'event_id an object' => [['event_id' => ['evt_123']], 'event_id'];

        yield 'type missing' => [['type' => null], 'type'];
        yield 'type empty' => [['type' => ''], 'type'];
        yield 'type unknown' => [['type' => 'payment_refunded'], 'type'];
        yield 'type wrong case' => [['type' => 'PAYMENT_RECEIVED'], 'type'];
        yield 'type not a string' => [['type' => 1], 'type'];

        yield 'amount missing' => [['amount' => null], 'amount'];
        yield 'amount zero' => [['amount' => 0], 'amount'];
        yield 'amount zero with decimals' => [['amount' => 0.00], 'amount'];
        yield 'amount negative' => [['amount' => -100.00], 'amount'];
        yield 'amount not a number' => [['amount' => 'one hundred'], 'amount'];
        yield 'amount in exponent notation' => [['amount' => '1e3'], 'amount'];
        yield 'amount an object' => [['amount' => ['value' => 100]], 'amount'];
        yield 'amount too precise for the currency' => [['amount' => 100.001], 'amount'];
        yield 'amount with decimals in a currency without them' => [['amount' => 100.5, 'currency' => 'JPY'], 'amount'];

        yield 'currency missing' => [['currency' => null], 'currency'];
        yield 'currency empty' => [['currency' => ''], 'currency'];
        yield 'currency too short' => [['currency' => 'EU'], 'currency'];
        yield 'currency too long' => [['currency' => 'EURO'], 'currency'];
        yield 'currency unknown code' => [['currency' => 'XYZ'], 'currency'];
        yield 'currency lowercase' => [['currency' => 'eur'], 'currency'];
        yield 'currency not a string' => [['currency' => 978], 'currency'];

        yield 'timestamp missing' => [['timestamp' => null], 'timestamp'];
        yield 'timestamp empty' => [['timestamp' => ''], 'timestamp'];
        yield 'timestamp not a date' => [['timestamp' => 'yesterday'], 'timestamp'];
        yield 'timestamp date only' => [['timestamp' => '2026-01-01'], 'timestamp'];
        yield 'timestamp without a zone' => [['timestamp' => '2026-01-01T00:00:00'], 'timestamp'];
        yield 'timestamp with an impossible date' => [['timestamp' => '2026-02-30T00:00:00Z'], 'timestamp'];
        yield 'timestamp with an impossible month' => [['timestamp' => '2026-13-01T00:00:00Z'], 'timestamp'];
        yield 'timestamp as a unix time' => [['timestamp' => 1767225600], 'timestamp'];
    }

    public function testEveryInvalidFieldIsReported(): void
    {
        try {
            $this->factory->fromJson('{}');
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
        $request = $this->factory->fromJson(self::json(['timestamp' => $timestamp]));

        self::assertSame($expected, $request->occurredAt()->format(\DATE_ATOM));
    }

    /** @return iterable<string, array{string, string}> */
    public static function acceptedTimestamps(): iterable
    {
        yield 'zulu' => ['2026-01-01T00:00:00Z', '2026-01-01T00:00:00+00:00'];
        yield 'offset' => ['2026-01-01T03:00:00+03:00', '2026-01-01T03:00:00+03:00'];
        yield 'fractional seconds' => ['2026-01-01T00:00:00.123Z', '2026-01-01T00:00:00+00:00'];
        yield 'leap day' => ['2028-02-29T12:00:00Z', '2028-02-29T12:00:00+00:00'];
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
