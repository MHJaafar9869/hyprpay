<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsAgreement;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsBeneficiary;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsCheckoutOptions;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsLineItem;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsSplitPayout;

it('maps the flat options and the framed flags', function (): void {
    $options = PaytabsCheckoutOptions::fromArray([
        'tran_class' => 'moto',
        'webhook_url' => 'https://shop.test/ipn',
        'tokenise' => 2,
        'iframe' => true,
        'framed_return_top' => true,
        'framed_message_target' => 'https://shop.test',
    ]);

    expect($options->tranClass)->toBe('moto')
        ->and($options->webhookUrl)->toBe('https://shop.test/ipn')
        ->and($options->tokenise)->toBe(2)
        ->and($options->iframe)->toBeTrue()
        ->and($options->framedReturnTop)->toBeTrue()
        ->and($options->framedReturnParent)->toBeNull()
        ->and($options->framedMessageTarget)->toBe('https://shop.test');
});

it('maps a nested agreement, split payout, and line items from a legacy array', function (): void {
    $options = PaytabsCheckoutOptions::fromArray([
        'agreement' => ['agreement_description' => 'Monthly plan', 'repeat_amount' => 100, 'repeat_terms' => 3],
        'split_payout' => [
            ['entity_id' => 1001, 'entity_name' => 'Agency', 'item_total' => '50.00', 'beneficiary' => ['name' => 'Tywin', 'bank' => 'Iron Bank']],
        ],
        'line_items' => [
            ['sku' => 'sku01', 'description' => 'Flight', 'unit_cost' => '200.00', 'quantity' => 1],
        ],
    ]);

    expect($options->agreement)->toBeInstanceOf(PaytabsAgreement::class)
        ->and($options->agreement?->description)->toBe('Monthly plan')
        ->and($options->agreement?->repeatAmount)->toBe(100)
        ->and($options->splitPayout)->toHaveCount(1)
        ->and($options->splitPayout[0])->toBeInstanceOf(PaytabsSplitPayout::class)
        ->and($options->splitPayout[0]->entityName)->toBe('Agency')
        ->and($options->splitPayout[0]->beneficiary)->toBeInstanceOf(PaytabsBeneficiary::class)
        ->and($options->splitPayout[0]->beneficiary?->name)->toBe('Tywin')
        ->and($options->lineItems[0])->toBeInstanceOf(PaytabsLineItem::class)
        ->and($options->lineItems[0]->unitCost)->toBe('200.00');
});

it('round-trips the nested structures through toArray', function (): void {
    $options = new PaytabsCheckoutOptions(
        tokenise: 2,
        agreement: new PaytabsAgreement(description: 'Monthly plan', repeatAmount: 100),
        splitPayout: [new PaytabsSplitPayout(entityName: 'Agency', itemTotal: '50.00', beneficiary: new PaytabsBeneficiary(name: 'Tywin'))],
        lineItems: [new PaytabsLineItem(sku: 'sku01', unitCost: '200.00', quantity: 1)],
    );

    expect($options->toArray())->toBe([
        'tokenise' => 2,
        'agreement' => ['agreement_description' => 'Monthly plan', 'repeat_amount' => 100],
        'split_payout' => [['entity_name' => 'Agency', 'item_total' => '50.00', 'beneficiary' => ['name' => 'Tywin']]],
        'line_items' => [['sku' => 'sku01', 'unit_cost' => '200.00', 'quantity' => 1]],
    ]);
});

it('drops a false iframe and unset fields from toArray', function (): void {
    expect((new PaytabsCheckoutOptions(webhookUrl: 'https://shop.test/ipn'))->toArray())
        ->toBe(['webhook_url' => 'https://shop.test/ipn']);
});
