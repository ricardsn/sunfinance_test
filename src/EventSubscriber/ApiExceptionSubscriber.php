<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\DuplicateEventException;
use App\Exception\InvalidJsonException;
use App\Exception\ValidationFailedException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/** Turns the application's exceptions into RFC 7807 error responses. */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // after Symfony's own error listener has logged the exception (priority 0),
        // before it renders its error page (priority -128)
        return [KernelEvents::EXCEPTION => ['onKernelException', -64]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        $status = match (true) {
            $exception instanceof InvalidJsonException => Response::HTTP_BAD_REQUEST,
            $exception instanceof ValidationFailedException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $exception instanceof DuplicateEventException => Response::HTTP_CONFLICT,
            // the controller's 415 and routing's 404 / 405 carry their own code
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };

        $body = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            // an unexpected failure says nothing about what went wrong internally
            'detail' => Response::HTTP_INTERNAL_SERVER_ERROR === $status
                ? 'An unexpected error occurred.'
                : $exception->getMessage(),
        ];

        if ($exception instanceof ValidationFailedException) {
            $body['errors'] = $exception->getErrors();
        }

        $event->setResponse(new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']));

        // keeps the error listener from replacing this with Symfony's own error page
        $event->stopPropagation();
    }
}
