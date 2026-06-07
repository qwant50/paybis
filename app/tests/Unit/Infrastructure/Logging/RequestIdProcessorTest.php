<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Logging\RequestIdProcessor;
use Codeception\Test\Unit;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestIdProcessorTest extends Unit
{
    public function testItTagsTheRecordWithTheCurrentRequestId(): void
    {
        $request = Request::create('/api/v1/rates/last-24h');
        $request->attributes->set(ApiResponder::REQUEST_ID_ATTRIBUTE, '01REQUESTID');

        $stack = new RequestStack();
        $stack->push($request);

        $record = (new RequestIdProcessor($stack))($this->record());

        $this->assertSame('01REQUESTID', $record->extra['request_id']);
    }

    public function testItLeavesTheRecordUntouchedWithoutARequest(): void
    {
        $record = (new RequestIdProcessor(new RequestStack()))($this->record());

        $this->assertArrayNotHasKey('request_id', $record->extra);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'message');
    }
}
