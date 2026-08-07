<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Marker interface implemented by every payment domain event the SDK emits.
 *
 * A single listener can subscribe to this interface to receive every payment event
 * (Laravel dispatches interface listeners to all events implementing them), while a
 * caller can still target a concrete event type (e.g. {@see PaymentRefunded}) when they
 * only care about one operation.
 *
 * Events are intentionally queue-safe: they carry only non-sensitive identifiers, the
 * amount, and the normalized result — never the raw request, which can hold a PAN.
 */
interface PaymentEvent
{
    /**
     * The gateway that produced this event.
     */
    public function gateway(): GatewayName;
}
