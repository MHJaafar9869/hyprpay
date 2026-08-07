<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Events;

use Hyprpay\Payments\Domain\Event\AuthorizationReversed;
use Hyprpay\Payments\Domain\Event\CheckoutSessionCreated;
use Hyprpay\Payments\Domain\Event\InstrumentVaulted;
use Hyprpay\Payments\Domain\Event\PaymentCaptured;
use Hyprpay\Payments\Domain\Event\PaymentCharged;
use Hyprpay\Payments\Domain\Event\PaymentEvent;
use Hyprpay\Payments\Domain\Event\PaymentRefunded;
use Hyprpay\Payments\Domain\Event\PaymentVoided;
use Hyprpay\Payments\Domain\Event\StoredCredentialCharged;
use Hyprpay\Payments\Domain\Event\WebhookReceived;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Infrastructure\Http\LoggingHttpClient;
use Psr\Log\LoggerInterface;

/**
 * Listener that writes a redaction-safe audit line for every payment event via PSR-3.
 *
 * Subscribes to the {@see PaymentEvent} interface, so one registration covers every event.
 * It logs metadata ONLY — the event name, gateway, correlation ids (order/transaction),
 * and the normalized status/outcome. Following {@see LoggingHttpClient},
 * it never logs the result's `raw` payload or any card data, keeping an audit trail without
 * leaking sensitive information.
 */
final readonly class LoggingPaymentEventListener
{
    /**
     * @param  LoggerInterface  $logger  PSR-3 logger the audit line is written to.
     */
    public function __construct(private LoggerInterface $logger) {}

    /**
     * Handle any payment event by logging its safe metadata.
     */
    public function handle(PaymentEvent $event): void
    {
        $this->logger->info('gateway.payment.event', [
            'event' => class_basename($event),
            'gateway' => $event->gateway()->value,
            ...$this->context($event),
        ]);
    }

    /**
     * Build the per-event safe context (no raw payloads, no card data).
     *
     * @return array<string, mixed>
     */
    private function context(PaymentEvent $event): array
    {
        return match (true) {
            $event instanceof CheckoutSessionCreated => ['order' => $event->orderReference, 'reference' => $event->session->reference],
            $event instanceof PaymentCharged => ['order' => $event->orderReference, ...$this->outcome($event->result)],
            $event instanceof PaymentCaptured => ['authorization' => $event->transactionId, 'order' => $event->orderReference, ...$this->outcome($event->result)],
            $event instanceof PaymentVoided => ['authorization' => $event->transactionId, 'order' => $event->orderReference, ...$this->outcome($event->result)],
            $event instanceof AuthorizationReversed => ['authorization' => $event->transactionId, 'order' => $event->orderReference, ...$this->outcome($event->result)],
            $event instanceof PaymentRefunded => ['transaction' => $event->transactionId, 'order' => $event->orderReference, 'refund' => $event->result->refundId, 'status' => $event->result->status->value, 'success' => $event->result->success],
            $event instanceof StoredCredentialCharged => ['instrument' => $event->paymentInstrumentId, 'order' => $event->orderReference, ...$this->outcome($event->result)],
            $event instanceof InstrumentVaulted => ['customer' => $event->customerReference, 'instrument' => $event->result->paymentInstrumentId, 'success' => $event->result->success],
            $event instanceof WebhookReceived => ['transaction' => $event->webhook->transactionId, 'verified' => $event->webhook->verified, 'status' => $event->webhook->status?->value, 'type' => $event->webhook->eventType],
            default => [],
        };
    }

    /**
     * Extract the safe outcome fields from a PaymentResult.
     *
     * @return array<string, mixed>
     */
    private function outcome(PaymentResult $result): array
    {
        return ['transaction' => $result->transactionId, 'status' => $result->status->value, 'success' => $result->success];
    }
}
