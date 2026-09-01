<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\CreateSubscriptionRequest;
use Hyprpay\Payments\Domain\Command\ListSubscriptionsRequest;
use Hyprpay\Payments\Domain\Command\UpdateSubscriptionRequest;
use Hyprpay\Payments\Domain\Enum\CredentialInitiator;
use Hyprpay\Payments\Domain\Enum\CybersourceCommerceIndicator;
use Hyprpay\Payments\Domain\Enum\SubscriptionStatus;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Result\SubscriptionResult;
use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: CybersourceUnifiedCheckoutGateway, 1: FakeHttpClient}
 */
function subscriptionGateway(): array
{
    $http = new FakeHttpClient;

    return [new CybersourceUnifiedCheckoutGateway(testCredentials(), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function subscriptionBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('creates a subscription against a vaulted customer with an inline plan', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson([
        'id' => 'sub_1',
        'status' => 'COMPLETED',
        'subscriptionInformation' => ['code' => 'SUB-CODE-1', 'status' => 'PENDING'],
        'clientReferenceInformation' => ['code' => 'ORDER-9'],
    ]);

    $result = $gateway->createSubscription(new CreateSubscriptionRequest(
        name: 'Pro monthly',
        customerId: 'cust_1',
        startDate: '2026-10-01',
        billingPeriod: BillingPeriod::monthly(),
        billingCycles: 12,
        billingAmount: Money::minor(4999, 'USD'),
        setupFee: Money::minor(1000, 'USD'),
        orderReference: 'ORDER-9',
    ));

    $request = $http->lastRequest();
    $body = subscriptionBody($http);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(SubscriptionStatus::Pending)
        ->and($result->subscriptionId)->toBe('sub_1')
        ->and($result->subscriptionCode)->toBe('SUB-CODE-1')
        ->and($result->requestStatus)->toBe('COMPLETED')
        ->and($result->orderReference)->toBe('ORDER-9')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions')
        ->and($request?->method)->toBe('POST')
        ->and($request?->header('Signature'))->not->toBeNull()
        ->and($request?->header('v-c-idempotency-id'))->toBe('ORDER-9')
        ->and($body['subscriptionInformation']['name'])->toBe('Pro monthly')
        ->and($body['subscriptionInformation']['startDate'])->toBe('2026-10-01T00:00:00Z')
        ->and($body['paymentInformation']['customer']['id'])->toBe('cust_1')
        ->and($body['planInformation']['billingPeriod'])->toBe(['length' => '1', 'unit' => 'M'])
        ->and($body['planInformation']['billingCycles'])->toBe(['total' => '12'])
        ->and($body['orderInformation']['amountDetails'])->toBe([
            'currency' => 'USD',
            'billingAmount' => '49.99',
            'setupFee' => '10.00',
        ])
        ->and($body['clientReferenceInformation']['code'])->toBe('ORDER-9')
        ->and($body)->not->toHaveKey('processingInformation');
});

it('passes a full UTC start timestamp through untouched', function (): void {
    [$gateway, $http] = subscriptionGateway();

    $gateway->createSubscription(new CreateSubscriptionRequest(
        name: 'Pro monthly',
        customerId: 'cust_1',
        startDate: '2026-10-01T22:47:57Z',
    ));

    expect(subscriptionBody($http)['subscriptionInformation']['startDate'])->toBe('2026-10-01T22:47:57Z');
});

it('references an existing plan without sending inline cadence or amounts', function (): void {
    [$gateway, $http] = subscriptionGateway();

    $gateway->createSubscription(new CreateSubscriptionRequest(
        name: 'Pro monthly',
        customerId: 'cust_1',
        startDate: '2026-10-01',
        planId: 'plan_1',
    ));

    $body = subscriptionBody($http);

    expect($body['subscriptionInformation']['planId'])->toBe('plan_1')
        ->and($body)->not->toHaveKey('planInformation')
        ->and($body)->not->toHaveKey('orderInformation')
        ->and($body['clientReferenceInformation']['code'])->toBe('cust_1');
});

it('threads the original cardholder transaction and initiator onto the subscription', function (): void {
    [$gateway, $http] = subscriptionGateway();

    $gateway->createSubscription(new CreateSubscriptionRequest(
        name: 'Pro monthly',
        customerId: 'cust_1',
        startDate: '2026-10-01',
        planId: 'plan_1',
        originalTransactionId: 'ntid_1',
        originalAuthorizedAmount: Money::minor(4999, 'USD'),
        commerceIndicator: CybersourceCommerceIndicator::Recurring,
        initiator: CredentialInitiator::Merchant,
    ));

    $body = subscriptionBody($http);

    expect($body['subscriptionInformation']['originalTransactionId'])->toBe('ntid_1')
        ->and($body['subscriptionInformation']['originalTransactionAuthorizedAmount'])->toBe('49.99')
        ->and($body['processingInformation']['commerceIndicator'])->toBe('RECURRING')
        ->and($body['processingInformation']['authorizationOptions']['initiator']['type'])->toBe('merchant');
});

it('sends the setup fee currency when only a setup fee is priced', function (): void {
    [$gateway, $http] = subscriptionGateway();

    $gateway->createSubscription(new CreateSubscriptionRequest(
        name: 'Pro monthly',
        customerId: 'cust_1',
        startDate: '2026-10-01',
        planId: 'plan_1',
        setupFee: Money::minor(1000, 'EGP'),
    ));

    expect(subscriptionBody($http)['orderInformation']['amountDetails'])
        ->toBe(['currency' => 'EGP', 'setupFee' => '10.00']);
});

it('reports a declined create as unsuccessful', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson([
        'id' => 'sub_2',
        'status' => 'DECLINED',
        'subscriptionInformation' => ['code' => 'SUB-CODE-2', 'status' => 'FAILED'],
    ]);

    $result = $gateway->createSubscription(new CreateSubscriptionRequest(
        name: 'Pro monthly',
        customerId: 'cust_1',
        startDate: '2026-10-01',
        planId: 'plan_1',
    ));

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(SubscriptionStatus::Failed)
        ->and($result->requestStatus)->toBe('DECLINED');
});

it('looks up a subscription by id', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson([
        'id' => 'sub_1',
        'subscriptionInformation' => [
            'code' => 'SUB-CODE-1',
            'planId' => 'plan_1',
            'name' => 'Pro monthly',
            'startDate' => '2026-10-01T00:00:00Z',
            'status' => 'ACTIVE',
        ],
    ]);

    $result = $gateway->getSubscription('sub_1');

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(SubscriptionStatus::Active)
        ->and($result->status?->isBilling())->toBeTrue()
        ->and($result->planId)->toBe('plan_1')
        ->and($result->name)->toBe('Pro monthly')
        ->and($result->startDate)->toBe('2026-10-01T00:00:00Z')
        ->and($result->requestStatus)->toBeNull()
        ->and($http->lastRequest()?->method)->toBe('GET')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions/sub_1');
});

