<?php

declare(strict_types=1);

namespace App\Tests\Unit\Factory;

use App\Enum\EventStatus;
use App\Enum\EventType;
use App\Factory\ProcessedEventFactory;
use App\Factory\RecordEventRequestFactory;
use App\Model\RecordEventRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ProcessedEventFactoryTest extends TestCase
{
    private ProcessedEventFactory $factory;
    private RecordEventRequestFactory $requests;

    protected function setUp(): void
    {
        $this->factory = new ProcessedEventFactory();
        $this->requests = new RecordEventRequestFactory(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }

    public function testItMapsTheRequestOntoTheEvent(): void
    {
        $event = $this->factory->create($this->request(['type' => 'fee_charged']));

        self::assertSame('evt_1', $event->getEventId());
        self::assertSame(EventType::FeeCharged, $event->getType());
        self::assertSame('EUR', $event->getCurrency());
        self::assertSame(
            '2026-01-01T00:00:00+00:00',
            $event->getOccurredAt()->format(\DateTimeInterface::ATOM),
        );
        self::assertSame(EventStatus::Pending, $event->getStatus());
        self::assertNull($event->getProcessedAt());
    }

    #[DataProvider('amounts')]
    public function testItStoresTheAmountInMinorUnits(string $currency, mixed $amount, int $expected): void
    {
        $event = $this->factory->create($this->request([
            'amount' => $amount,
            'currency' => $currency,
        ]));

        self::assertSame($expected, $event->getAmount());
    }

    /** @return array<string, array{string, mixed, int}> */
    public static function amounts(): array
    {
        return [
            'EUR decimal string' => ['EUR', '100.50', 10_050],
            'EUR integer' => ['EUR', 100, 10_000],
            'EUR float' => ['EUR', 100.5, 10_050],
            'JPY has no minor unit' => ['JPY', 100, 100],
            'KWD has three' => ['KWD', '1.234', 1_234],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function request(array $overrides = []): RecordEventRequest
    {
        return $this->requests->create(json_encode($overrides + [
            'event_id' => 'evt_1',
            'type' => 'payment_received',
            'amount' => '100.50',
            'currency' => 'EUR',
            'timestamp' => '2026-01-01T00:00:00Z',
        ], \JSON_THROW_ON_ERROR));
    }
}
