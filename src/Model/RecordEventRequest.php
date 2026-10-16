<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\EventType;
use App\Helper\DecimalAmount;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The POST /events payload, exactly as it arrived. The fields are typed `mixed` so that
 * a wrong JSON type becomes a validation error instead of a TypeError, and every field
 * is wrapped in Sequentially so the later constraints never see a value they cannot
 * handle. The accessors at the bottom return converted values and may only be called
 * once validation has passed.
 */
final class RecordEventRequest
{
    /** ISO-8601 date and time; the zone is required so the stored instant is unambiguous. */
    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    public function __construct(
        #[Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('string'),
            new Assert\NotBlank(normalizer: 'trim'),
            new Assert\Length(max: 128),
        ])]
        public readonly mixed $eventId,

        #[Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('string'),
            new Assert\NotBlank(),
            new Assert\Choice(
                callback: [self::class, 'eventTypes'],
                message: 'This value is not a supported event type.',
            ),
        ])]
        public readonly mixed $type,

        #[Assert\NotNull]
        public readonly mixed $amount,

        #[Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('string'),
            new Assert\NotBlank(),
            new Assert\Length(exactly: 3),
            new Assert\Currency(),
        ])]
        public readonly mixed $currency,

        #[Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('string'),
            new Assert\NotBlank(),
        ])]
        public readonly mixed $timestamp,
    ) {
    }

    /** @return list<string> */
    public static function eventTypes(): array
    {
        return array_column(EventType::cases(), 'value');
    }

    /**
     * Amount and currency are checked together: how many decimal places are allowed
     * depends on the currency exponent (EUR 2, JPY 0, KWD 3).
     */
    #[Assert\Callback]
    public function validateAmount(ExecutionContextInterface $context): void
    {
        if (null === $this->amount) {
            return; // NotNull reports it
        }

        $amount = DecimalAmount::tryFrom($this->amount);

        if (null === $amount) {
            $this->fail($context, 'amount', 'This value should be a number.');

            return;
        }

        if (!$amount->isPositive()) {
            $this->fail($context, 'amount', 'This value should be greater than zero.');

            return;
        }

        if (!is_string($this->currency) || !Currencies::exists($this->currency)) {
            return; // the currency constraints report it; without a currency there is no exponent
        }

        $exponent = Currencies::getFractionDigits($this->currency);

        if ($amount->decimals() > $exponent) {
            $this->fail($context, 'amount', 'This value should not have more than {{ limit }} decimal places in {{ currency }}.', [
                '{{ limit }}' => (string) $exponent,
                '{{ currency }}' => $this->currency,
            ]);
        }
    }

    #[Assert\Callback]
    public function validateTimestamp(ExecutionContextInterface $context): void
    {
        if (!is_string($this->timestamp) || '' === $this->timestamp) {
            return;
        }

        if (null === $this->parseTimestamp()) {
            $this->fail($context, 'timestamp', 'This value is not a valid ISO-8601 date and time.');
        }
    }

    public function eventId(): string
    {
        \assert(is_string($this->eventId));

        return $this->eventId;
    }

    public function type(): EventType
    {
        \assert(is_string($this->type));

        return EventType::from($this->type);
    }

    public function currency(): string
    {
        \assert(is_string($this->currency));

        return $this->currency;
    }

    /** The amount in minor units: 100.00 EUR -> 10000, 100 JPY -> 100. */
    public function amountInMinorUnits(): int
    {
        $amount = DecimalAmount::tryFrom($this->amount);
        \assert(null !== $amount);

        return $amount->toMinorUnits(Currencies::getFractionDigits($this->currency()));
    }

    public function occurredAt(): \DateTimeImmutable
    {
        $at = $this->parseTimestamp();
        \assert(null !== $at);

        return $at;
    }

    private function parseTimestamp(): ?\DateTimeImmutable
    {
        if (!is_string($this->timestamp) || 1 !== preg_match(self::ISO_8601, $this->timestamp)) {
            return null;
        }

        try {
            $at = new \DateTimeImmutable($this->timestamp);
        } catch (\Exception) {
            return null;
        }

        // PHP rolls 2026-02-30 over to March 2 instead of failing, so the calendar
        // date has to be compared back against the input
        return str_starts_with($this->timestamp, $at->format('Y-m-d')) ? $at : null;
    }

    /** @param array<string, string> $parameters */
    private function fail(ExecutionContextInterface $context, string $field, string $message, array $parameters = []): void
    {
        $context->buildViolation($message)
            ->setParameters($parameters)
            ->atPath($field)
            ->addViolation();
    }
}
