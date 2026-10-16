<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * One test per status code the API can answer with. The transport is sync:// in tests,
 * so an accepted event is already posted by the time the response comes back.
 */
final class RecordEventControllerTest extends ApiTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testAnAcceptedEventAnswersWithAnEmpty202AndPostsTheLedgerPair(): void
    {
        $this->post($this->payload('evt_http_1'));

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertSame(2, $this->countTransactions('evt_http_1'));
    }

    public function testAnInvalidJsonBodyAnswersWith400(): void
    {
        $this->postRaw('{"event_id":');

        $this->assertProblem(Response::HTTP_BAD_REQUEST);
    }

    public function testAnInvalidFieldAnswersWith422AndNamesIt(): void
    {
        $this->post($this->payload('evt_http_2', ['amount' => '0.00']));

        $problem = $this->assertProblem(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertArrayHasKey('amount', $problem['errors']);
    }

    public function testAnUnknownEventTypeAnswersWith422(): void
    {
        $this->post($this->payload('evt_http_3', ['type' => 'interest_accrued']));

        $problem = $this->assertProblem(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertArrayHasKey('type', $problem['errors']);
    }

    public function testTheSameEventIdTwiceAnswersWith409AndPostsOnlyOnePair(): void
    {
        $this->post($this->payload('evt_http_4'));
        $this->post($this->payload('evt_http_4'));

        $this->assertProblem(Response::HTTP_CONFLICT);
        self::assertSame(2, $this->countTransactions('evt_http_4'));
    }

    public function testANonJsonContentTypeAnswersWith415(): void
    {
        $this->postRaw(json_encode($this->payload('evt_http_5'), \JSON_THROW_ON_ERROR), 'text/plain');

        $this->assertProblem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    public function testADatabaseFailureAnswersWith500AndLeaksNothing(): void
    {
        // a real persistence failure: the rename is rolled back with the test
        $this->connection->executeStatement('ALTER TABLE processed_events RENAME TO processed_events_gone');

        $this->post($this->payload('evt_http_6'));

        $problem = $this->assertProblem(Response::HTTP_INTERNAL_SERVER_ERROR);

        self::assertSame('An unexpected error occurred.', $problem['detail']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(string $eventId, array $overrides = []): array
    {
        return array_merge([
            'event_id' => $eventId,
            'type' => 'payment_received',
            'amount' => '100.50',
            'currency' => 'EUR',
            'timestamp' => '2026-01-01T00:00:00Z',
        ], $overrides);
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): void
    {
        $this->client->jsonRequest('POST', '/events', $payload);
    }

    /** For the bodies jsonRequest() cannot send: broken JSON, or another content type. */
    private function postRaw(string $body, string $contentType = 'application/json'): void
    {
        $this->client->request('POST', '/events', server: ['CONTENT_TYPE' => $contentType], content: $body);
    }

    /** @return array<string, mixed> the decoded problem document */
    private function assertProblem(int $status): array
    {
        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

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
