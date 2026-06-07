<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use App\Infrastructure\Controller\Api\ApiResponder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Ulid;

/**
 * Assigns every request a correlation id, as early as possible.
 *
 * Runs before routing (high priority) on {@see KernelEvents::REQUEST} so even
 * requests that fault during routing or argument resolution already carry an id.
 * It reuses a sane inbound {@code X-Request-Id} or mints a fresh ULID and stores
 * it on the request, from where {@see ApiResponder} stamps the response envelope's
 * {@code id} + {@code X-Request-Id} header and
 * {@see \App\Infrastructure\Logging\RequestIdProcessor} tags every log line —
 * letting a client correlate a response with its server-side logs.
 *
 * The response header is deliberately set by {@see ApiResponder}, not here: a
 * {@code kernel.response} listener is skipped on some exception paths (the kernel
 * swallows listener errors during exception handling), which would let the header
 * drift from the envelope id.
 */
final class RequestIdListener
{
    /**
     * Accept an inbound id only if it is short and uses an unambiguous charset,
     * so a forged/oversized header can't poison logs or response headers.
     */
    private const string INBOUND_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 255)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $inbound = (string) $request->headers->get(ApiResponder::REQUEST_ID_HEADER, '');

        $id = preg_match(self::INBOUND_PATTERN, $inbound) === 1
            ? $inbound
            : (string) new Ulid();

        $request->attributes->set(ApiResponder::REQUEST_ID_ATTRIBUTE, $id);
    }
}
