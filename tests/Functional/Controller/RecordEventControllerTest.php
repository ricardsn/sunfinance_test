<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * One test per status code the API can answer with. The transport is sync:// in tests,
 * so an accepted event is already posted by the time the response comes back.
 */
final class RecordEventControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testAnAcceptedEventAnswersWithAnEmpty202AndPostsTheLedgerPair(): void
    {
        $this->post($this->payload('evt_http_1'));

        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame(2, $this->countTransactions('evt_http_1'));
    }

    public function testAnInvalidJsonBodyAnswersWith400(): void
    {
        $this->post('{"event_id":');

        self::assertProblem(Response::HTTP_BAD_REQUEST, $this->client->getResponse());
    }

    public function testAnInvalidFieldAnswersWith422AndNamesIt(): void
    {
        $this->post($this->payload('evt_http_2', ['amount' => '0.00']));

        $problem = self::assertProblem(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse());

        self::assertArrayHasKey('amount', $problem['errors']);
    }

    public function testAnUnknownEventTypeAnswersWith422(): void
    {
        $this->post($this->payload('evt_http_3', ['type' => 'interest_accrued']));

        $problem = self::assertProblem(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse());

        self::assertArrayHasKey('type', $problem['errors']);
    }

    public function testTheSameEventIdTwiceAnswersWith409AndPostsOnlyOnePair(): void
    {
        $this->post($this->payload('evt_http_4'));
        $this->post($this->payload('evt_http_4'));

        self::assertProblem(Response::HTTP_CONFLICT, $this->client->getResponse());
        self::assertSame(2, $this->countTransactions('evt_http_4'));
    }

    public function testANonJsonContentTypeAnswersWith415(): void
    {
        $this->post($this->payload('evt_http_5'), 'text/plain');

        self::assertProblem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $this->client->getResponse());
    }

    public function testADatabaseFailureAnswersWith500AndLeaksNothing(): void
    {
        // a real persistence failure: the rename is rolled back with the test
        $this->connection->executeStatement('ALTER TABLE processed_events RENAME TO processed_events_gone');

        $this->post($this->payload('evt_http_6'));

        $problem = self::assertProblem(Response::HTTP_INTERNAL_SERVER_ERROR, $this->client->getResponse());

        self::assertSame('An unexpected error occurred.', $problem['detail']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function payload(string $eventId, array $overrides = []): string
    {
        return json_encode(array_merge([
            'event_id' => $eventId,
            'type' => 'payment_received',
            'amount' => '100.50',
            'currency' => 'EUR',
            'timestamp' => '2026-01-01T00:00:00Z',
        ], $overrides), \JSON_THROW_ON_ERROR);
    }

    private function post(string $body, string $contentType = 'application/json'): void
    {
        $this->client->request('POST', '/events', server: ['CONTENT_TYPE' => $contentType], content: $body);
    }

    /**
     * @return array<string, mixed> the decoded problem document
     */
    private static function assertProblem(int $status, Response $response): array
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($problem);
        self::assertSame($status, $problem['status']);

        return $problem;
    }

    private function countTransactions(string $eventId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM transactions WHERE event_id = ?',
            [$eventId],
        );
    }
}
