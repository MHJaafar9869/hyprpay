<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\CybersourceCardNetwork;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\BinLookupResult;
use Hyprpay\Payments\Domain\Result\OrchestratedPaymentResult;
use Hyprpay\Payments\Domain\Result\PaymentInstrument;

it('resolves cybersource numeric card-type codes to a network', function (string $code, CybersourceCardNetwork $network): void {
    expect(CybersourceCardNetwork::fromCyberSourceCode($code))->toBe($network)
        ->and(CybersourceCardNetwork::resolve($code))->toBe($network);
})->with([
    ['001', CybersourceCardNetwork::Visa],
    ['002', CybersourceCardNetwork::Mastercard],
    ['003', CybersourceCardNetwork::Amex],
    ['004', CybersourceCardNetwork::Discover],
    ['005', CybersourceCardNetwork::DinersClub],
    ['007', CybersourceCardNetwork::Jcb],
    ['024', CybersourceCardNetwork::Maestro],
    ['042', CybersourceCardNetwork::Maestro],
    ['054', CybersourceCardNetwork::Elo],
    ['060', CybersourceCardNetwork::Mada],
    ['062', CybersourceCardNetwork::Cup],
    ['067', CybersourceCardNetwork::Meeza],
    ['081', CybersourceCardNetwork::Jaywan],
]);

it('resolves brand names however the gateway spelled them', function (string $name, CybersourceCardNetwork $network): void {
    expect(CybersourceCardNetwork::fromBrandName($name))->toBe($network)
        ->and(CybersourceCardNetwork::resolve($name))->toBe($network);
})->with([
    ['VISA', CybersourceCardNetwork::Visa],
    ['visa', CybersourceCardNetwork::Visa],
    ['MASTERCARD', CybersourceCardNetwork::Mastercard],
    ['mastercard', CybersourceCardNetwork::Mastercard],
    ['AMEX', CybersourceCardNetwork::Amex],
    ['AMERICAN EXPRESS', CybersourceCardNetwork::Amex],
    ['american express', CybersourceCardNetwork::Amex],
    ['DINERS CLUB', CybersourceCardNetwork::DinersClub],
    ['diners', CybersourceCardNetwork::DinersClub],
    ['CHINA UNION PAY', CybersourceCardNetwork::Cup],
    ['Visa Electron', CybersourceCardNetwork::Visa],
]);

it('returns null for a network it does not model rather than guessing', function (): void {
    expect(CybersourceCardNetwork::fromCyberSourceCode('061'))->toBeNull()   // RuPay
        ->and(CybersourceCardNetwork::fromCyberSourceCode('000'))->toBeNull() // unsupported
        ->and(CybersourceCardNetwork::fromCyberSourceCode(null))->toBeNull()
        ->and(CybersourceCardNetwork::fromBrandName(''))->toBeNull()
        ->and(CybersourceCardNetwork::fromBrandName(null))->toBeNull()
        ->and(CybersourceCardNetwork::resolve('something new'))->toBeNull();
});

it('gives the same network from a bin lookup, a vaulted card, and a completed payment', function (): void {
    $bin = new BinLookupResult(cardType: '002', brandName: 'MASTERCARD');
    $vaulted = new PaymentInstrument(cardType: '002');
    $orchestrated = new OrchestratedPaymentResult(success: true, status: PaymentStatus::Captured, cardBrand: 'mastercard');

    expect($bin->network())->toBe(CybersourceCardNetwork::Mastercard)
        ->and($vaulted->network())->toBe(CybersourceCardNetwork::Mastercard)
        ->and($orchestrated->network())->toBe(CybersourceCardNetwork::Mastercard);
});

it('falls back to the brand name when a bin lookup reports no type code', function (): void {
    expect((new BinLookupResult(brandName: 'VISA'))->network())->toBe(CybersourceCardNetwork::Visa)
        ->and((new BinLookupResult)->network())->toBeNull()
        ->and((new PaymentInstrument)->network())->toBeNull();
});

it('reports no network for a wallet payment, which carries no card brand', function (): void {
    $wallet = new OrchestratedPaymentResult(success: true, status: PaymentStatus::Captured, isWallet: true);

    expect($wallet->network())->toBeNull();
});
