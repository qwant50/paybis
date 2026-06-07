<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\ArgumentResolver;

use App\Domain\ExchangeRate\Day;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Resolves a {@see Day} action argument from the `date` query parameter.
 *
 * Keeps the strict YYYY-MM-DD parsing (and its domain
 * {@see \App\Domain\ExchangeRate\Exception\InvalidDateException}, mapped to a 400
 * by the {@see \App\Infrastructure\EventListener\ApiExceptionListener}) out of the
 * controllers, so actions can simply type-hint `Day $day`.
 */
final class DayValueResolver implements ValueResolverInterface
{
    /**
     * @return iterable<Day>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== Day::class) {
            return [];
        }

        return [Day::fromString((string) $request->query->get('date', ''))];
    }
}
