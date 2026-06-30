<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ExchangeRate;

use App\Domain\ExchangeRate\Day;
use App\Domain\ExchangeRate\Exception\InvalidDateException;
use Codeception\Test\Unit;

final class DayTest extends Unit
{
    public function testItParsesAValidDayAsUtcMidnight(): void
    {
        $date = Day::fromString('2026-06-06')->toDateTime();

        $this->assertSame('2026-06-06 00:00:00', $date->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $date->getTimezone()->getName());
    }

    public function testItRejectsNonDateInput(): void
    {
        $this->expectException(InvalidDateException::class);

        Day::fromString('not-a-date');
    }

    public function testItRejectsEmptyInput(): void
    {
        $this->expectException(InvalidDateException::class);

        Day::fromString('');
    }

    public function testItRejectsOutOfRangeDates(): void
    {
        $this->expectException(InvalidDateException::class);

        // PHP would otherwise overflow this into a real date.
        Day::fromString('2026-13-40');
    }

    public function testItRejectsNonCanonicalFormats(): void
    {
        $this->expectException(InvalidDateException::class);

        Day::fromString('2026-6-6');
    }
}
