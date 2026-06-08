<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\CorrelationContext;
use App\Infrastructure\Logging\RequestIdProcessor;
use Codeception\Test\Unit;
use Monolog\Level;
use Monolog\LogRecord;

final class RequestIdProcessorTest extends Unit
{
    public function testItTagsTheRecordWithTheCurrentCorrelationId(): void
    {
        $context = new CorrelationContext();
        $context->set('01REQUESTID');

        $record = (new RequestIdProcessor($context))($this->record());

        $this->assertSame('01REQUESTID', $record->extra['request_id']);
    }

    public function testItLeavesTheRecordUntouchedWithoutACorrelationId(): void
    {
        $record = (new RequestIdProcessor(new CorrelationContext()))($this->record());

        $this->assertArrayNotHasKey('request_id', $record->extra);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'message');
    }
}
