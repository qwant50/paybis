<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;

/**
 * A `datetime_immutable` variant that persists microsecond precision as MySQL
 * `DATETIME(6)`.
 *
 * The built-in {@see DateTimeImmutableType} formats writes as `Y-m-d H:i:s`, so it
 * would store `.000000` even in a fractional-second column. This type keeps the
 * microseconds on write (and parses them back on read), which is what makes a
 * `created_at` column actually useful for debugging insert timing.
 *
 * The fractional-second precision lives in the migration DDL (`DATETIME(6)`), not in
 * {@see getSQLDeclaration()}: the schema comparator ignores datetime precision, so
 * declaring `DATETIME(6)` here would make the model report a phantom diff against the
 * column DBAL re-introspects as plain `DATETIME`. The inherited `DATETIME` declaration
 * keeps `doctrine:schema:validate` green while the column stays `DATETIME(6)`.
 */
final class DateTimeImmutableMicrosecondType extends DateTimeImmutableType
{
    public const string NAME = 'datetime_immutable_microsecond';

    private const string FORMAT = 'Y-m-d H:i:s.u';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->format(self::FORMAT);
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (is_string($value)) {
            $dateTime = \DateTimeImmutable::createFromFormat(self::FORMAT, $value);

            if ($dateTime !== false) {
                return $dateTime;
            }
        }

        return parent::convertToPHPValue($value, $platform);
    }
}
