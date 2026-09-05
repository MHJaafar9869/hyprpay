<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Events;

use Hyprpay\Payments\Domain\Contract\RecordsPaymentActivity;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Event\AuthorizationReversed;
use Hyprpay\Payments\Domain\Event\CheckoutSessionCreated;
use Hyprpay\Payments\Domain\Event\DccRateQuoted;
use Hyprpay\Payments\Domain\Event\InstrumentVaulted;
use Hyprpay\Payments\Domain\Event\PayerAuthenticationEciRejected;
use Hyprpay\Payments\Domain\Event\PayerAuthenticationEnrolled;
use Hyprpay\Payments\Domain\Event\PayerAuthenticationSetUp;
use Hyprpay\Payments\Domain\Event\PayerAuthenticationValidated;
use Hyprpay\Payments\Domain\Event\PaymentCaptured;
use Hyprpay\Payments\Domain\Event\PaymentCharged;
use Hyprpay\Payments\Domain\Event\PaymentEvent;
use Hyprpay\Payments\Domain\Event\PaymentRefunded;
use Hyprpay\Payments\Domain\Event\PaymentVoided;
use Hyprpay\Payments\Domain\Event\StoredCredentialCharged;
use Hyprpay\Payments\Domain\Event\WalletCharged;
use Hyprpay\Payments\Domain\Event\WebhookReceived;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Infrastructure\Http\ApiResponseRecorder;
use Illuminate\Support\Carbon;

/**
 * Listener that records a PII-safe activity entry for every payment event.
 *
 * Subscribes to the {@see PaymentEvent} interface so one registration covers every event,
 * then normalises each into a {@see PaymentActivityRecord} and hands it to the configured
 * {@see RecordsPaymentActivity} action — feeding the monitoring dashboard's activity feed.
 * Mirrors {@see LoggingPaymentEventListener}: it captures only correlation ids, the
 * normalised status/outcome, and the amount — never card data.
 *
 * When an ApiResponseRecorder is injected, the gateway HTTP calls buffered since the last
 * event are drained and attached to the record, so the dashboard can show what the
 * operation actually sent and received. They arrive already masked by the Redactor.
 */
final readonly class RecordingPaymentEventListener
{
    /**
     * @param  RecordsPaymentActivity  $activity  The record action the activity entry is written through.
     * @param  ApiResponseRecorder|null  $apiResponses  Buffer of recorded gateway calls; null when exchange recording is off.
     */
    public function __construct(
        private RecordsPaymentActivity $activity,
        private ?ApiResponseRecorder $apiResponses = null,
    ) {}

    /**
     * Handle any payment event by recording its normalised activity entry.
     */
    public function handle(PaymentEvent $event): void
    {
        $this->activity->record($this->toRecord($event));
    }

    /**
     * Normalise a payment event into a flat, PII-safe activity record.
     */
    private function toRecord(PaymentEvent $event): PaymentActivityRecord
    {
        $operation = class_basename($event);
        $gateway = $event->gateway();
        $when = Carbon::now()->toIso8601String();
        $calls = $this->apiResponses?->take() ?? [];

        return match (true) {
            $event instanceof PaymentCharged => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, null, $event->money, $when, $calls),
            $event instanceof PaymentCaptured => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, $event->transactionId, $event->money, $when, $calls),
            $event instanceof AuthorizationReversed => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, $event->transactionId, $event->money, $when, $calls),
            $event instanceof PaymentVoided => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, $event->transactionId, null, $when, $calls),
            $event instanceof PaymentRefunded => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->refundId, $event->transactionId, $event->money, $when, $calls),
            $event instanceof StoredCredentialCharged => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, $event->paymentInstrumentId, $event->money, $when, $calls),
            $event instanceof WalletCharged => PaymentActivityRecord::make($operation, $gateway, $event->result->status, $event->result->success, $event->orderReference, $event->result->transactionId, $event->wallet->value, $event->money, $when, $calls),
            $event instanceof CheckoutSessionCreated => PaymentActivityRecord::make($operation, $gateway, null, null, $event->orderReference, $event->session->reference, null, $event->money, $when, $calls),
            $event instanceof InstrumentVaulted => PaymentActivityRecord::make($operation, $gateway, null, $event->result->success, null, $event->result->paymentInstrumentId, $event->customerReference, null, $when, $calls),
            $event instanceof WebhookReceived => PaymentActivityRecord::make($operation, $gateway, $event->webhook->status, $event->webhook->verified, null, $event->webhook->transactionId, $event->webhook->eventType, null, $when, $calls),
            $event instanceof PayerAuthenticationEciRejected => PaymentActivityRecord::make($operation, $gateway, $event->status, false, null, $event->authenticationTransactionId, $event->eci, null, $when, $calls),
            $event instanceof DccRateQuoted => PaymentActivityRecord::make($operation, $gateway, null, null, $event->orderReference, $event->quote->id, $event->quote->offered ? 'offered' : 'not offered', $event->money, $when, $calls),
            $event instanceof PayerAuthenticationSetUp => PaymentActivityRecord::make($operation, $gateway, $this->legStatus($event->result->success), $event->result->success, $event->orderReference, $event->result->referenceId, $event->result->status, null, $when, $calls),
            $event instanceof PayerAuthenticationEnrolled => PaymentActivityRecord::make($operation, $gateway, $this->legStatus($event->result->success), $event->result->success, $event->orderReference, $event->result->authenticationTransactionId, $event->result->status, $event->money, $when, $calls),
            $event instanceof PayerAuthenticationValidated => PaymentActivityRecord::make($operation, $gateway, $this->legStatus($event->result->success), $event->result->success, $event->orderReference, $event->result->authenticationTransactionId, $event->result->status, $event->money, $when, $calls),
            default => PaymentActivityRecord::make($operation, $gateway, null, null, null, null, null, null, $when, $calls),
        };
    }

    /**
     * The status an authentication or conversion leg resolves to.
     *
     * These legs move no money, so neither Authorized nor Captured fits: a leg that succeeded
     * leaves the payment in flight (Pending), and one that failed ended it (Failed). Pending
     * counts as successful in the dashboard's rate, which is the reading an operator wants —
     * the step did what it was asked.
     */
    private function legStatus(bool $success): PaymentStatus
    {
        return $success ? PaymentStatus::Pending : PaymentStatus::Failed;
    }
}
