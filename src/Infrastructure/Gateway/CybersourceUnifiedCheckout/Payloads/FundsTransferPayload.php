<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\PullFundsRequest;
use Hyprpay\Payments\Domain\Command\PushFundsRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Builds the CyberSource funds-transfer request bodies — push (OCT) and pull (AFT).
 *
 * The two are near-mirrors: same amount, same business application id, same party blocks. They
 * differ in which side of the transfer carries the card — the recipient's for a push, the sender's
 * for a pull — and in the sender-only identification fields, which the recipient block does not
 * accept.
 */
final class FundsTransferPayload
{
    /**
     * Build the POST /pts/v1/push-funds-transfer request body — credit the recipient's card.
     *
     * @param  PushFundsRequest  $request  The transfer to push.
     * @return array<string, mixed>
     */
    public static function push(PushFundsRequest $request): array
    {
        $processing = array_filter([
            'businessApplicationId' => $request->businessApplicationId?->value,
            'purposeOfPayment' => $request->purposeOfPayment,
        ], filled(...));

        return self::body(
            reference: ClientReference::block($request->orderReference, $request->merchantTransactionId),
            money: $request->money,
            processing: $processing,
            sender: $request->sender?->toArray(true) ?? [],
            recipient: [
                ...($request->recipient?->toArray() ?? []),
                ...self::paymentInformation(
                    self::card($request->cardNumber, $request->expirationMonth, $request->expirationYear),
                    $request->paymentInstrumentId,
                ),
            ],
        );
    }

    /**
     * Build the POST /pts/v1/pull-funds-transfer request body — debit the sender's card.
     *
     * @param  PullFundsRequest  $request  The transfer to fund.
     * @return array<string, mixed>
     */
    public static function pull(PullFundsRequest $request): array
    {
        $processing = array_filter([
            'businessApplicationId' => $request->businessApplicationId?->value,
        ], filled(...));

        return self::body(
            reference: ClientReference::block($request->orderReference, $request->merchantTransactionId),
            money: $request->money,
            processing: $processing,
            sender: [
                ...$request->sender?->toArray(true) ?? [],
                ...self::paymentInformation(
                    self::card($request->cardNumber, $request->expirationMonth, $request->expirationYear, $request->securityCode),
                    $request->paymentInstrumentId,
                ),
            ],
            recipient: $request->recipient?->toArray() ?? [],
        );
    }

    /**
     * Assemble a transfer body, dropping every block the caller left empty.
     *
     * @param  array<string, string>  $reference
     * @param  array<string, string>  $processing
     * @param  array<string, mixed>  $sender
     * @param  array<string, mixed>  $recipient
     * @return array<string, mixed>
     */
    private static function body(array $reference, Money $money, array $processing, array $sender, array $recipient): array
    {
        return array_filter([
            'clientReferenceInformation' => $reference,
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $money->toDecimalString(),
                    'currency' => $money->currency,
                ],
            ],
            'processingInformation' => $processing,
            'senderInformation' => $sender,
            'recipientInformation' => $recipient,
        ], static fn (array $block): bool => $block !== []);
    }

    /**
     * The `card` fragment for a raw card, or an empty array when none was supplied.
     *
     * @return array<string, string>
     */
    private static function card(?string $number, ?string $month, ?string $year, ?string $securityCode = null): array
    {
        if (blank($number)) {
            return [];
        }

        return array_filter([
            'number' => $number,
            'expirationMonth' => $month,
            'expirationYear' => $year,
            'securityCode' => $securityCode,
        ], filled(...));
    }

    /**
     * A party's `paymentInformation` block — a raw card, a vault instrument, or neither.
     *
     * @param  array<string, string>  $card
     * @return array<string, mixed>
     */
    private static function paymentInformation(array $card, ?string $paymentInstrumentId): array
    {
        $payment = array_filter([
            'card' => $card,
            'paymentInstrument' => filled($paymentInstrumentId) ? ['id' => $paymentInstrumentId] : null,
        ], filled(...));

        return $payment === [] ? [] : ['paymentInformation' => $payment];
    }
}