it('cancels a subscription with a bodyless signed post', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson([
        'id' => 'sub_1',
        'status' => 'ACCEPTED',
        'subscriptionInformation' => ['code' => 'SUB-CODE-1', 'status' => 'CANCELLED'],
    ]);

    $result = $gateway->cancelSubscription('sub_1');

    $request = $http->lastRequest();

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($result->status?->isTerminal())->toBeTrue()
        ->and($request?->method)->toBe('POST')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions/sub_1/cancel')
        ->and($request?->body)->toBe('')
        ->and($request?->header('Digest'))->toBe('SHA-256='.base64_encode(hash('sha256', '', true)))
        ->and($request?->header('Signature'))->toContain('headers="(request-target) host digest v-c-date v-c-merchant-id"');
});

it('suspends a subscription', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson([
        'id' => 'sub_1',
        'status' => 'ACCEPTED',
        'subscriptionInformation' => ['status' => 'SUSPENDED'],
    ]);

    $result = $gateway->suspendSubscription('sub_1');

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(SubscriptionStatus::Suspended)
        ->and($result->status?->isTerminal())->toBeFalse()
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions/sub_1/suspend');
});

it('reactivates a suspended subscription and signs the processMissedPayments query', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson([
        'id' => 'sub_1',
        'status' => 'COMPLETED',
        'subscriptionInformation' => ['status' => 'ACTIVE'],
    ]);

    $result = $gateway->activateSubscription('sub_1', processMissedPayments: false);

    $request = $http->lastRequest();

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(SubscriptionStatus::Active)
        ->and($request?->url)->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions/sub_1/activate?processMissedPayments=false')
        ->and($request?->header('Signature'))->not->toBeNull();
});

