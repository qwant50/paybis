<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Controller\Api\V1\Health;

use App\Domain\ExchangeRate\RateRepository;
use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Security\ResponseSigner;
use App\Infrastructure\Controller\Api\V1\Health\Action\HealthAction;
use App\Infrastructure\Logging\CorrelationContext;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class HealthActionTest extends Unit
{
    private const string NOW = '2026-06-08 12:00:00';

    public function testItReportsUnavailableWhenTheDatabaseIsUnreachable(): void
    {
        $rates = $this->createMock(RateRepository::class);
        $rates->method('latestRecordedAt')->willThrowException(new \RuntimeException('connection refused'));

        $this->expectException(ServiceUnavailableHttpException::class);

        $this->action($rates)();
    }

    public function testItReportsUnavailableWhenNoSamplesExist(): void
    {
        $rates = $this->createMock(RateRepository::class);
        $rates->method('latestRecordedAt')->willReturn(null);

        $this->expectException(ServiceUnavailableHttpException::class);

        $this->action($rates)();
    }

    public function testItReportsUnavailableWhenTheLatestSampleIsStale(): void
    {
        // 16 minutes old — past the 15-minute (3 missed ticks) staleness threshold.
        $rates = $this->createMock(RateRepository::class);
        $rates->method('latestRecordedAt')->willReturn(
            new \DateTimeImmutable(self::NOW . ' -16 minutes', new \DateTimeZone('UTC')),
        );

        $this->expectException(ServiceUnavailableHttpException::class);

        $this->action($rates)();
    }

    private function action(RateRepository $rates): HealthAction
    {
        return new HealthAction(
            $rates,
            new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))),
            // A real responder (it is final, so not mockable) — never reached on the
            // failure paths under test, which throw before producing a response.
            new ApiResponder(new RequestStack(), new CorrelationContext(), new MockClock(), new ResponseSigner('s', 'k'), '1.0.0'),
            $this->createMock(LoggerInterface::class),
        );
    }
}
