<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * Result DTO describing the normalised outcome of a refund operation.
 *
 * Returned by the gateway's refund operation, exposing a success flag, a normalised
 * {@see PaymentStatus}, the resulting refund id, and any gateway response code and
 * message.
 */
final readonly class RefundResult
{
    /**
     * @param  bool  $success  Whether the refund succeeded
     * @param  PaymentStatus  $status  Normalised payment status enum for the refund
     * @param  string|null  $refundId  Gateway identifier for the created refund
     * @param  string|null  $code  Gateway response/status code
     * @param  string|null  $message  Human-readable gateway response message
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public bool $success,
        public PaymentStatus $status,
        public ?string $refundId = null,
        public ?string $code = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}
}
