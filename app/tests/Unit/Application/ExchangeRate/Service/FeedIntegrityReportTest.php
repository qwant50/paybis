<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ExchangeRate\Service;

use App\Application\ExchangeRate\Service\FeedIntegrityReport;
use Codeception\Test\Unit;

final class FeedIntegrityReportTest extends Unit
{
    public function testACleanRunHasNoFailures(): void
    {
        $report = new FeedIntegrityReport(
            checkedPairs: 3,
            failedPairs: 0,
            missingSlots: 2,
            repairedSlots: 2,
            unrepairedSlots: 0,
        );

        $this->assertFalse($report->hasFailures());
    }

    public function testAFailedPairCountsAsAFailure(): void
    {
        $report = new FeedIntegrityReport(
            checkedPairs: 2,
            failedPairs: 1,
            missingSlots: 0,
            repairedSlots: 0,
            unrepairedSlots: 0,
        );

        $this->assertTrue($report->hasFailures());
    }

    public function testAnUnrepairedSlotCountsAsAFailure(): void
    {
        $report = new FeedIntegrityReport(
            checkedPairs: 3,
            failedPairs: 0,
            missingSlots: 1,
            repairedSlots: 0,
            unrepairedSlots: 1,
        );

        $this->assertTrue($report->hasFailures());
    }
}
