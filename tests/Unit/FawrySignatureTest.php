<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawrySignature;

it('signs a reference (PAYATFAWRY) charge in the documented field order', function (): void {
    $signature = FawrySignature::reference('MC', 'REF1', null, 'PAYATFAWRY', '100.00', 'sec');

    expect($signature)->toBe(hash('sha256', 'MC'.'REF1'.''.'PAYATFAWRY'.'100.00'.'sec'));
});

it('signs a mobile-wallet charge including the customer profile and wallet number', function (): void {
    $signature = FawrySignature::wallet('MC', 'REF1', 'CUST', 'MWALLET', '50.00', '01000000000', 'sec');

    expect($signature)->toBe(hash('sha256', 'MC'.'REF1'.'CUST'.'MWALLET'.'50.00'.'01000000000'.'sec'));
});

it('signs a card charge including card data and return url', function (): void {
    $signature = FawrySignature::card('MC', 'REF1', null, 'PayUsingCC', '100.00', '4111', '30', '12', '123', 'https://ret', 'sec');

    expect($signature)->toBe(hash('sha256', 'MC'.'REF1'.''.'PayUsingCC'.'100.00'.'4111'.'30'.'12'.'123'.'https://ret'.'sec'));
});

it('signs a hosted-init request over the concatenated charge items', function (): void {
    $items = [['itemId' => 'i1', 'quantity' => '1', 'price' => '100.00']];

    $signature = FawrySignature::hostedInit('MC', 'REF1', 'https://ret', $items, 'sec');

    expect($signature)->toBe(hash('sha256', 'MC'.'REF1'.'https://ret'.'i1'.'1'.'100.00'.'sec'));
});

it('signs an instalment (CARD) charge including the plan id, before the secret', function (): void {
    $signature = FawrySignature::installmentCard('MC', 'REF1', null, 'CARD', '1200.00', '4111', '30', '12', '123', 'PLAN-12', 'sec');

    expect($signature)->toBe(hash('sha256', 'MC'.'REF1'.''.'CARD'.'1200.00'.'4111'.'30'.'12'.'123'.'PLAN-12'.'sec'));
});

it('signs a capture over the reference, amount, merchant code and secret', function (): void {
    expect(FawrySignature::capture('REF1', '75.00', 'MC', 'sec'))
        ->toBe(hash('sha256', 'REF1'.'75.00'.'MC'.'sec'))
        ->and(FawrySignature::capture('REF1', null, 'MC', 'sec'))
        ->toBe(hash('sha256', 'REF1'.''.'MC'.'sec'));
});

it('signs a cancel-authorization over the reference, merchant code and secret', function (): void {
    expect(FawrySignature::cancelAuthorization('REF1', 'MC', 'sec'))
        ->toBe(hash('sha256', 'REF1'.'MC'.'sec'));
});

it('signs a refund with and without a reason', function (): void {
    expect(FawrySignature::refund('MC', 'FREF', '25.00', 'duplicate', 'sec'))
        ->toBe(hash('sha256', 'MC'.'FREF'.'25.00'.'duplicate'.'sec'))
        ->and(FawrySignature::refund('MC', 'FREF', '25.00', null, 'sec'))
        ->toBe(hash('sha256', 'MC'.'FREF'.'25.00'.''.'sec'));
});

it('signs a status inquiry over merchant code, reference and secret', function (): void {
    expect(FawrySignature::status('MC', 'REF1', 'sec'))->toBe(hash('sha256', 'MC'.'REF1'.'sec'));
});

it('signs a webhook in the Server Notification V2 field order', function (): void {
    $signature = FawrySignature::webhook('FREF', 'REF1', '100.00', '100.00', 'PAID', 'PayUsingCC', 'PREF', 'sec');

    expect($signature)->toBe(hash('sha256', 'FREF'.'REF1'.'100.00'.'100.00'.'PAID'.'PayUsingCC'.'PREF'.'sec'));
});

it('formats amounts to two decimals for signing', function (): void {
    expect(FawrySignature::amount(100))->toBe('100.00')
        ->and(FawrySignature::amount('50.5'))->toBe('50.50')
        ->and(FawrySignature::amount(1000))->toBe('1000.00');
});
