<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Events;

use Hyprpay\Payments\Domain\Contract\EventDispatcher;
use Hyprpay\Payments\Domain\Event\PaymentEvent;

/**
 * In-memory test double implementing the {@see EventDispatcher} port.
 *
 * Records every dispatched event so tests can assert which events fired and with what
 * payload, without a framework event system.
 */
final class RecordingEventDispatcher implements EventDispatcher
{
    /**
     * @var array<int, PaymentEvent>
     */
    private array $dispatched = [];

    public function dispatch(PaymentEvent $event): void
    {
        $this->dispatched[] = $event;
    }

    /**
     * Return every event dispatched so far, in order.
     *
     * @return array<int, PaymentEvent>
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }

    /**
     * Return the most recently dispatched event, or null when none have been dispatched.
     */
    public function last(): ?PaymentEvent
    {
        $key = array_key_last($this->dispatched);

        return $key === null ? null : $this->dispatched[$key];
    }

    /**
     * Return the number of events dispatched.
     */
    public function count(): int
    {
        return count($this->dispatched);
    }
}
