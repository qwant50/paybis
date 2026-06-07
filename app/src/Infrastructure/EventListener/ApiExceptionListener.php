<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use App\Domain\ExchangeRate\Exception\InvalidDateException;
use App\Domain\ExchangeRate\Exception\InvalidPairException;
use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Response\ApiError;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Single place that turns exceptions into the API's error response — the caught
 * exception is reduced to an {@see ApiError} ({@code message} + {@code code}),
 * which {@see ApiResponder::error()} wraps in the signed {@see \App\Infrastructure\Controller\Api\Response\ApiEnvelope}.
 *
 * Three tiers, in order:
 *  - whitelisted domain *input* exceptions → 400 with their (client-safe) message;
 *  - any other {@see HttpExceptionInterface} (routing 404, 405, …) → its own
 *    status code and headers preserved, with a generic message per status;
 *  - everything else → logged at error level and hidden behind a generic 500, so
 *    internal details (DB errors, stack traces, file paths) never leak.
 */
#[AsEventListener(event: ExceptionEvent::class)]
final readonly class ApiExceptionListener
{
    /**
     * Domain exceptions whose message is safe to return verbatim as a 400,
     * mapped to the stable machine-readable code returned alongside it.
     *
     * @var array<class-string<\Throwable>, string>
     */
    private const array CLIENT_ERRORS = [
        InvalidPairException::class => 'INVALID_PAIR',
        InvalidDateException::class => 'INVALID_DATE',
    ];

    public function __construct(
        private LoggerInterface $logger,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        // Only versioned API endpoints (/api/v1, /api/v2, …) get the JSON error
        // envelope; Swagger UI (/api/doc), the profiler, etc. are left to Symfony.
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/v')) {
            return;
        }

        $exception = $event->getThrowable();

        foreach (self::CLIENT_ERRORS as $clientError => $code) {
            if ($exception instanceof $clientError) {
                $event->setResponse(
                    $this->responder->error(
                        new ApiError($exception->getMessage(), $code),
                        Response::HTTP_BAD_REQUEST,
                    )
                );

                return;
            }
        }

        // Routing/HTTP errors (404, 405, …) are client faults, not crashes:
        // keep the real status (and headers, e.g. Allow on 405), log as a notice.
        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            $this->logger->notice('API HTTP exception.', ['exception' => $exception]);

            $event->setResponse(
                $this->responder->error(
                    new ApiError(Response::$statusTexts[$status] ?? 'HTTP error.', $this->codeForStatus($status)),
                    $status,
                    $exception->getHeaders(),
                )
            );

            return;
        }

        $this->logger->error('Unhandled API exception.', ['exception' => $exception]);

        $event->setResponse(
            $this->responder->error(
                new ApiError('Internal server error.', 'INTERNAL_ERROR'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            )
        );
    }

    private function codeForStatus(int $status): string
    {
        return match ($status) {
            Response::HTTP_NOT_FOUND          => 'NOT_FOUND',
            Response::HTTP_METHOD_NOT_ALLOWED => 'METHOD_NOT_ALLOWED',
            default                           => 'HTTP_ERROR',
        };
    }
}
