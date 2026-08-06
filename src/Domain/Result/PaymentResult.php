<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * Result DTO describing the normalised outcome of a charge, capture, void, or reversal.
 *
 * Returned by the gateway's payment operations, exposing a success flag, a normalised
 * {@see PaymentStatus}, the resulting transaction id, and any gateway response code and
 * message.
 */
final readonly class PaymentResult
{
    /**
     * @param  bool  $success  Whether the payment operation succeeded
     * @param  PaymentStatus  $status  Normalised payment status enum
     * @param  string|null  $transactionId  Gateway transaction identifier for the payment
     * @param  string|null  $code  Gateway response/status code
     * @param  string|null  $message  Human-readable gateway response message
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public bool $success,
        public PaymentStatus $status,
        public ?string $transactionId = null,
        public ?string $code = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}
}
