<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\CorrelationContext;
use Codeception\Test\Unit;

final class CorrelationContextTest extends Unit
{
    public function testItHasNoIdUntilOneIsSet(): void
    {
        $this->assertNull(new CorrelationContext()->current());
    }

    public function testItReturnsTheSetId(): void
    {
        $context = new CorrelationContext();
        $context->set('01RUNID');

        $this->assertSame('01RUNID', $context->current());
        $this->assertSame('01RUNID', $context->getOrGenerate());
    }

    public function testGetOrGenerateMintsAndStoresAUlidWhenUnset(): void
    {
        $context = new CorrelationContext();

        $generated = $context->getOrGenerate();

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $generated);
        // Stored: a second call returns the same id rather than minting a new one.
        $this->assertSame($generated, $context->current());
        $this->assertSame($generated, $context->getOrGenerate());
    }
}
