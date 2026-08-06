<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry;

/**
 * Builds FawryPay request/response SHA-256 signatures.
 *
 * FawryPay signs each operation by SHA-256 hashing a fixed concatenation of request
 * fields followed by the merchant's secure key (used raw, not base64-decoded). The
 * concatenation order for every operation is taken verbatim from the FawryPay
 * developer documentation. Optional fields that are absent contribute an empty string.
 */
final class FawrySignature
{
    /**
     * Signature for a card (PayUsingCC) charge.
     *
     * merchantCode + merchantRefNum + customerProfileId + paymentMethod + amount +
     * cardNumber + cardExpiryYear + cardExpiryMonth + cvv + returnUrl + secureKey
     */
    public static function card(
        string $merchantCode,
        string $merchantRefNum,
        ?string $customerProfileId,
        string $paymentMethod,
        string $amount,
        string $cardNumber,
        string $cardExpiryYear,
        string $cardExpiryMonth,
        string $cvv,
        string $returnUrl,
        string $secureKey,
    ): string {
        return self::hash(
            $merchantCode.$merchantRefNum.($customerProfileId ?? '').$paymentMethod.$amount
            .$cardNumber.$cardExpiryYear.$cardExpiryMonth.$cvv.$returnUrl.$secureKey,
        );
    }

    /**
     * Signature for a mobile-wallet (MWALLET) charge.
     *
     * merchantCode + merchantRefNum + customerProfileId + paymentMethod + amount +
     * debitMobileWalletNo + secureKey
     */
    public static function wallet(
        string $merchantCode,
        string $merchantRefNum,
        ?string $customerProfileId,
        string $paymentMethod,
        string $amount,
        string $debitMobileWalletNo,
        string $secureKey,
    ): string {
        return self::hash(
            $merchantCode.$merchantRefNum.($customerProfileId ?? '').$paymentMethod.$amount
            .$debitMobileWalletNo.$secureKey,
        );
    }

    /**
     * Signature for a pay-at-outlet (PAYATFAWRY) reference-number charge.
     *
     * merchantCode + merchantRefNum + customerProfileId + paymentMethod + amount + secureKey
     */
    public static function reference(
        string $merchantCode,
        string $merchantRefNum,
        ?string $customerProfileId,
        string $paymentMethod,
        string $amount,
        string $secureKey,
    ): string {
        return self::hash(
            $merchantCode.$merchantRefNum.($customerProfileId ?? '').$paymentMethod.$amount.$secureKey,
        );
    }

    /**
     * Signature for the Express Checkout hosted-page init request.
     *
     * merchantCode + merchantRefNum + returnUrl + (itemId + quantity + price per item) + secureKey
     *
     * @param  array<int, array<string, string>>  $chargeItems  Charge items; each must expose itemId, quantity, and price
     */
    public static function hostedInit(
        string $merchantCode,
        string $merchantRefNum,
        string $returnUrl,
        array $chargeItems,
        string $secureKey,
    ): string {
        $items = '';

        foreach ($chargeItems as $item) {
            $items .= $item['itemId'].$item['quantity'].$item['price'];
        }

        return self::hash($merchantCode.$merchantRefNum.$returnUrl.$items.$secureKey);
    }

    /**
     * Signature for a refund request.
     *
     * merchantCode + referenceNumber + refundAmount + reason (if any) + secureKey
     */
    public static function refund(
        string $merchantCode,
        string $referenceNumber,
        string $refundAmount,
        ?string $reason,
        string $secureKey,
    ): string {
        return self::hash($merchantCode.$referenceNumber.$refundAmount.($reason ?? '').$secureKey);
    }

    /**
     * Signature for a Get Payment Status V2 request.
     *
     * merchantCode + merchantRefNumber + secureKey
     */
    public static function status(string $merchantCode, string $merchantRefNumber, string $secureKey): string
    {
        return self::hash($merchantCode.$merchantRefNumber.$secureKey);
    }

    /**
     * Expected signature for a Server Notification V2 webhook (`messageSignature`).
     *
     * fawryRefNumber + merchantRefNumber + paymentAmount + orderAmount + orderStatus +
     * paymentMethod + paymentReferenceNumber (if any) + secureKey
     */
    public static function webhook(
        string $fawryRefNumber,
        string $merchantRefNumber,
        string $paymentAmount,
        string $orderAmount,
        string $orderStatus,
        string $paymentMethod,
        ?string $paymentReferenceNumber,
        string $secureKey,
    ): string {
        return self::hash(
            $fawryRefNumber.$merchantRefNumber.$paymentAmount.$orderAmount.$orderStatus
            .$paymentMethod.($paymentReferenceNumber ?? '').$secureKey,
        );
    }

    /**
     * Format a numeric amount to FawryPay's two-decimal string form (e.g. "10.00").
     *
     * Used only to reconstruct signatures over gateway-provided amounts; it does not
     * touch the SDK's own monetary values (which are carried as exact minor units).
     *
     * @param  int|float|string  $amount  The raw amount value from a FawryPay payload.
     */
    public static function amount(int|float|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
