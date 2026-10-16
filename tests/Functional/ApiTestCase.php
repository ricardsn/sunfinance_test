<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** Drives the API over HTTP: post events, then read them back. */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * The transport is sync:// in tests, so the ledger pair is written before this
     * returns and the read endpoints can see it.
     */
    protected function postEvent(
        string $eventId,
        string $type,
        string $amount = '100.50',
        string $currency = 'EUR',
    ): void {
        $this->client->jsonRequest('POST', '/events', [
            'event_id' => $eventId,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'timestamp' => '2026-01-01T00:00:00Z',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
    }

    /** @return array<string, mixed> the decoded response body */
    protected function getJson(string $uri, int $expectedStatus = Response::HTTP_OK): array
    {
        $this->client->request('GET', $uri);

        self::assertResponseStatusCodeSame($expectedStatus);

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
