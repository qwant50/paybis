<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\EventListener;

use App\Domain\ExchangeRate\Exception\InvalidDateException;
use App\Domain\ExchangeRate\Exception\InvalidPairException;
use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Security\ResponseSigner;
use App\Infrastructure\EventListener\ApiExceptionListener;
use App\Infrastructure\Logging\CorrelationContext;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiExceptionListenerTest extends Unit
{
    public function testItSurfacesInvalidPairAsBadRequest(): void
    {
        $event = $this->dispatch('/api/v1/rates/last-24h', InvalidPairException::forPair('EUR/DOGE', 'EUR/BTC'));

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(400, $response->getStatusCode());

        $body = $this->json($response);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Unsupported currency pair "EUR/DOGE". Supported pairs: EUR/BTC.', $body['error']['message']);
        $this->assertSame('INVALID_PAIR', $body['error']['code']);
    }

    public function testItSurfacesInvalidDateAsBadRequest(): void
    {
        $event = $this->dispatch('/api/v1/rates/day', InvalidDateException::forDate('nope'));

        $response = $event->getResponse();
        $this->assertSame(400, $response?->getStatusCode());
        $this->assertSame('INVALID_DATE', $this->json($response)['error']['code']);
    }

    public function testItPreservesNotFoundStatus(): void
    {
        $event = $this->dispatch('/api/v1/rates/typo', new NotFoundHttpException());

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('NOT_FOUND', $this->json($response)['error']['code']);
    }

    public function testItPreservesMethodNotAllowedStatusAndAllowHeader(): void
    {
        $event = $this->dispatch('/api/v1/rates/last-24h', new MethodNotAllowedHttpException(['GET']));

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('METHOD_NOT_ALLOWED', $this->json($response)['error']['code']);
        $this->assertSame('GET', $response->headers->get('Allow'));
    }

    public function testItHidesUnexpectedExceptionsBehindGeneric500(): void
    {
        $event = $this->dispatch('/api/v1/rates/day', new \RuntimeException('SQLSTATE: connection refused at /app/secret.php'));

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(500, $response->getStatusCode());

        $body = $this->json($response);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Internal server error.', $body['error']['message']);
        $this->assertSame('INTERNAL_ERROR', $body['error']['code']);
        // The internal detail must never reach the client.
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
        $this->assertStringNotContainsString('secret.php', (string) $response->getContent());
    }

    public function testItWrapsErrorsInTheSignedEnvelope(): void
    {
        $event = $this->dispatch('/api/v1/rates/last-24h', InvalidPairException::forPair('EUR/DOGE', 'EUR/BTC'));

        $body = $this->json($event->getResponse());
        $this->assertArrayHasKey('id', $body);
        $this->assertSame(['api' => 'v1', 'release' => '1.0.0'], $body['version']);
        $this->assertSame('2026-06-07T12:34:56+00:00', $body['datetime']);
        $this->assertSame('HMAC-SHA256', $body['security']['algorithm']);
        $this->assertNotSame('', $body['security']['signature']);
    }

    public function testItIgnoresNonApiRequests(): void
    {
        $event = $this->dispatch('/api/doc', new \RuntimeException('boom'));

        // Left untouched so Symfony's own handler renders it.
        $this->assertNull($event->getResponse());
    }

    private function dispatch(string $path, \Throwable $exception): ExceptionEvent
    {
        $request = Request::create($path);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $responder = new ApiResponder(
            $requestStack,
            new CorrelationContext(),
            new MockClock('2026-06-07T12:34:56+00:00'),
            new ResponseSigner('test_signing_secret', 'test'),
            '1.0.0',
        );

        $listener = new ApiExceptionListener($this->createMock(LoggerInterface::class), $responder);

        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        $listener($event);

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