it('defaults reactivation to processing the payments missed while suspended', function (): void {
    [$gateway, $http] = subscriptionGateway();

    $gateway->activateSubscription('sub_1');

    expect($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions/sub_1/activate?processMissedPayments=true');
});

it('throws when the recurring billing api rejects the request', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson(['message' => 'Invalid plan'], 400);

    expect(fn (): SubscriptionResult => $gateway->getSubscription('sub_missing'))
        ->toThrow(GatewayRequestException::class);
});

it('amends a subscription in place with a signed patch carrying only the changed fields', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson([
        'id' => 'sub_1',
        'status' => 'COMPLETED',
        'subscriptionInformation' => ['code' => 'SUB-CODE-1', 'status' => 'ACTIVE'],
    ]);

    $result = $gateway->updateSubscription(new UpdateSubscriptionRequest(
        subscriptionId: 'sub_1',
        name: 'Pro annual',
        billingAmount: Money::minor(9900, 'USD'),
        orderReference: 'ORDER-10',
    ));

    $request = $http->lastRequest();
    $body = subscriptionBody($http);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(SubscriptionStatus::Active)
        ->and($request?->method)->toBe('PATCH')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions/sub_1')
        ->and($request?->header('v-c-idempotency-id'))->toBe('ORDER-10')
        ->and($request?->header('Signature'))->toContain('headers="(request-target) host digest v-c-date v-c-merchant-id"')
        ->and($body['subscriptionInformation'])->toBe(['name' => 'Pro annual'])
        ->and($body['orderInformation']['amountDetails'])->toBe(['billingAmount' => '99.00'])
        ->and($body['clientReferenceInformation']['code'])->toBe('ORDER-10')
        ->and($body)->not->toHaveKey('planInformation')
        ->and($body)->not->toHaveKey('processingInformation');
});

it('omits the currency from an update, since a subscription bills in the currency it was created with', function (): void {
    [$gateway, $http] = subscriptionGateway();

    $gateway->updateSubscription(new UpdateSubscriptionRequest(
        subscriptionId: 'sub_1',
        billingAmount: Money::minor(9900, 'EGP'),
        setupFee: Money::minor(500, 'EGP'),
    ));

    expect(subscriptionBody($http)['orderInformation']['amountDetails'])
        ->toBe(['billingAmount' => '99.00', 'setupFee' => '5.00']);
});

it('sends a new cycle count as the only plan change an update allows', function (): void {
    [$gateway, $http] = subscriptionGateway();

    $gateway->updateSubscription(new UpdateSubscriptionRequest(
        subscriptionId: 'sub_1',
        planId: 'plan_2',
        code: 'SUB-CODE-9',
        startDate: '2026-11-01',
        billingCycles: 24,
    ));

    $body = subscriptionBody($http);

    expect($body['planInformation'])->toBe(['billingCycles' => ['total' => '24']])
        ->and($body['subscriptionInformation'])->toBe([
            'planId' => 'plan_2',
            'code' => 'SUB-CODE-9',
            'startDate' => '2026-11-01T00:00:00Z',
        ])
        ->and($body)->not->toHaveKey('orderInformation')
        ->and($body)->not->toHaveKey('clientReferenceInformation');
});

