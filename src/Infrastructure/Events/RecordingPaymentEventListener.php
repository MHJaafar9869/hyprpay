<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Events;

use Hyprpay\Payments\Domain\Contract\PaymentActivityRepository;
use Hyprpay\Payments\Domain\Event\AuthorizationReversed;
use Hyprpay\Payments\Domain\Event\CheckoutSessionCreated;
use Hyprpay\Payments\Domain\Event\InstrumentVaulted;
use Hyprpay\Payments\Domain\Event\PaymentCaptured;
use Hyprpay\Payments\Domain\Event\PaymentCharged;
use Hyprpay\Payments\Domain\Event\PaymentEvent;
use Hyprpay\Payments\Domain\Event\PaymentRefunded;
use Hyprpay\Payments\Domain\Event\PaymentVoided;
use Hyprpay\Payments\Domain\Event\StoredCredentialCharged;
use Hyprpay\Payments\Domain\Event\WalletCharged;
use Hyprpay\Payments\Domain\Event\WebhookReceived;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Illuminate\Support\Carbon;

/**
 * Listener that records a PII-safe activity entry for every payment event.
 *
 * Subscribes to the {@see PaymentEvent} interface so one registration covers every event,
 * then normalises each into a {@see PaymentActivityRecord} and hands it to the configured
 * {@see PaymentActivityRepository} — feeding the monitoring dashboard's activity feed.
 * Mirrors {@see LoggingPaymentEventListener}: it captures only correlation ids, the
 * normalised status/outcome, and the amount — never the raw payload or any card data.
 */
final readonly class RecordingPaymentEventListener
{
    /**
     * @param  PaymentActivityRepository  $repository  The store the activity record is written to.
     */
    public function __construct(private PaymentActivityRepository $repository) {}

    /**
     * Handle any payment event by recording its normalised activity entry.
     */
    public function handle(PaymentEvent $event): void
    {
        $this->repository->record($this->toRecord($event));
    }

    /**
     * Normalise a payment event into a flat, PII-safe activity record.
     */
    private function toRecord(PaymentEvent $event): PaymentActivityRecord
    {
        $operation = class_basename($event);
        $gateway = $event->gateway();
        $when = Carbon::now()->toIso8601String();

        return match (true) {
            $event instanceof PaymentCharged => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, null, $event->money, $when),
            $event instanceof PaymentCaptured => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, $event->transactionId, $event->money, $when),
            $event instanceof AuthorizationReversed => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, $event->transactionId, $event->money, $when),
            $event instanceof PaymentVoided => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, $event->transactionId, null, $when),
            $event instanceof PaymentRefunded => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->refundId, $event->transactionId, $event->money, $when),
            $event instanceof StoredCredentialCharged => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, $event->paymentInstrumentId, $event->money, $when),
            $event instanceof WalletCharged => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, $event->wallet->value, $event->money, $when),
            $event instanceof CheckoutSessionCreated => PaymentActivityRecord::make($operation, $gateway, null, null, $event->orderReference, $event->session->reference, null, $event->money, $when),
            $event instanceof InstrumentVaulted => PaymentActivityRecord::make($operation, $gateway, null, $event->result->success, null, $event->result->paymentInstrumentId, $event->customerReference, null, $when),
            $event instanceof WebhookReceived => PaymentActivityRecord::make($operation, $gateway, $event->webhook->status, $event->webhook->verified, null, $event->webhook->transactionId, $event->webhook->eventType, null, $when),
            default => PaymentActivityRecord::make($operation, $gateway, null, null, null, null, null, null, $when),
        };
    }
}
