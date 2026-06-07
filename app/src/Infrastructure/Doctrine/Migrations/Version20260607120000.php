<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen exchange_rate.price to DECIMAL(30, 12).
 *
 * The previous DECIMAL(20, 8) was lossless for the EUR-majors but left no room
 * for micro-priced assets. Widening is value-preserving (existing rows only gain
 * trailing zeros). The column scale is a fixed storage ceiling, decoupled from
 * the per-pair display precision.
 */
final class Version20260607120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen exchange_rate.price from DECIMAL(20,8) to DECIMAL(30,12).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exchange_rate CHANGE price price NUMERIC(30, 12) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exchange_rate CHANGE price price NUMERIC(20, 8) NOT NULL');
    }
}
