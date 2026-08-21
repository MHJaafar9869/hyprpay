<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\WalletChargeRequest;
use Hyprpay\Payments\Domain\Enum\WalletType;
use Hyprpay\Payments\Domain\ValueObject\DecryptedWalletToken;
use Hyprpay\Payments\Domain\ValueObject\EncryptedWalletToken;
use Hyprpay\Payments\Domain\ValueObject\WalletToken;

/**
 * Builds the CyberSource payments request body for a digital-wallet (Apple Pay / Google Pay) charge.
 *
 * Supports both CyberSource wallet models, tagged with the wallet's payment-solution id (001 Apple
 * Pay, 012 Google Pay): a merchant-decrypted network token ({@see DecryptedWalletToken}) sent as
 * paymentInformation.tokenizedCard with transactionType 1 (the canonical in-app wallet path), or an
 * encrypted token ({@see EncryptedWalletToken}) forwarded as paymentInformation.fluidData for
 * CyberSource to decrypt. Either way the SDK never decrypts the token itself.
 */
final class WalletPaymentPayload
{
    /**
     * Build the POST /pts/v2/payments request body for a wallet charge.
     *
     * Carries processing information (capture flag, wallet payment solution), the wallet instrument
     * (tokenizedCard or fluidData depending on the token shape), the order amount and optional billing
     * address, and optional client reference code and device fingerprint session id.
     *
     * @param  WalletChargeRequest  $request  Wallet charge inputs (wallet token, wallet type, amount, capture flag, optional billTo, order reference, device fingerprint id).
     * @return array<string, mixed>
     */
    public static function build(WalletChargeRequest $request): array
    {
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
                'paymentSolution' => self::paymentSolution($request->wallet),
            ],
            'orderInformation' => $orderInformation,
            ...self::instrument($request->token, $request->wallet),
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
     * The paymentInformation (and any consumerAuthenticationInformation) fields for the wallet token.
     *
     * @return array<string, mixed>
     */
    private static function instrument(WalletToken $token, WalletType $wallet): array
    {
        return match (true) {
            $token instanceof DecryptedWalletToken => self::tokenizedCard($token),
            $token instanceof EncryptedWalletToken => self::fluidData($token, $wallet),
            default => throw new \InvalidArgumentException('Unsupported wallet token type: '.$token::class),
        };
    }

    /**
     * The tokenizedCard (network-token) fields for a merchant-decrypted wallet token, plus the ECI
     * when present. transactionType 1 marks an in-app wallet tokenization; the card type is included
     * when known so CyberSource processes the correct network.
     *
     * @return array<string, mixed>
     */
    private static function tokenizedCard(DecryptedWalletToken $token): array
    {
        $tokenizedCard = [
            'number' => $token->number,
            'expirationMonth' => $token->expiryMonth,
            'expirationYear' => $token->expiryYear,
            'cryptogram' => $token->cryptogram,
            'transactionType' => '1',
        ];

        if (filled($token->cardType)) {
            $tokenizedCard['type'] = $token->cardType;
        }

        $fields = ['paymentInformation' => ['tokenizedCard' => $tokenizedCard]];

        if (filled($token->eci)) {
            $fields['consumerAuthenticationInformation'] = ['eciRaw' => $token->eci];
        }

        return $fields;
    }

    /**
     * The fluidData fields for an encrypted wallet token CyberSource will decrypt: the Base64-encoded
     * token value under the wallet's Base64-encoded FID descriptor.
     *
     * @return array<string, mixed>
     */
    private static function fluidData(EncryptedWalletToken $token, WalletType $wallet): array
    {
        return [
            'paymentInformation' => [
                'fluidData' => [
                    'descriptor' => base64_encode(self::descriptor($wallet)),
                    'encoding' => 'Base64',
                    'value' => base64_encode($token->value),
                ],
            ],
        ];
    }

    /**
     * CyberSource digital payment solution id for the wallet (001 Apple Pay, 012 Google Pay).
     */
    private static function paymentSolution(WalletType $wallet): string
    {
        return match ($wallet) {
            WalletType::ApplePay => '001',
            WalletType::GooglePay => '012',
        };
    }

    /**
     * CyberSource fluidData FID descriptor (pre-Base64) for the wallet.
     */
    private static function descriptor(WalletType $wallet): string
    {
        return match ($wallet) {
            WalletType::ApplePay => 'FID=COMMON.APPLE.INAPP.PAYMENT',
            WalletType::GooglePay => 'FID=COMMON.GOOGLE.INAPP.PAYMENT',
        };
    }
}
