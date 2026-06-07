<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\Response;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The single envelope wrapping every API response — success and error alike.
 *
 * One consistent top-level shape so a client can always read {@see $status} to
 * branch, {@see $id} to correlate with server logs (also returned as the
 * {@code X-Request-Id} header), {@see $version} to know the contract/release, and
 * {@see $security} to verify payload integrity. Exactly one of {@code data}
 * (success) or {@code error} (failure) is present on the wire — enforced by the
 * named constructors and {@see jsonSerialize()}, which omits the absent key.
 *
 * Built centrally by {@see \App\Infrastructure\Controller\Api\ApiResponder}; the
 * meta fields are documented here, while the {@code data}/{@code error} schemas
 * are composed per endpoint via {@code allOf} (their shape is resource-specific).
 */
#[OA\Schema(schema: 'ApiEnvelope')]
final readonly class ApiEnvelope implements \JsonSerializable
{
    public const string STATUS_SUCCESS = 'success';
    public const string STATUS_ERROR = 'error';

    /**
     * @param self::STATUS_* $status
     */
    private function __construct(
        #[OA\Property(type: 'string', description: 'Correlation id, also echoed as the X-Request-Id header.', example: '01JZ8K3M9QW2T6V0R7Y5N4B8XC')]
        public string $id,
        #[OA\Property(type: 'string', enum: [self::STATUS_SUCCESS, self::STATUS_ERROR], example: self::STATUS_SUCCESS)]
        public string $status,
        #[OA\Property(ref: new Model(type: ApiVersion::class))]
        public ApiVersion $version,
        #[OA\Property(type: 'string', format: 'date-time', description: 'UTC time the response was produced.', example: '2026-06-07T12:34:56+00:00')]
        public string $datetime,
        public ?object $data,
        public ?ApiError $error,
        #[OA\Property(ref: new Model(type: Signature::class))]
        public Signature $security,
    ) {
    }

    public static function success(string $id, ApiVersion $version, string $datetime, object $data, Signature $security): self
    {
        return new self($id, self::STATUS_SUCCESS, $version, $datetime, $data, null, $security);
    }

    public static function failure(string $id, ApiVersion $version, string $datetime, ApiError $error, Signature $security): self
    {
        return new self($id, self::STATUS_ERROR, $version, $datetime, null, $error, $security);
    }

    /**
     * Emits a stable key order with exactly one of {@code data}/{@code error}.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $envelope = [
            'id' => $this->id,
            'status' => $this->status,
            'version' => $this->version,
            'datetime' => $this->datetime,
        ];

        if ($this->status === self::STATUS_SUCCESS) {
            $envelope['data'] = $this->data;
        } else {
            $envelope['error'] = $this->error;
        }

        $envelope['security'] = $this->security;

        return $envelope;
    }
}
