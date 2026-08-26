<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Contract;

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
use Hyprpay\Payments\Domain\Enum\GatewayName;
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
 * The port that every payment gateway driver in the SDK implements.
 *
 * Defines the full set of payment operations the SDK understands (checkout,
 * charge/capture, refund/void/reversal, 3-D Secure payer authentication,
 * vaulting and stored-credential charges, transaction lookup, and webhook
 * verification). Concrete adapters implement only the operations their gateway
 * supports; the rest are typically inherited from AbstractPaymentGateway, which
 * rejects unsupported operations.
 */
interface PaymentGatewayInterface
{
    /**
     * Identify which gateway this driver represents.
     *
     * @return GatewayName The canonical gateway identifier.
     */
    public function name(): GatewayName;

    /**
     * Expose the credentials this driver was constructed with.
     *
     * @return GatewayCredentials The credentials used to authenticate against the gateway.
     */
    public function credentials(): GatewayCredentials;

    /**
     * Create a hosted/embedded checkout session for the customer to complete payment.
     *
     * @param  CheckoutSessionRequest  $request  The session parameters (amount, currency, context).
     * @return CheckoutSession The created session, including any token or redirect details.
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession;

    /**
     * Request a Dynamic Currency Conversion (DCC) rate quote for an amount and card.
     *
     * The returned quote can be threaded into a charge/capture/refund to bill the
     * cardholder in their own currency at the quoted rate.
     *
     * @param  DccRateRequest  $request  The amount, merchant currency, and card to quote.
     * @return DccQuote The quoted rate and converted amount (or an "not offered" quote).
     */
    public function requestDccRate(DccRateRequest $request): DccQuote;

    /**
     * Authorize (and optionally capture) a payment for the given request.
     *
     * @param  ChargeRequest  $request  The charge details (amount, instrument, context).
     * @return PaymentResult The normalized outcome of the charge.
     */
    public function charge(ChargeRequest $request): PaymentResult;

    /**
     * Capture funds for a previously authorized payment.
     *
     * @param  CaptureRequest  $request  The capture details (authorization reference, amount).
     * @return PaymentResult The normalized outcome of the capture.
     */
    public function capture(CaptureRequest $request): PaymentResult;

    /**
     * Refund all or part of a settled payment back to the customer.
     *
     * @param  RefundRequest  $request  The refund details (payment reference, amount).
     * @return RefundResult The normalized outcome of the refund.
     */
    public function refund(RefundRequest $request): RefundResult;

    /**
     * Void an authorized-but-not-yet-captured payment.
     *
     * @param  VoidRequest  $request  The void details (payment reference).
     * @return PaymentResult The normalized outcome of the void.
     */
    public function void(VoidRequest $request): PaymentResult;

    /**
     * Reverse an existing authorization, releasing the held funds.
     *
     * @param  ReversalRequest  $request  The reversal details (authorization reference).
     * @return PaymentResult The normalized outcome of the reversal.
     */
    public function reverseAuthorization(ReversalRequest $request): PaymentResult;

    /**
     * Set up 3-D Secure payer authentication (device data collection) before enrollment.
     *
     * @param  PayerAuthSetupRequest  $request  The setup details (instrument, order context).
     * @return PayerAuthSetupResult The setup outcome, including the device-data-collection URL and reference id.
     */
    public function setupPayerAuth(PayerAuthSetupRequest $request): PayerAuthSetupResult;

    /**
     * Begin 3-D Secure payer authentication by enrolling the card in the check.
     *
     * @param  PayerAuthEnrollRequest  $request  The enrollment details (instrument, amount, return context).
     * @return PayerAuthResult The enrollment outcome, including any challenge or redirect data.
     */
    public function enrollPayerAuth(PayerAuthEnrollRequest $request): PayerAuthResult;

    /**
     * Complete 3-D Secure payer authentication after the challenge/validation step.
     *
     * @param  ValidatePayerAuthRequest  $request  The validation details (authentication reference/response).
     * @return PayerAuthResult The validation outcome, including authentication values for the subsequent charge.
     */
    public function validatePayerAuth(ValidatePayerAuthRequest $request): PayerAuthResult;

    /**
     * Tokenize a payment instrument into the gateway vault for later reuse.
     *
     * @param  TokenizeInstrumentRequest  $request  The instrument to vault and its context.
     * @return VaultedInstrument The stored instrument reference/token.
     */
    public function vaultInstrument(TokenizeInstrumentRequest $request): VaultedInstrument;

    /**
     * Charge a stored (card-on-file) credential, honoring CIT/MIT rules.
     *
     * @param  StoredCredentialChargeRequest  $request  The stored-credential charge details (token, amount, initiator).
     * @return PaymentResult The normalized outcome of the charge.
     */
    public function chargeStoredCredential(StoredCredentialChargeRequest $request): PaymentResult;

    /**
     * Charge a digital-wallet payment token (Apple Pay / Google Pay).
     *
     * The wallet's device-encrypted token is forwarded to the gateway, which decrypts
     * it and authorizes (and optionally captures) the payment; the SDK does not decrypt
     * the token or handle the cleartext PAN itself.
     *
     * @param  WalletChargeRequest  $request  The wallet charge details (encrypted token, wallet type, amount, context).
     * @return PaymentResult The normalized outcome of the charge.
     */
    public function chargeWallet(WalletChargeRequest $request): PaymentResult;

    /**
     * Retrieve the current state of a transaction by its gateway identifier.
     *
     * @param  string  $transactionId  The gateway's transaction identifier.
     * @return TransactionSnapshot A normalized snapshot of the transaction.
     */
    public function getTransaction(string $transactionId): TransactionSnapshot;

    /**
     * Search for a transaction using a gateway-specific query.
     *
     * @param  string  $query  The search query understood by the gateway.
     * @return TransactionSnapshot|null The matching transaction snapshot, or null when nothing matches.
     */
    public function searchTransaction(string $query): ?TransactionSnapshot;

    /**
     * Find the most recent successful (authorized or captured) transaction carrying a merchant
     * reference, to reconcile before retrying a charge whose response was lost.
     *
     * Use before re-charging a stable per-attempt reference (e.g. a scheduled installment) so a charge
     * that actually settled but whose response never arrived is adopted rather than repeated.
     *
     * @param  string  $reference  The merchant reference the original charge was sent with.
     * @return TransactionSnapshot|null The most recent settled transaction for the reference, or null when none exists.
     */
    public function findSuccessfulTransactionByReference(string $reference): ?TransactionSnapshot;

    /**
     * List every transaction matching a gateway-specific query, newest first.
     *
     * Unlike searchTransaction(), which returns only the first match, this returns the whole result
     * set — use it to render a transaction's full history.
     *
     * @param  string  $query  The search query understood by the gateway.
     * @return list<TransactionSnapshot> Every matching transaction as a snapshot, newest first; empty when none match.
     */
    public function listTransactions(string $query): array;

    /**
     * List the full history of a payment by its merchant reference, newest first — every authorization,
     * capture, reversal, refund, and retry attempt sent under that reference.
     *
     * @param  string  $reference  The merchant reference the transactions were sent with.
     * @return list<TransactionSnapshot> Every matching transaction as a snapshot, newest first; empty when none match.
     */
    public function listTransactionsByReference(string $reference): array;

    /**
     * Verify an incoming webhook's authenticity and parse it into an event.
     *
     * @param  string  $rawBody  The raw, unparsed webhook request body used for signature verification.
     * @param  array<string, string|array<int, string>>  $headers  The webhook request headers, including any signature header.
     * @return WebhookEvent The verified and parsed webhook event.
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent;
}
