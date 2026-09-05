<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway;

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\DccRateRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthSetupRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Command\WalletChargeRequest;
use Hyprpay\Payments\Domain\Contract\EventDispatcher;
use Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Event\AuthorizationReversed;
use Hyprpay\Payments\Domain\Event\CheckoutSessionCreated;
use Hyprpay\Payments\Domain\Event\DccRateQuoted;
use Hyprpay\Payments\Domain\Event\InstrumentVaulted;
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
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\Result\PayerAuthResult;
use Hyprpay\Payments\Domain\Result\PayerAuthSetupResult;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\Result\VaultedInstrument;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;

/**
 * Decorator that dispatches a payment domain event after each lifecycle operation.
 *
 * Wraps any {@see PaymentGatewayInterface} and, after the inner driver returns, emits the
 * matching {@see PaymentEvent} through the {@see EventDispatcher}
 * port. Events fire on completion regardless of success — the result's success/status carries
 * the outcome — so listeners can react to declines too; if the inner call throws, the
 * exception propagates and no event is dispatched.
 *
 * Read/query operations (DCC quote, payer-auth enroll/validate, transaction lookup/search)
 * and the identity accessors are pass-through, matching the events the SDK actually models.
 */
final readonly class EventDispatchingGateway implements PaymentGatewayInterface
{
    /**
     * @param  PaymentGatewayInterface  $inner  The wrapped driver that performs the operation.
     * @param  EventDispatcher  $events  The port events are dispatched through.
     */
    public function __construct(
        private PaymentGatewayInterface $inner,
        private EventDispatcher $events,
    ) {}

    public function name(): GatewayName
    {
        return $this->inner->name();
    }

    public function credentials(): GatewayCredentials
    {
        return $this->inner->credentials();
    }

    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $session = $this->inner->createCheckoutSession($request);

        $this->events->dispatch(new CheckoutSessionCreated($this->name(), $request->orderReference, $request->money, $session));

        return $session;
    }

    public function requestDccRate(DccRateRequest $request): DccQuote
    {
        $quote = $this->inner->requestDccRate($request);

        $this->events->dispatch(new DccRateQuoted($this->name(), $request->orderReference, $request->money, $quote));

        return $quote;
    }

    public function charge(ChargeRequest $request): PaymentResult
    {
        $result = $this->inner->charge($request);

        $this->events->dispatch(new PaymentCharged($this->name(), $request->orderReference, $request->money, $result));

        return $result;
    }

    public function capture(CaptureRequest $request): PaymentResult
    {
        $result = $this->inner->capture($request);

        $this->events->dispatch(new PaymentCaptured($this->name(), $request->transactionId, $request->orderReference, $request->money, $result));

        return $result;
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $result = $this->inner->refund($request);

        $this->events->dispatch(new PaymentRefunded($this->name(), $request->transactionId, $request->orderReference, $request->money, $result));

        return $result;
    }

    public function void(VoidRequest $request): PaymentResult
    {
        $result = $this->inner->void($request);

        $this->events->dispatch(new PaymentVoided($this->name(), $request->transactionId, $request->orderReference, $result));

        return $result;
    }

    public function reverseAuthorization(ReversalRequest $request): PaymentResult
    {
        $result = $this->inner->reverseAuthorization($request);

        $this->events->dispatch(new AuthorizationReversed($this->name(), $request->transactionId, $request->orderReference, $request->money, $result));

        return $result;
    }

    public function setupPayerAuth(PayerAuthSetupRequest $request): PayerAuthSetupResult
    {
        $result = $this->inner->setupPayerAuth($request);

        $this->events->dispatch(new PayerAuthenticationSetUp($this->name(), $request->orderReference, $result));

        return $result;
    }

    public function enrollPayerAuth(PayerAuthEnrollRequest $request): PayerAuthResult
    {
        $result = $this->inner->enrollPayerAuth($request);

        $this->events->dispatch(new PayerAuthenticationEnrolled($this->name(), $request->orderReference, $request->money, $result));

        return $result;
    }

    public function validatePayerAuth(ValidatePayerAuthRequest $request): PayerAuthResult
    {
        $result = $this->inner->validatePayerAuth($request);

        $this->events->dispatch(new PayerAuthenticationValidated($this->name(), $request->orderReference, $request->money, $result));

        return $result;
    }

    public function vaultInstrument(TokenizeInstrumentRequest $request): VaultedInstrument
    {
        $result = $this->inner->vaultInstrument($request);

        $this->events->dispatch(new InstrumentVaulted($this->name(), $request->customerReference, $result));

        return $result;
    }

    public function chargeStoredCredential(StoredCredentialChargeRequest $request): PaymentResult
    {
        $result = $this->inner->chargeStoredCredential($request);

        $this->events->dispatch(new StoredCredentialCharged($this->name(), $request->paymentInstrumentId, $request->orderReference, $request->money, $result));

        return $result;
    }

    public function chargeWallet(WalletChargeRequest $request): PaymentResult
    {
        $result = $this->inner->chargeWallet($request);

        $this->events->dispatch(new WalletCharged($this->name(), $request->wallet, $request->orderReference, $request->money, $result));

        return $result;
    }

    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        return $this->inner->getTransaction($transactionId);
    }

    public function searchTransaction(string $query): ?TransactionSnapshot
    {
        return $this->inner->searchTransaction($query);
    }

    public function findSuccessfulTransactionByReference(string $reference): ?TransactionSnapshot
    {
        return $this->inner->findSuccessfulTransactionByReference($reference);
    }

    public function listTransactions(string $query): array
    {
        return $this->inner->listTransactions($query);
    }

    public function listTransactionsByReference(string $reference): array
    {
        return $this->inner->listTransactionsByReference($reference);
    }

    /**
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $event = $this->inner->verifyWebhook($rawBody, $headers);

        $this->events->dispatch(new WebhookReceived($this->name(), $event));

        return $event;
    }
}
