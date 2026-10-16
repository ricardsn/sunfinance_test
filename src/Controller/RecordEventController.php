<?php

declare(strict_types=1);

namespace App\Controller;

use App\Factory\RecordEventRequestFactory;
use App\Handler\EventProcessor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class RecordEventController
{
    public function __construct(
        private RecordEventRequestFactory $requests,
        private EventProcessor $processor,
    ) {
    }

    /**
     * 202 with an empty body: the event is stored and queued here, the ledger pair is
     * written by the worker.
     */
    #[Route('/events', name: 'record_event', methods: ['POST'])]
    public function record(Request $request): Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException('The request body must be sent as application/json.');
        }

        $this->processor->process($this->requests->create($request->getContent()));

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
