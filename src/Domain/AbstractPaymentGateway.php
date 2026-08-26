<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain;

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
use Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Exception\UnsupportedOperationException;
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
 * Base class for gateway drivers implementing the PaymentGatewayInterface port.
 *
 * Holds the gateway's credentials and provides a default implementation for
 * every operation that rejects it with UnsupportedOperationException. Concrete
 * gateways extend this class and override only the operations they actually
 * support, and must implement name() to identify themselves.
 */
abstract class AbstractPaymentGateway implements PaymentGatewayInterface
{
    /**
     * @param  GatewayCredentials  $gatewayCredentials  The credentials this driver uses to authenticate against the gateway.
     */
    public function __construct(protected readonly GatewayCredentials $gatewayCredentials) {}

    /**
     * Identify which gateway this driver represents.
     *
     * @return GatewayName The canonical gateway identifier.
     */
    abstract public function name(): GatewayName;

    /**
     * Expose the credentials this driver was constructed with.
     *
     * @return GatewayCredentials The credentials used to authenticate against the gateway.
     */
    public function credentials(): GatewayCredentials
    {
        return $this->gatewayCredentials;
    }

    /**
     * Create a hosted/embedded checkout session; unsupported unless overridden.
     *
     * @param  CheckoutSessionRequest  $request  The session parameters (amount, currency, context).
     * @return CheckoutSession The created checkout session.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'createCheckoutSession');
    }

    /**
     * Request a Dynamic Currency Conversion rate quote; unsupported unless overridden.
     *
     * @param  DccRateRequest  $request  The amount, merchant currency, and card to quote.
     * @return DccQuote The quoted DCC rate and converted amount.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function requestDccRate(DccRateRequest $request): DccQuote
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'requestDccRate');
    }

    /**
     * Authorize (and optionally capture) a payment; unsupported unless overridden.
     *
     * @param  ChargeRequest  $request  The charge details (amount, instrument, context).
     * @return PaymentResult The normalized outcome of the charge.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function charge(ChargeRequest $request): PaymentResult
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'charge');
    }

    /**
     * Capture funds for a prior authorization; unsupported unless overridden.
     *
     * @param  CaptureRequest  $request  The capture details (authorization reference, amount).
     * @return PaymentResult The normalized outcome of the capture.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function capture(CaptureRequest $request): PaymentResult
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'capture');
    }

    /**
     * Refund a settled payment; unsupported unless overridden.
     *
     * @param  RefundRequest  $request  The refund details (payment reference, amount).
     * @return RefundResult The normalized outcome of the refund.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'refund');
    }

    /**
     * Void an authorized-but-uncaptured payment; unsupported unless overridden.
     *
     * @param  VoidRequest  $request  The void details (payment reference).
     * @return PaymentResult The normalized outcome of the void.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function void(VoidRequest $request): PaymentResult
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'void');
    }

    /**
     * Reverse an existing authorization; unsupported unless overridden.
     *
     * @param  ReversalRequest  $request  The reversal details (authorization reference).
     * @return PaymentResult The normalized outcome of the reversal.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function reverseAuthorization(ReversalRequest $request): PaymentResult
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'reverseAuthorization');
    }

    /**
     * Set up 3-D Secure payer authentication (device data collection); unsupported unless overridden.
     *
     * @param  PayerAuthSetupRequest  $request  The setup details (instrument, order context).
     * @return PayerAuthSetupResult The setup outcome.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function setupPayerAuth(PayerAuthSetupRequest $request): PayerAuthSetupResult
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'setupPayerAuth');
    }

    /**
     * Begin 3-D Secure payer authentication enrollment; unsupported unless overridden.
     *
     * @param  PayerAuthEnrollRequest  $request  The enrollment details (instrument, amount, return context).
     * @return PayerAuthResult The enrollment outcome.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function enrollPayerAuth(PayerAuthEnrollRequest $request): PayerAuthResult
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'enrollPayerAuth');
    }

    /**
     * Complete 3-D Secure payer authentication validation; unsupported unless overridden.
     *
     * @param  ValidatePayerAuthRequest  $request  The validation details (authentication reference/response).
     * @return PayerAuthResult The validation outcome.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function validatePayerAuth(ValidatePayerAuthRequest $request): PayerAuthResult
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'validatePayerAuth');
    }

    /**
     * Tokenize a payment instrument into the vault; unsupported unless overridden.
     *
     * @param  TokenizeInstrumentRequest  $request  The instrument to vault and its context.
     * @return VaultedInstrument The stored instrument reference/token.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function vaultInstrument(TokenizeInstrumentRequest $request): VaultedInstrument
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'vaultInstrument');
    }

    /**
     * Charge a stored (card-on-file) credential; unsupported unless overridden.
     *
     * @param  StoredCredentialChargeRequest  $request  The stored-credential charge details (token, amount, initiator).
     * @return PaymentResult The normalized outcome of the charge.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function chargeStoredCredential(StoredCredentialChargeRequest $request): PaymentResult
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'chargeStoredCredential');
    }

    /**
     * Charge a digital-wallet payment token (Apple Pay / Google Pay); unsupported unless overridden.
     *
     * @param  WalletChargeRequest  $request  The wallet charge details (encrypted token, wallet type, amount, context).
     * @return PaymentResult The normalized outcome of the charge.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function chargeWallet(WalletChargeRequest $request): PaymentResult
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'chargeWallet');
    }

    /**
     * Retrieve a transaction by its gateway identifier; unsupported unless overridden.
     *
     * @param  string  $transactionId  The gateway's transaction identifier.
     * @return TransactionSnapshot A normalized snapshot of the transaction.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'getTransaction');
    }

    /**
     * Search for a transaction with a gateway-specific query; unsupported unless overridden.
     *
     * @param  string  $query  The search query understood by the gateway.
     * @return TransactionSnapshot|null The matching transaction snapshot, or null when nothing matches.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function searchTransaction(string $query): ?TransactionSnapshot
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'searchTransaction');
    }

    /**
     * Find the most recent settled transaction for a merchant reference; unsupported unless overridden.
     *
     * @param  string  $reference  The merchant reference the original charge was sent with.
     * @return TransactionSnapshot|null The most recent settled transaction for the reference, or null when none exists.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function findSuccessfulTransactionByReference(string $reference): ?TransactionSnapshot
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'findSuccessfulTransactionByReference');
    }

    /**
     * List every transaction matching a gateway-specific query; unsupported unless overridden.
     *
     * @param  string  $query  The search query understood by the gateway.
     * @return list<TransactionSnapshot> Every matching transaction as a snapshot, newest first.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function listTransactions(string $query): array
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'listTransactions');
    }

    /**
     * List the full history of a payment by its merchant reference; unsupported unless overridden.
     *
     * @param  string  $reference  The merchant reference the transactions were sent with.
     * @return list<TransactionSnapshot> Every matching transaction as a snapshot, newest first.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function listTransactionsByReference(string $reference): array
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'listTransactionsByReference');
    }

    /**
     * Verify and parse an incoming webhook; unsupported unless overridden.
     *
     * @param  string  $rawBody  The raw, unparsed webhook request body used for signature verification.
     * @param  array<string, string|array<int, string>>  $headers  The webhook request headers, including any signature header.
     * @return WebhookEvent The verified and parsed webhook event.
     *
     * @throws UnsupportedOperationException Always, unless a concrete gateway overrides this.
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        throw UnsupportedOperationException::forOperation($this->name(), 'verifyWebhook');
    }
}
