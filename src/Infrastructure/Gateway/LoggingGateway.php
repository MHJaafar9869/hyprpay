<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway;

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\DccRateRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Command\WalletChargeRequest;
use Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\Result\PayerAuthResult;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\Result\VaultedInstrument;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Support\Concerns\LogsAction;
use Psr\Log\LoggerInterface;

/**
 * Decorator that logs every gateway operation with its duration through the LogsAction trait.
 *
 * Wraps any {@see PaymentGatewayInterface} and runs each operation inside
 * {@see LogsAction::logTimedAction()}, so each call is logged as `[LoggingGateway] {op}` with
 * the gateway, correlation ids, amount, and elapsed `duration_ms`. The context is deliberately
 * safe — no raw PAN, cvv, or tokens — and the trait masks sensitive keys as a backstop. The
 * identity accessors ({@see name()}, {@see credentials()}) are trivial and pass through unlogged.
 */
final readonly class LoggingGateway implements PaymentGatewayInterface
{
    use LogsAction;

    /**
     * @param  PaymentGatewayInterface  $inner  The wrapped driver that performs the operation.
     * @param  LoggerInterface  $logger  PSR-3 logger the operation lines are written to.
     * @param  array<string, mixed>  $extraContext  Extra fields merged into every log line (e.g. a component tag). Request-scoped correlation (request_id, ip, url) is better added once to your app's log context (a Monolog processor / Log::shareContext) so it lands on every line.
     */
    public function __construct(
        private PaymentGatewayInterface $inner,
        private LoggerInterface $logger,
        private array $extraContext = [],
    ) {}

    protected function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Identify the log by the gateway, not this generic decorator's class name.
     */
    protected function logName(): string
    {
        return $this->inner->name()->value;
    }

    /**
     * Only the caller's extra fields — no `action`, since the gateway + operation already identify it.
     *
     * @return array<string, mixed>
     */
    protected function baseLogContext(): array
    {
        return $this->extraContext;
    }

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
        return $this->logTimedAction('createCheckoutSession', fn (): CheckoutSession => $this->inner->createCheckoutSession($request), $this->context([
            'order_reference' => $request->orderReference,
            'amount' => $request->money->toDecimalString(),
            'currency' => $request->money->currency,
            'payment_method' => $request->paymentMethod,
        ]));
    }

    public function requestDccRate(DccRateRequest $request): DccQuote
    {
        return $this->logTimedAction('requestDccRate', fn (): DccQuote => $this->inner->requestDccRate($request), $this->context([
            'order_reference' => $request->orderReference,
            'amount' => $request->money->toDecimalString(),
            'currency' => $request->money->currency,
        ]));
    }

    public function charge(ChargeRequest $request): PaymentResult
    {
        return $this->logTimedAction('charge', fn (): PaymentResult => $this->inner->charge($request), $this->context([
            'order_reference' => $request->orderReference,
            'amount' => $request->money->toDecimalString(),
            'currency' => $request->money->currency,
        ]));
    }

    public function capture(CaptureRequest $request): PaymentResult
    {
        return $this->logTimedAction('capture', fn (): PaymentResult => $this->inner->capture($request), $this->context([
            'transaction_id' => $request->transactionId,
            'order_reference' => $request->orderReference,
            'amount' => $request->money->toDecimalString(),
            'currency' => $request->money->currency,
        ]));
    }

    public function refund(RefundRequest $request): RefundResult
    {
        return $this->logTimedAction('refund', fn (): RefundResult => $this->inner->refund($request), $this->context([
            'transaction_id' => $request->transactionId,
            'order_reference' => $request->orderReference,
            'amount' => $request->money->toDecimalString(),
            'currency' => $request->money->currency,
        ]));
    }

    public function void(VoidRequest $request): PaymentResult
    {
        return $this->logTimedAction('void', fn (): PaymentResult => $this->inner->void($request), $this->context([
            'transaction_id' => $request->transactionId,
            'order_reference' => $request->orderReference,
        ]));
    }

    public function reverseAuthorization(ReversalRequest $request): PaymentResult
    {
        return $this->logTimedAction('reverseAuthorization', fn (): PaymentResult => $this->inner->reverseAuthorization($request), $this->context([
            'transaction_id' => $request->transactionId,
            'order_reference' => $request->orderReference,
            'amount' => $request->money->toDecimalString(),
            'currency' => $request->money->currency,
        ]));
    }

    public function enrollPayerAuth(PayerAuthEnrollRequest $request): PayerAuthResult
    {
        return $this->logTimedAction('enrollPayerAuth', fn (): PayerAuthResult => $this->inner->enrollPayerAuth($request), $this->context([
            'order_reference' => $request->orderReference,
        ]));
    }

    public function validatePayerAuth(ValidatePayerAuthRequest $request): PayerAuthResult
    {
        return $this->logTimedAction('validatePayerAuth', fn (): PayerAuthResult => $this->inner->validatePayerAuth($request), $this->context([
            'order_reference' => $request->orderReference,
            'authentication_transaction_id' => $request->authenticationTransactionId,
        ]));
    }

    public function vaultInstrument(TokenizeInstrumentRequest $request): VaultedInstrument
    {
        return $this->logTimedAction('vaultInstrument', fn (): VaultedInstrument => $this->inner->vaultInstrument($request), $this->context([
            'customer_reference' => $request->customerReference,
        ]));
    }

    public function chargeStoredCredential(StoredCredentialChargeRequest $request): PaymentResult
    {
        return $this->logTimedAction('chargeStoredCredential', fn (): PaymentResult => $this->inner->chargeStoredCredential($request), $this->context([
            'payment_instrument_id' => $request->paymentInstrumentId,
            'order_reference' => $request->orderReference,
            'amount' => $request->money->toDecimalString(),
            'currency' => $request->money->currency,
        ]));
    }

    public function chargeWallet(WalletChargeRequest $request): PaymentResult
    {
        return $this->logTimedAction('chargeWallet', fn (): PaymentResult => $this->inner->chargeWallet($request), $this->context([
            'wallet' => $request->wallet->value,
            'order_reference' => $request->orderReference,
            'amount' => $request->money->toDecimalString(),
            'currency' => $request->money->currency,
        ]));
    }

    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        return $this->logTimedAction('getTransaction', fn (): TransactionSnapshot => $this->inner->getTransaction($transactionId), $this->context([
            'transaction_id' => $transactionId,
        ]));
    }

    public function searchTransaction(string $query): ?TransactionSnapshot
    {
        return $this->logTimedAction('searchTransaction', fn (): ?TransactionSnapshot => $this->inner->searchTransaction($query), $this->context([
            'query' => $query,
        ]));
    }

    public function findSuccessfulTransactionByReference(string $reference): ?TransactionSnapshot
    {
        return $this->logTimedAction('findSuccessfulTransactionByReference', fn (): ?TransactionSnapshot => $this->inner->findSuccessfulTransactionByReference($reference), $this->context([
            'reference' => $reference,
        ]));
    }

    /**
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        return $this->logTimedAction('verifyWebhook', fn (): WebhookEvent => $this->inner->verifyWebhook($rawBody, $headers), $this->context([]));
    }

    /**
     * Tag an operation's context with the gateway name.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function context(array $extra): array
    {
        return array_merge(['gateway' => $this->inner->name()->value], $extra);
    }
}
