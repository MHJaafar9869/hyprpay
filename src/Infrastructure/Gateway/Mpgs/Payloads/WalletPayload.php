<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\WalletChargeRequest;
use Hyprpay\Payments\Domain\Enum\WalletType;
use Hyprpay\Payments\Domain\ValueObject\DecryptedWalletToken;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsApiOperation;
use InvalidArgumentException;

/**
 * Builds the MPGS `PAY`/`AUTHORIZE` transaction request body for a digital-wallet (Apple Pay / Google
 * Pay) charge.
 *
 * MPGS decrypts nothing itself: it accepts the wallet token already decrypted by the merchant into its
 * network-token fields, so this builder requires a {@see DecryptedWalletToken} and rejects an encrypted
 * one. The decrypted DPAN, expiry, and online-payment cryptogram are sent as
 * `sourceOfFunds.provided.card` with a `devicePayment` block, and the wallet is flagged on the order via
 * `order.walletProvider` (`APPLE_PAY`/`GOOGLE_PAY`). PAY authorises and captures; AUTHORIZE holds funds
 * for a later capture, selected by the request's capture flag.
 */
final class WalletPayload
{
    /**
     * Build the PUT /order/{orderId}/transaction/{transactionId} wallet charge request body.
     *
     * @param  WalletChargeRequest  $request  Wallet charge inputs (decrypted token, wallet type, amount, capture flag, billing).
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When the token is not a merchant-decrypted network token.
     */
    public static function build(WalletChargeRequest $request): array
    {
        $token = $request->token;

        if (! $token instanceof DecryptedWalletToken) {
            throw new InvalidArgumentException(
                'MPGS supports only merchant-decrypted wallet tokens (DecryptedWalletToken); got '.$token::class,
            );
        }

        return array_filter([
            'apiOperation' => ($request->capture ? MpgsApiOperation::Pay : MpgsApiOperation::Authorize)->value,
            'order' => self::order($request),
            'sourceOfFunds' => MpgsPayloadParts::devicePaymentSourceOfFunds($token),
            'transaction' => ['source' => 'INTERNET'],
            'billing' => MpgsPayloadParts::billing($request->billTo),
        ], static fn (mixed $value): bool => $value !== [] && $value !== null);
    }

    /**
     * Build the `order` block carrying the amount, currency, and wallet-provider flag.
     *
     * @return array<string, string>
     */
    private static function order(WalletChargeRequest $request): array
    {
        return [
            'amount' => $request->money->toDecimalString(),
            'currency' => $request->money->currency,
            'walletProvider' => self::walletProvider($request->wallet),
        ];
    }

    /**
     * Map the wallet type onto MPGS's `order.walletProvider` value.
     */
    private static function walletProvider(WalletType $wallet): string
    {
        return match ($wallet) {
            WalletType::ApplePay => 'APPLE_PAY',
            WalletType::GooglePay => 'GOOGLE_PAY',
        };
    }
}
