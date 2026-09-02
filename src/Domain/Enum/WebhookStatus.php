<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Delivery state of a webhook subscription.
 *
 * An inactive subscription stops receiving notifications until it is activated again — the
 * gateway can also deactivate one itself when deliveries keep failing and the subscription's
 * retry policy allows it.
 */
enum WebhookStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';

    /**
     * Whether notifications are currently being delivered to this subscription.
     */
    public function isDelivering(): bool
    {
        return $this === self::Active;
    }
}
