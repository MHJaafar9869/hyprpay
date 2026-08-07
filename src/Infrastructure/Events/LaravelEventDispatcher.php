<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Events;

use Hyprpay\Payments\Domain\Contract\EventDispatcher;
use Hyprpay\Payments\Domain\Event\PaymentEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * EventDispatcher adapter that forwards payment events to Laravel's event dispatcher.
 *
 * Dispatches the event object as-is, so host listeners can subscribe to a concrete event
 * class or to the {@see PaymentEvent} interface (Laravel routes interface listeners to
 * every event that implements them).
 */
final readonly class LaravelEventDispatcher implements EventDispatcher
{
    /**
     * @param  Dispatcher  $events  Laravel's event dispatcher the events are forwarded to.
     */
    public function __construct(private Dispatcher $events) {}

    public function dispatch(PaymentEvent $event): void
    {
        $this->events->dispatch($event);
    }
}