it('treats an update held for review as accepted while surfacing the raw status', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson([
        'id' => 'sub_1',
        'status' => 'PENDING_REVIEW',
        'subscriptionInformation' => ['status' => 'ACTIVE'],
    ]);

    $result = $gateway->updateSubscription(new UpdateSubscriptionRequest(subscriptionId: 'sub_1', name: 'Pro annual'));

    expect($result->success)->toBeTrue()
        ->and($result->requestStatus)->toBe('PENDING_REVIEW');
});

it('reports a rejected update as unsuccessful', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson(['id' => 'sub_1', 'status' => 'INVALID_REQUEST']);

    $result = $gateway->updateSubscription(new UpdateSubscriptionRequest(subscriptionId: 'sub_1', name: 'Pro annual'));

    expect($result->success)->toBeFalse()
        ->and($result->requestStatus)->toBe('INVALID_REQUEST');
});

it('lists subscriptions as a page of results with the filter in the signed query', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson([
        'totalCount' => 42,
        'subscriptions' => [
            [
                'id' => 'sub_1',
                'subscriptionInformation' => ['code' => 'SUB-1', 'name' => 'Pro monthly', 'status' => 'DELINQUENT'],
                'clientReferenceInformation' => ['code' => 'ORDER-1'],
            ],
            [
                'id' => 'sub_2',
                'subscriptionInformation' => ['code' => 'SUB-2', 'name' => 'Pro annual', 'status' => 'DELINQUENT'],
            ],
        ],
    ]);

    $page = $gateway->listSubscriptions(new ListSubscriptionsRequest(
        status: SubscriptionStatus::Delinquent,
        customerId: 'cust_1',
        limit: 2,
    ));

    $request = $http->lastRequest();

    expect($page->count())->toBe(2)
        ->and($page->totalCount)->toBe(42)
        ->and($page->offset)->toBe(0)
        ->and($page->limit)->toBe(2)
        ->and($page->isEmpty())->toBeFalse()
        ->and($page->hasMore())->toBeTrue()
        ->and($page->subscriptions[0]->subscriptionId)->toBe('sub_1')
        ->and($page->subscriptions[0]->status)->toBe(SubscriptionStatus::Delinquent)
        ->and($page->subscriptions[0]->orderReference)->toBe('ORDER-1')
        ->and($page->subscriptions[1]->name)->toBe('Pro annual')
        ->and($request?->method)->toBe('GET')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions?offset=0&limit=2&status=DELINQUENT&customerId=cust_1')
        ->and($request?->header('Signature'))->not->toBeNull();
});

it('sends only paging when no filter is set', function (): void {
    [$gateway, $http] = subscriptionGateway();

    $gateway->listSubscriptions(new ListSubscriptionsRequest);

    expect($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions?offset=0&limit=20');
});

it('url-encodes a merchant reference filter', function (): void {
    [$gateway, $http] = subscriptionGateway();

    $gateway->listSubscriptions(new ListSubscriptionsRequest(orderReference: 'ORDER 1/A'));

    expect($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions?offset=0&limit=20&clientReferenceInformationCode=ORDER+1%2FA');
});

it('walks to the next page keeping every filter', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $request = new ListSubscriptionsRequest(status: SubscriptionStatus::Active, limit: 50);

    $gateway->listSubscriptions($request->nextPage());

    expect($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions?offset=50&limit=50&status=ACTIVE');
});

it('reports an empty page as having nothing more to walk', function (): void {
    [$gateway, $http] = subscriptionGateway();
    $http->queueJson(['totalCount' => 42, 'subscriptions' => []]);

    $page = $gateway->listSubscriptions(new ListSubscriptionsRequest(offset: 100));

    expect($page->isEmpty())->toBeTrue()
        ->and($page->count())->toBe(0)
        ->and($page->hasMore())->toBeFalse()
        ->and($page->subscriptions)->toBe([]);
});

it('returns an empty page when the response carries no subscriptions key', function (): void {
    [$gateway, $http] = subscriptionGateway();

    $page = $gateway->listSubscriptions(new ListSubscriptionsRequest);

    expect($page->isEmpty())->toBeTrue()
        ->and($page->totalCount)->toBe(0)
        ->and($page->hasMore())->toBeFalse();
});
