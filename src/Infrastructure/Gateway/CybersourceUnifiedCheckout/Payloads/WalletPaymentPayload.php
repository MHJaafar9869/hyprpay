<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\WalletChargeRequest;
use Hyprpay\Payments\Domain\Enum\WalletType;

/**
 * Builds the CyberSource payments request body for a digital-wallet (Apple Pay / Google Pay) charge.
 *
 * Forwards the wallet's device-encrypted token in paymentInformation.fluidData for CyberSource to
 * decrypt, tagging the transaction with the wallet's payment-solution id (001 Apple Pay, 012 Google
 * Pay). Because CyberSource decrypts the token and derives the card network itself, the SDK never
 * handles the cleartext PAN or guesses the card type.
 */
final class WalletPaymentPayload
{
    /**
     * Build the POST /pts/v2/payments request body for a wallet charge.
     *
     * Carries processing information (capture flag, wallet payment solution), the encrypted wallet
     * token as Base64-encoded fluidData, the order amount and optional billing address, and optional
     * client reference code and device fingerprint session id.
     *
     * @param  WalletChargeRequest  $request  Wallet charge inputs (encrypted token, wallet type, amount, capture flag, optional billTo, order reference, device fingerprint id).
     * @return array<string, mixed>
     */
    public static function build(WalletChargeRequest $request): array
    {
        $solution = self::solution($request->wallet);

        $orderInformation = [
            'amountDetails' => [
                'totalAmount' => $request->money->toDecimalString(),
                'currency' => $request->money->currency,
                ...DccAmountDetails::fields($request->dcc),
            ],
        ];

        $billTo = $request->billTo?->toArray() ?? [];

        if (filled($billTo)) {
            $orderInformation['billTo'] = $billTo;
        }

        $payload = [
            'processingInformation' => [
                'capture' => $request->capture,
                'paymentSolution' => $solution['paymentSolution'],
            ],
            'paymentInformation' => [
                'fluidData' => [
                    'descriptor' => base64_encode($solution['fid']),
                    'encoding' => 'Base64',
                    'value' => base64_encode($request->encryptedToken),
                ],
            ],
            'orderInformation' => $orderInformation,
        ];

        if (filled($request->orderReference)) {
            $payload['clientReferenceInformation'] = ['code' => ClientReference::code($request->orderReference)];
        }

        $deviceInformation = DeviceInformation::fields($request->deviceFingerprintId, $request->useRawFingerprintSessionId);

        if (filled($deviceInformation)) {
            $payload['deviceInformation'] = $deviceInformation;
        }

        return $payload;
    }

    /**
     * The CyberSource payment-solution id and fluidData FID descriptor for a wallet.
     *
     * @return array{paymentSolution: string, fid: string}
     */
    private static function solution(WalletType $wallet): array
    {
        return match ($wallet) {
            WalletType::ApplePay => ['paymentSolution' => '001', 'fid' => 'FID=COMMON.APPLE.INAPP.PAYMENT'],
            WalletType::GooglePay => ['paymentSolution' => '012', 'fid' => 'FID=COMMON.GOOGLE.INAPP.PAYMENT'],
        };
    }
}
