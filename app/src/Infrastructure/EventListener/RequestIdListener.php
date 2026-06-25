<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Logging\CorrelationContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Ulid;

/**
 * Assigns every request a correlation id, as early as possible.
 *
 * Runs before routing (high priority) on {@see KernelEvents::REQUEST} so even
 * requests that fault during routing or argument resolution already carry an id.
 * It reuses a sane inbound {@code X-Request-Id} or mints a fresh ULID into the
 * {@see CorrelationContext}, from where {@see ApiResponder} stamps the response
 * envelope's {@code id} + {@code X-Request-Id} header and
 * {@see \App\Infrastructure\Logging\RequestIdProcessor} tags every log line —
 * letting a client correlate a response with its server-side logs.
 *
 * The response header is deliberately set by {@see ApiResponder}, not here: a
 * {@code kernel.response} listener is skipped on some exception paths (the kernel
 * swallows listener errors during exception handling), which would let the header
 * drift from the envelope id.
 */
final readonly class RequestIdListener
{
    /**
     * Accept an inbound id only if it is short and uses an unambiguous charset,
     * so a forged/oversized header can't poison logs or response headers.
     */
    private const string INBOUND_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    public function __construct(
        private CorrelationContext $correlation,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 255)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $inbound = (string) $event->getRequest()->headers->get(ApiResponder::REQUEST_ID_HEADER, '');

        $this->correlation->set(
            preg_match(self::INBOUND_PATTERN, $inbound) === 1 ? $inbound : (string) new Ulid(),
        );
    }
}
