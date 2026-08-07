<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Contract;

use Hyprpay\Payments\Domain\Event\PaymentEvent;

/**
 * Port that dispatches the SDK's payment domain events to the host application.
 *
 * Lets the gateway decorator announce what happened (a charge, capture, refund, webhook,
 * …) without knowing how the host handles it; the default adapter (LaravelEventDispatcher)
 * forwards to Laravel's event dispatcher so listeners can subscribe to a specific event or
 * to the shared {@see PaymentEvent} interface.
 */
interface EventDispatcher
{
    /**
     * Dispatch a payment domain event to any registered listeners.
     *
     * @param  PaymentEvent  $event  The event describing the operation that just completed.
     */
    public function dispatch(PaymentEvent $event): void;
}
