<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

/**
 * One event a webhook product can notify on, as the gateway's catalogue describes it.
 *
 * Two of these fields are operationally significant rather than decorative.
 * {@see $isTimeSensitive} marks events that lose their value if they sit in a retry queue — a
 * retry policy that suspends and backfills is the wrong choice for those. {@see $isEncrypted}
 * marks events whose payload arrives encrypted, which needs message-level encryption configured
 * before the notification can be read at all.
 */
final readonly class WebhookEventType
{
    /**
     * @param  string|null  $eventName  Event name, as passed in a subscription's event list
     * @param  string|null  $displayName  Human-readable name for the event
     * @param  int|null  $frequency  How often the gateway expects to emit it
     * @param  bool  $isTimeSensitive  Whether the event loses value if delayed by a retry queue
     * @param  bool  $isEncrypted  Whether the payload arrives encrypted and needs MLE configured
     * @param  array<string, mixed>  $raw  Raw gateway payload for the event
     */
    public function __construct(
        public ?string $eventName = null,
        public ?string $displayName = null,
        public ?int $frequency = null,
        public bool $isTimeSensitive = false,
        public bool $isEncrypted = false,
        public array $raw = [],
    ) {}
}
