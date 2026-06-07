<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\EventListener;

use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\EventListener\RequestIdListener;
use Codeception\Test\Unit;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestIdListenerTest extends Unit
{
    public function testItMintsAUlidWhenNoInboundHeaderIsPresent(): void
    {
        $request = Request::create('/api/v1/rates/last-24h');
        $this->listener()->onRequest($this->requestEvent($request));

        $id = $request->attributes->get(ApiResponder::REQUEST_ID_ATTRIBUTE);
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id);
    }

    public function testItReusesASaneInboundHeader(): void
    {
        $request = Request::create('/api/v1/rates/last-24h');
        $request->headers->set(ApiResponder::REQUEST_ID_HEADER, 'trace-abc_123');

        $this->listener()->onRequest($this->requestEvent($request));

        $this->assertSame('trace-abc_123', $request->attributes->get(ApiResponder::REQUEST_ID_ATTRIBUTE));
    }

    public function testItRejectsAnOversizedOrUnsafeInboundHeader(): void
    {
        $request = Request::create('/api/v1/rates/last-24h');
        $request->headers->set(ApiResponder::REQUEST_ID_HEADER, "bad id with spaces \n");

        $this->listener()->onRequest($this->requestEvent($request));

        $id = $request->attributes->get(ApiResponder::REQUEST_ID_ATTRIBUTE);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id);
    }

    private function listener(): RequestIdListener
    {
        return new RequestIdListener();
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
