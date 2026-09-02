<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\BinLookupRequest;
use Hyprpay\Payments\Domain\Enum\BinLookupStatus;
use Hyprpay\Payments\Domain\Enum\CardFundingSource;
use Hyprpay\Payments\Domain\Enum\CardPlatform;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Result\BinLookupResult;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: CybersourceUnifiedCheckoutGateway, 1: FakeHttpClient}
 */
function binGateway(): array
{
    $http = new FakeHttpClient;

    return [new CybersourceUnifiedCheckoutGateway(testCredentials(), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function binBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('resolves a card from a raw PAN into brand, funding, issuer, and capabilities', function (): void {
    [$gateway, $http] = binGateway();
    $http->queueJson([
        'id' => 'bin_1',
        'status' => 'COMPLETED',
        'paymentAccountInformation' => [
            'card' => [
                'type' => '001',
                'brandName' => 'VISA',
                'currency' => 'USD',
                'maxLength' => '16',
                'credentialType' => 'PAN',
            ],
            'features' => [
                'accountFundingSource' => 'CREDIT',
                'cardPlatform' => 'CORPORATE',
                'cardProduct' => 'Visa Infinite',
                'threeDSSupport' => true,
                'siEligible' => true,
                'emiEligible' => false,
                'ecomEnabled' => true,
                'commercialCardLevel2' => true,
                'commercialCardLevel3' => false,
            ],
        ],
        'issuerInformation' => [
            'name' => 'Test Bank',
            'country' => 'US',
            'accountPrefix' => '411111',
            'phoneNumber' => '+1-800-000-0000',
        ],
    ]);

    $bin = $gateway->lookupBin(new BinLookupRequest(cardNumber: '4111111111111111', orderReference: 'ORDER-1'));

    $request = $http->lastRequest();

    expect($bin->isResolved())->toBeTrue()
        ->and($bin->status)->toBe(BinLookupStatus::Completed)
        ->and($bin->brandName)->toBe('VISA')
        ->and($bin->cardType)->toBe('001')
        ->and($bin->currency)->toBe('USD')
        ->and($bin->maxLength)->toBe(16)
        ->and($bin->credentialType)->toBe('PAN')
        ->and($bin->fundingSource)->toBe(CardFundingSource::Credit)
        ->and($bin->platform)->toBe(CardPlatform::Corporate)
        ->and($bin->platform?->isCommercial())->toBeTrue()
        ->and($bin->cardProduct)->toBe('Visa Infinite')
        ->and($bin->issuerName)->toBe('Test Bank')
        ->and($bin->issuerCountry)->toBe('US')
        ->and($bin->accountPrefix)->toBe('411111')
        ->and($bin->supports3ds())->toBeTrue()
        ->and($bin->supportsRecurring())->toBeTrue()
        ->and($bin->supportsInstallments())->toBeFalse()
        ->and($bin->supportsEcommerce())->toBeTrue()
        ->and($bin->qualifiesForCommercialRates())->toBeTrue()
        ->and($request?->method)->toBe('POST')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/bin/v1/binlookup')
        ->and($request?->header('Signature'))->not->toBeNull()
        ->and(binBody($http)['paymentInformation']['card'])->toBe(['number' => '4111111111111111'])
        ->and(binBody($http)['clientReferenceInformation']['code'])->toBe('ORDER-1');
});

it('looks a card up by transient token without sending any card data', function (): void {
    [$gateway, $http] = binGateway();

    $gateway->lookupBin(BinLookupRequest::forTransientToken('header.payload.sig'));

    $body = binBody($http);

    expect($body['tokenInformation'])->toBe(['transientTokenJwt' => 'header.payload.sig'])
        ->and($body)->not->toHaveKey('paymentInformation')
        ->and(json_encode($body))->not->toContain('number');
});

it('looks a card up by its jti or by a vault reference', function (): void {
    [$gateway, $http] = binGateway();

    $gateway->lookupBin(new BinLookupRequest(transientTokenJti: 'a1b2c3'));
    expect(binBody($http)['tokenInformation'])->toBe(['jti' => 'a1b2c3']);

    $gateway->lookupBin(new BinLookupRequest(
        customerId: 'cust_1', paymentInstrumentId: 'pi_1', instrumentIdentifierId: 'ii_1',
    ));

    expect(binBody($http)['paymentInformation'])->toBe([
        'customer' => ['id' => 'cust_1'],
        'paymentInstrument' => ['id' => 'pi_1'],
        'instrumentIdentifier' => ['id' => 'ii_1'],
    ]);
});

it('flags an ambiguous or unknown BIN as unresolved rather than as a decline', function (string $status): void {
    [$gateway, $http] = binGateway();
    $http->queueJson(['status' => $status, 'paymentAccountInformation' => ['card' => ['brandName' => 'VISA']]]);

    $bin = $gateway->lookupBin(new BinLookupRequest(cardNumber: '4111111111111111'));

    expect($bin->isResolved())->toBeFalse()
        ->and($bin->status?->isResolved())->toBeFalse();
})->with(['MULTIPLE', 'NO MATCH']);

it('detects a prepaid card as one that can partially approve', function (): void {
    [$gateway, $http] = binGateway();
    $http->queueJson(['status' => 'COMPLETED', 'paymentAccountInformation' => ['features' => [
        'accountFundingSource' => 'PREPAID', 'accountFundingSourceSubType' => 'Reloadable',
    ]]]);

    $bin = $gateway->lookupBin(new BinLookupRequest(cardNumber: '4111111111111111'));

    expect($bin->fundingSource)->toBe(CardFundingSource::Prepaid)
        ->and($bin->fundingSource?->canPartiallyApprove())->toBeTrue()
        ->and($bin->fundingSubType)->toBe('Reloadable')
        ->and(CardFundingSource::Credit->canPartiallyApprove())->toBeFalse();
});

it('reads a BIN feature the SDK does not model, and treats an absent one as off', function (): void {
    [$gateway, $http] = binGateway();
    $http->queueJson(['status' => 'COMPLETED', 'paymentAccountInformation' => ['features' => [
        'fleetCard' => true, 'productId' => 'Q4',
    ]]]);

    $bin = $gateway->lookupBin(new BinLookupRequest(cardNumber: '4111111111111111'));

    expect($bin->hasFeature('fleetCard'))->toBeTrue()
        ->and($bin->feature('productId'))->toBe('Q4')
        ->and($bin->feature('nothingLikeThis', 'fallback'))->toBe('fallback')
        ->and($bin->hasFeature('threeDSSupport'))->toBeFalse()
        ->and($bin->supports3ds())->toBeFalse();
});

it('throws when the bin lookup service rejects the request', function (): void {
    [$gateway, $http] = binGateway();
    $http->queueJson(['message' => 'Invalid credential'], 400);

    expect(fn (): BinLookupResult => $gateway->lookupBin(new BinLookupRequest(cardNumber: 'x')))
        ->toThrow(GatewayRequestException::class);
});
