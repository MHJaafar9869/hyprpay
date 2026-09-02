<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\UpdatePaymentInstrumentRequest;
use Hyprpay\Payments\Domain\Enum\PaymentInstrumentState;
use Hyprpay\Payments\Domain\Result\PaymentInstrument;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: CybersourceUnifiedCheckoutGateway, 1: FakeHttpClient}
 */
function vaultGateway(): array
{
    $http = new FakeHttpClient;

    return [new CybersourceUnifiedCheckoutGateway(testCredentials(), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function vaultBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('reads a stored payment instrument back, including the masked number from the linked identifier', function (): void {
    [$gateway, $http] = vaultGateway();
    $http->queueJson([
        'id' => 'pi_1',
        'default' => true,
        'state' => 'ACTIVE',
        'card' => ['expirationMonth' => '12', 'expirationYear' => '2030', 'type' => '001'],
        'instrumentIdentifier' => ['id' => 'ii_1'],
        '_embedded' => ['instrumentIdentifier' => ['card' => ['number' => '411111XXXXXX1111']]],
        'billTo' => ['country' => 'EG'],
    ]);

    $instrument = $gateway->getPaymentInstrument('cust_1', 'pi_1');

    expect($instrument->id)->toBe('pi_1')
        ->and($instrument->customerId)->toBe('cust_1')
        ->and($instrument->instrumentIdentifierId)->toBe('ii_1')
        ->and($instrument->state)->toBe(PaymentInstrumentState::Active)
        ->and($instrument->state?->isChargeable())->toBeTrue()
        ->and($instrument->isDefault)->toBeTrue()
        ->and($instrument->expiry())->toBe('12/2030')
        ->and($instrument->maskedNumber)->toBe('411111XXXXXX1111')
        ->and($instrument->billTo)->toBe(['country' => 'EG'])
        ->and($http->lastRequest()?->method)->toBe('GET')
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/tms/v2/customers/cust_1/payment-instruments/pi_1');
});

it('flags a closed account as not chargeable', function (): void {
    [$gateway, $http] = vaultGateway();
    $http->queueJson(['id' => 'pi_1', 'state' => 'CLOSED']);

    $instrument = $gateway->getPaymentInstrument('cust_1', 'pi_1');

    expect($instrument->state)->toBe(PaymentInstrumentState::Closed)
        ->and($instrument->state?->isChargeable())->toBeFalse();
});

it('detects an expired stored card against a fixed moment', function (): void {
    $march2026 = mktime(12, 0, 0, 3, 15, 2026);

    expect((new PaymentInstrument(expirationMonth: '02', expirationYear: '2026'))->isExpired($march2026))->toBeTrue()
        ->and((new PaymentInstrument(expirationMonth: '03', expirationYear: '2026'))->isExpired($march2026))->toBeFalse()
        ->and((new PaymentInstrument(expirationMonth: '12', expirationYear: '2030'))->isExpired($march2026))->toBeFalse();
});

it('never treats an unknown expiry as expired', function (): void {
    expect((new PaymentInstrument)->isExpired())->toBeFalse()
        ->and((new PaymentInstrument(expirationMonth: 'XX', expirationYear: '2020'))->isExpired())->toBeFalse()
        ->and((new PaymentInstrument(expirationMonth: '01'))->expiry())->toBeNull();
});

it('lists a customer instruments as a page, picking out the default', function (): void {
    [$gateway, $http] = vaultGateway();
    $http->queueJson([
        'offset' => 0,
        'limit' => 2,
        'count' => 2,
        'total' => 5,
        '_embedded' => ['paymentInstruments' => [
            ['id' => 'pi_1', 'state' => 'ACTIVE', 'card' => ['expirationMonth' => '12', 'expirationYear' => '2030']],
            ['id' => 'pi_2', 'default' => true, 'state' => 'ACTIVE'],
        ]],
    ]);

    $page = $gateway->listPaymentInstruments('cust_1', limit: 2);

    expect($page->count())->toBe(2)
        ->and($page->totalCount)->toBe(5)
        ->and($page->hasMore())->toBeTrue()
        ->and($page->isEmpty())->toBeFalse()
        ->and($page->default()?->id)->toBe('pi_2')
        ->and($page->instruments[0]->customerId)->toBe('cust_1')
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/tms/v2/customers/cust_1/payment-instruments?offset=0&limit=2');
});

it('reports an empty instrument list as having nothing more to walk', function (): void {
    [$gateway] = vaultGateway();

    $page = $gateway->listPaymentInstruments('cust_1');

    expect($page->isEmpty())->toBeTrue()
        ->and($page->hasMore())->toBeFalse()
        ->and($page->default())->toBeNull();
});

it('re-dates a reissued card with a partial patch that leaves the rest alone', function (): void {
    [$gateway, $http] = vaultGateway();
    $http->queueJson(['id' => 'pi_1', 'state' => 'ACTIVE', 'card' => ['expirationMonth' => '01', 'expirationYear' => '2032']]);

    $instrument = $gateway->updatePaymentInstrument(new UpdatePaymentInstrumentRequest(
        customerId: 'cust_1',
        paymentInstrumentId: 'pi_1',
        expirationMonth: '01',
        expirationYear: '2032',
    ));

    $request = $http->lastRequest();

    expect($instrument->expiry())->toBe('01/2032')
        ->and($request?->method)->toBe('PATCH')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/tms/v2/customers/cust_1/payment-instruments/pi_1')
        ->and($request?->header('Signature'))->toContain('headers="(request-target) host digest v-c-date v-c-merchant-id"')
        ->and(vaultBody($http))->toBe(['card' => ['expirationMonth' => '01', 'expirationYear' => '2032']]);
});

it('promotes an instrument to the customer default', function (): void {
    [$gateway, $http] = vaultGateway();

    $gateway->updatePaymentInstrument(new UpdatePaymentInstrumentRequest(
        customerId: 'cust_1',
        paymentInstrumentId: 'pi_2',
        makeDefault: true,
    ));

    expect(vaultBody($http))->toBe(['default' => true]);
});

it('replaces the stored billing address when one is supplied', function (): void {
    [$gateway, $http] = vaultGateway();

    $gateway->updatePaymentInstrument(new UpdatePaymentInstrumentRequest(
        customerId: 'cust_1',
        paymentInstrumentId: 'pi_1',
        billTo: new BillingAddress(firstName: 'Jane', lastName: 'Doe', country: 'EG'),
    ));

    $body = vaultBody($http);

    expect($body['billTo']['firstName'])->toBe('Jane')
        ->and($body['billTo']['country'])->toBe('EG')
        ->and($body)->not->toHaveKey('card')
        ->and($body)->not->toHaveKey('default');
});

it('deletes a stored instrument, a customer, and an instrument identifier', function (): void {
    [$gateway, $http] = vaultGateway();

    expect($gateway->deletePaymentInstrument('cust_1', 'pi_1'))->toBeTrue()
        ->and($http->lastRequest()?->method)->toBe('DELETE')
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/tms/v2/customers/cust_1/payment-instruments/pi_1')
        ->and($http->lastRequest()?->header('Digest'))->toBeNull();

    expect($gateway->deleteCustomer('cust_1'))->toBeTrue()
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/tms/v2/customers/cust_1');

    expect($gateway->deleteInstrumentIdentifier('ii_1'))->toBeTrue()
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/tms/v1/instrumentidentifiers/ii_1');
});

it('reads a vault customer record', function (): void {
    [$gateway, $http] = vaultGateway();
    $http->queueJson(['id' => 'cust_1', 'defaultPaymentInstrument' => ['id' => 'pi_2']]);

    $customer = $gateway->getCustomer('cust_1');

    expect($customer['id'])->toBe('cust_1')
        ->and(data_get($customer, 'defaultPaymentInstrument.id'))->toBe('pi_2')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/tms/v2/customers/cust_1');
});
