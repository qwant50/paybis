<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\EventListener;

use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\EventListener\RequestIdListener;
use App\Infrastructure\Logging\CorrelationContext;
use Codeception\Test\Unit;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestIdListenerTest extends Unit
{
    public function testItMintsAUlidWhenNoInboundHeaderIsPresent(): void
    {
        $context = new CorrelationContext();
        $request = Request::create('/api/v1/rates/last-24h');

        (new RequestIdListener($context))->onRequest($this->requestEvent($request));

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $context->current());
    }

    public function testItReusesASaneInboundHeader(): void
    {
        $context = new CorrelationContext();
        $request = Request::create('/api/v1/rates/last-24h');
        $request->headers->set(ApiResponder::REQUEST_ID_HEADER, 'trace-abc_123');

        (new RequestIdListener($context))->onRequest($this->requestEvent($request));

        $this->assertSame('trace-abc_123', $context->current());
    }

    public function testItRejectsAnOversizedOrUnsafeInboundHeader(): void
    {
        $context = new CorrelationContext();
        $request = Request::create('/api/v1/rates/last-24h');
        $request->headers->set(ApiResponder::REQUEST_ID_HEADER, "bad id with spaces \n");

        (new RequestIdListener($context))->onRequest($this->requestEvent($request));

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $context->current());
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
