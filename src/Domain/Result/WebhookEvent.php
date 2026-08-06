<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * Result DTO representing a parsed and signature-verified inbound gateway webhook.
 *
 * Produced when the SDK receives and validates a gateway notification, exposing whether
 * the signature verified, the event type, the affected transaction id, and the
 * normalised {@see PaymentStatus} it conveys.
 */
final readonly class WebhookEvent
{
    /**
     * @param  bool  $verified  Whether the webhook signature was successfully verified
     * @param  string|null  $eventType  Gateway event type/name carried by the notification
     * @param  string|null  $transactionId  Gateway transaction identifier the event relates to
     * @param  PaymentStatus|null  $status  Normalised payment status conveyed by the event, when applicable
     * @param  array<string, mixed>  $payload  Raw decoded webhook payload
     */
    public function __construct(
        public bool $verified,
        public ?string $eventType = null,
        public ?string $transactionId = null,
        public ?PaymentStatus $status = null,
        public array $payload = [],
    ) {}
}
