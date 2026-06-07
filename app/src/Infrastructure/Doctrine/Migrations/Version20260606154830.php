<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the exchange_rate table that stores EUR→crypto rate samples.
 */
final class Version20260606154830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exchange_rate table with index on (pair, recorded_at).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE exchange_rate (
              id INT AUTO_INCREMENT NOT NULL,
              pair VARCHAR(16) NOT NULL,
              price NUMERIC(20, 8) NOT NULL,
              recorded_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              INDEX idx_exchange_rate_pair_recorded_at (pair, recorded_at),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE exchange_rate');
    }
}
