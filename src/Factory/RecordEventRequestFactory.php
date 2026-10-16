<?php

declare(strict_types=1);

namespace App\Factory;

use App\Exception\InvalidJsonException;
use App\Exception\ValidationFailedException;
use App\Model\RecordEventRequest;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Turns a raw request body into a validated RecordEventRequest. */
final readonly class RecordEventRequestFactory
{
    /** Property paths whose JSON field name differs. */
    private const JSON_NAMES = ['eventId' => 'event_id'];

    public function __construct(private ValidatorInterface $validator)
    {
    }

    public function create(string $body): RecordEventRequest
    {
        try {
            $decoded = json_decode($body, false, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidJsonException('The request body is not valid JSON.', previous: $e);
        }

        // decoded as an object, not an array, so that {} and [] can be told apart
        if (!$decoded instanceof \stdClass) {
            throw new InvalidJsonException('The request body must be a JSON object.');
        }

        $payload = (array) $decoded;

        $request = new RecordEventRequest(
            eventId: $payload['event_id'] ?? null,
            type: $payload['type'] ?? null,
            amount: $payload['amount'] ?? null,
            currency: $payload['currency'] ?? null,
            timestamp: $payload['timestamp'] ?? null,
        );

        $violations = $this->validator->validate($request);

        if (count($violations) > 0) {
            $errors = [];

            foreach ($violations as $violation) {
                $path = $violation->getPropertyPath();
                $errors[self::JSON_NAMES[$path] ?? $path][] = (string) $violation->getMessage();
            }

            throw new ValidationFailedException($errors);
        }

        return $request;
    }
}
