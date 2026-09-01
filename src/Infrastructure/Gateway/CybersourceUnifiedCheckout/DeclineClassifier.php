<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout;

use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\SubscriptionResult;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums\CybersourceTransactionStatus;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Classifies a declined CyberSource authorization so a caller retrying a charge (e.g. a scheduled
 * installment or subscription rebill) can retry intelligently instead of blindly re-charging. It
 * reads a Recurring Billing response the same way, so a subscription CyberSource refused at create
 * time is triaged with the same rules as a one-off charge.
 *
 * Retryability is read from two authoritative CyberSource signals:
 *  - `errorInformation.reason` — the decline cause (INSUFFICIENT_FUND, EXPIRED_CARD, …).
 *  - `processorInformation.merchantAdvice.code` — the card networks' own retry guidance (Visa `1` =
 *    issuer never approves; Mastercard `01`/`03`/`04`/`21`/`99` = do not try again / account changed).
 *
 * A reason or advice code in the permanent set means the stored credential can never approve, so the
 * caller should stop retrying rather than burn its retry budget. Anything unrecognised is treated as
 * transient (retryable) so a merely-unclassified decline never prematurely gives up.
 *
 * This reads the raw gateway response the SDK already surfaces on {@see PaymentResult::$raw} or
 * {@see SubscriptionResult::$raw}; it does not throw and has no side effects.
 */
final class DeclineClassifier
{
    /**
     * `errorInformation.reason` values where retrying the same stored credential cannot succeed.
     *
     * @var list<string>
     */
    private const PERMANENT_REASONS = [
        'EXPIRED_CARD',
        'STOLEN_LOST_CARD',
        'INVALID_ACCOUNT',
        'UNAUTHORIZED_CARD',
        'BLOCKED_BY_CARDHOLDER',
        'SUSPENDED_ACCOUNT',
        'INVALID_MERCHANT_CONFIGURATION',
        'DECISION_PROFILE_REJECT',
    ];

    /**
     * `processorInformation.merchantAdvice.code` values that instruct the merchant not to retry:
     * Visa `1` (issuer never approves); Mastercard `01` (new account information available), `03`/`99`
     * (do not try again), `04` (token requirements not met / stop), `21` (do not honour).
     *
     * @var list<string>
     */
    private const PERMANENT_ADVICE_CODES = ['1', '01', '03', '04', '21', '99'];

    /**
     * Classify the decline carried by a result's raw gateway response.
     *
     * Accepts either a charge outcome or a subscription outcome: a Recurring Billing create that comes
     * back DECLINED carries the same `errorInformation.reason` and `processorInformation.merchantAdvice.code`
     * signals as a declined authorization, so a permanent refusal (an expired or invalid card behind the
     * vaulted token) is told apart from a transient one before the subscription is retried or the customer
     * is asked for a new card.
     *
     * @param  PaymentResult|SubscriptionResult  $result  The declined outcome to triage.
     */
    public static function fromResult(PaymentResult|SubscriptionResult $result): DeclineOutcome
    {
        return self::classify($result->raw);
    }

    /**
     * @param  array<string, mixed>  $response  Decoded CyberSource payment response body.
     */
    public static function classify(array $response): DeclineOutcome
    {
        $status = strtoupper(Value::string(data_get($response, 'status')));
        $reasonCode = strtoupper(Value::string(data_get($response, 'errorInformation.reason')));
        $adviceCode = Value::string(data_get($response, 'processorInformation.merchantAdvice.code'));

        $reason = match (true) {
            $reasonCode !== '' => $reasonCode,
            $status !== '' => $status,
            default => 'UNKNOWN',
        };

        $isPermanent = in_array($reasonCode, self::PERMANENT_REASONS, true)
            || in_array($adviceCode, self::PERMANENT_ADVICE_CODES, true);

        return new DeclineOutcome(
            reason: $reason,
            isPermanent: $isPermanent,
            isPartialAuthorization: $status === CybersourceTransactionStatus::PartialAuthorized->value,
            transactionId: Value::nullableString(data_get($response, 'id')),
            status: $status,
        );
    }
}
