<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads;

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\Enums\FawryPaymentMethod;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawrySignature;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Builds the FawryPay charge request bodies for the direct payment methods.
 *
 * Targets POST /ECommerceWeb/Fawry/payments/charge with one method per builder:
 * card (PayUsingCC, 3-D Secure), mobile wallet (MWALLET), and pay-at-outlet by
 * reference number (PAYATFAWRY). Each method shares a common base body and appends
 * its method-specific fields and matching signature.
 *
 * The merchant reference is taken verbatim from the request's order reference (no random
 * or time-based suffix), so identical retries are treated idempotently by FawryPay.
 */
final class FawryChargePayload
{
    /**
     * Build a card (PayUsingCC) 3-D Secure charge body.
     *
     * Card details are read from the request options bag under `card` as
     * [number, expiryYear, expiryMonth, cvv].
     *
     * @return array<string, mixed>
     */
    public static function card(CheckoutSessionRequest $request, GatewayCredentials $credentials): array
    {
        $amount = $request->money->toDecimalString();
        $returnUrl = $request->returnUrl ?? '';
        $card = is_array($request->options['card'] ?? null) ? $request->options['card'] : [];

        $number = Value::string($card['number'] ?? null);
        $expiryYear = Value::string($card['expiryYear'] ?? null);
        $expiryMonth = Value::string($card['expiryMonth'] ?? null);
        $cvv = Value::string($card['cvv'] ?? null);

        $body = self::base($request, $credentials, FawryPaymentMethod::Card->value, $amount);
        $body['cardNumber'] = $number;
        $body['cardExpiryYear'] = $expiryYear;
        $body['cardExpiryMonth'] = $expiryMonth;
        $body['cvv'] = $cvv;
        $body['enable3DS'] = true;
        $body['authCaptureModePayment'] = false;
        $body['returnUrl'] = $returnUrl;
        $body['signature'] = FawrySignature::card(
            $credentials->merchantId,
            FawryFields::merchantRefNum($request),
            FawryFields::customerProfileId($request),
            FawryPaymentMethod::Card->value,
            $amount,
            $number,
            $expiryYear,
            $expiryMonth,
            $cvv,
            $returnUrl,
            $credentials->sharedSecret,
        );

        return $body;
    }

    /**
     * Build a mobile-wallet (MWALLET) charge body.
     *
     * The wallet number is read from the request options bag under `wallet_number`.
     *
     * @return array<string, mixed>
     */
    public static function wallet(CheckoutSessionRequest $request, GatewayCredentials $credentials): array
    {
        $amount = $request->money->toDecimalString();
        $walletNumber = Value::string($request->options['wallet_number'] ?? null);

        $body = self::base($request, $credentials, FawryPaymentMethod::MobileWallet->value, $amount);
        $body['debitMobileWalletNo'] = $walletNumber;
        $body['signature'] = FawrySignature::wallet(
            $credentials->merchantId,
            FawryFields::merchantRefNum($request),
            FawryFields::customerProfileId($request),
            FawryPaymentMethod::MobileWallet->value,
            $amount,
            $walletNumber,
            $credentials->sharedSecret,
        );

        return $body;
    }

    /**
     * Build a pay-at-outlet (PAYATFAWRY) reference-number charge body.
     *
     * @return array<string, mixed>
     */
    public static function reference(CheckoutSessionRequest $request, GatewayCredentials $credentials): array
    {
        $amount = $request->money->toDecimalString();

        $body = self::base($request, $credentials, FawryPaymentMethod::PayAtFawry->value, $amount);
        $body['signature'] = FawrySignature::reference(
            $credentials->merchantId,
            FawryFields::merchantRefNum($request),
            FawryFields::customerProfileId($request),
            FawryPaymentMethod::PayAtFawry->value,
            $amount,
            $credentials->sharedSecret,
        );

        return $body;
    }

    /**
     * Build the charge fields common to every direct payment method.
     *
     * @return array<string, mixed>
     */
    private static function base(
        CheckoutSessionRequest $request,
        GatewayCredentials $credentials,
        string $paymentMethod,
        string $amount,
    ): array {
        $body = [
            'merchantCode' => $credentials->merchantId,
            'merchantRefNum' => FawryFields::merchantRefNum($request),
            'paymentMethod' => $paymentMethod,
            'amount' => $amount,
            'currencyCode' => $request->money->currency,
            'chargeItems' => FawryFields::chargeItems($request, $amount),
            'description' => FawryFields::description($request),
            'language' => FawryFields::language($request, $credentials),
        ];

        $email = FawryFields::customerEmail($request);

        if (filled($email)) {
            $body['customerEmail'] = $email;
        }

        $mobile = FawryFields::customerMobile($request);

        if (filled($mobile)) {
            $body['customerMobile'] = $mobile;
        }

        $name = FawryFields::customerName($request);

        if (filled($name)) {
            $body['customerName'] = $name;
        }

        $profileId = FawryFields::customerProfileId($request);

        if (filled($profileId)) {
            $body['customerProfileId'] = $profileId;
        }

        return $body;
    }
}
