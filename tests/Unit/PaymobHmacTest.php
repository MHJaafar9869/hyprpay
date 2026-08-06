<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobHmac;

/**
 * @return array<string, mixed>
 */
function paymobTransactionObject(): array
{
    return [
        'amount_cents' => 10000,
        'created_at' => '2026-08-05T10:00:00',
        'currency' => 'EGP',
        'error_occured' => false,
        'has_parent_transaction' => false,
        'id' => 123,
        'integration_id' => 111,
        'is_3d_secure' => true,
        'is_auth' => false,
        'is_capture' => false,
        'is_refunded' => false,
        'is_standalone_payment' => true,
        'is_voided' => false,
        'order' => ['id' => 456],
        'owner' => 789,
        'pending' => false,
        'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
        'success' => true,
    ];
}

it('computes the transaction HMAC over the documented field order', function (): void {
    $concatenated = '10000'.'2026-08-05T10:00:00'.'EGP'.'false'.'false'.'123'.'111'.'true'
        .'false'.'false'.'false'.'true'.'false'.'456'.'789'.'false'.'2346'.'MasterCard'.'card'.'true';

    expect(PaymobHmac::forTransaction(paymobTransactionObject(), 'hmac_secret'))
        ->toBe(hash_hmac('sha512', $concatenated, 'hmac_secret'));
});

it('renders booleans as true/false and missing fields as empty when hashing', function (): void {
    $object = paymobTransactionObject();
    unset($object['source_data']);

    $concatenated = '10000'.'2026-08-05T10:00:00'.'EGP'.'false'.'false'.'123'.'111'.'true'
        .'false'.'false'.'false'.'true'.'false'.'456'.'789'.'false'.''.''.''.'true';

    expect(PaymobHmac::forTransaction($object, 'hmac_secret'))
        ->toBe(hash_hmac('sha512', $concatenated, 'hmac_secret'));
});
