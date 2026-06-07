<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\ArgumentResolver;

use App\Domain\ExchangeRate\CurrencyPair;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Resolves a {@see CurrencyPair} action argument from the `pair` query parameter.
 *
 * Keeps the parsing (and its domain {@see \App\Domain\ExchangeRate\Exception\InvalidPairException},
 * which the {@see \App\Infrastructure\EventListener\ApiExceptionListener} turns
 * into a 400) out of the controllers, so actions can simply type-hint
 * `CurrencyPair $pair` with no `Request` handling.
 */
final class CurrencyPairValueResolver implements ValueResolverInterface
{
    /**
     * @return iterable<CurrencyPair>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== CurrencyPair::class) {
            return [];
        }

        return [CurrencyPair::fromString((string) $request->query->get('pair', ''))];
    }
}
