<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\CreateWebhookRequest;
use Hyprpay\Payments\Domain\Command\UpdateWebhookRequest;
use Hyprpay\Payments\Domain\Enum\WebhookRetryAlgorithm;
use Hyprpay\Payments\Domain\Enum\WebhookSecurityType;
use Hyprpay\Payments\Domain\Enum\WebhookStatus;
use Hyprpay\Payments\Domain\ValueObject\WebhookProduct;
use Hyprpay\Payments\Domain\ValueObject\WebhookRetryPolicy;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: CybersourceUnifiedCheckoutGateway, 1: FakeHttpClient}
 */
function webhookMgmtGateway(): array
{
    $http = new FakeHttpClient;

    return [new CybersourceUnifiedCheckoutGateway(testCredentials(), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function webhookMgmtBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('subscribes an endpoint to gateway notifications', function (): void {
    [$gateway, $http] = webhookMgmtGateway();
    $http->queueJson([
        'webhookId' => 'wh_1',
        'name' => 'Payments',
        'webhookUrl' => 'https://shop.test/webhook',
        'status' => 'ACTIVE',
        'securityPolicy' => ['securityType' => 'key'],
        'products' => [['productId' => 'payments', 'eventTypes' => ['payments.payments.accept']]],
    ]);

    $webhook = $gateway->createWebhook(new CreateWebhookRequest(
        name: 'Payments',
        webhookUrl: 'https://shop.test/webhook',
        products: [new WebhookProduct('payments', ['payments.payments.accept', 'payments.refunds.accept'])],
        healthCheckUrl: 'https://shop.test/health',
        securityType: WebhookSecurityType::Key,
        securityConfig: ['keyId' => 'key_1'],
        retryPolicy: new WebhookRetryPolicy(
            algorithm: WebhookRetryAlgorithm::Geometric,
            firstRetry: 10,
            interval: 30,
            numberOfRetries: 3,
            deactivateOnFailure: true,
        ),
    ));

    $request = $http->lastRequest();
    $body = webhookMgmtBody($http);

    expect($webhook->webhookId)->toBe('wh_1')
        ->and($webhook->isDelivering())->toBeTrue()
        ->and($webhook->securityType)->toBe(WebhookSecurityType::Key)
        ->and($webhook->isSignatureVerifiable())->toBeTrue()
        ->and($webhook->eventTypes())->toBe(['payments.payments.accept'])
        ->and($request?->method)->toBe('POST')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/notification-subscriptions/v2/webhooks')
        ->and($body['name'])->toBe('Payments')
        ->and($body['organizationId'])->toBe('test_merchant')
        ->and($body['healthCheckUrl'])->toBe('https://shop.test/health')
        ->and($body['products'])->toBe([[
            'productId' => 'payments',
            'eventTypes' => ['payments.payments.accept', 'payments.refunds.accept'],
        ]])
        ->and($body['securityPolicy'])->toBe(['securityType' => 'key', 'config' => ['keyId' => 'key_1']])
        ->and($body['retryPolicy'])->toBe([
            'algorithm' => 'GEOMETRIC',
            'firstRetry' => 10,
            'interval' => 30,
            'numberOfRetries' => 3,
            'deactivateFlag' => 'true',
        ]);
});

it('omits the security policy and retry policy when the gateway defaults should apply', function (): void {
    [$gateway, $http] = webhookMgmtGateway();

    $gateway->createWebhook(new CreateWebhookRequest(
        name: 'Minimal',
        webhookUrl: 'https://shop.test/webhook',
        products: [new WebhookProduct('payments')],
    ));

    $body = webhookMgmtBody($http);

    expect($body)->not->toHaveKey('securityPolicy')
        ->and($body)->not->toHaveKey('retryPolicy')
        ->and($body)->not->toHaveKey('healthCheckUrl')
        ->and($body['products'])->toBe([['productId' => 'payments']]);
});

it('flags an oAuth subscription as one the sdk cannot verify signatures for', function (): void {
    [$gateway, $http] = webhookMgmtGateway();
    $http->queueJson(['webhookId' => 'wh_2', 'status' => 'ACTIVE', 'securityPolicy' => ['securityType' => 'oAuth']]);

    $webhook = $gateway->getWebhook('wh_2');

    expect($webhook->securityType)->toBe(WebhookSecurityType::OAuth)
        ->and($webhook->isSignatureVerifiable())->toBeFalse()
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/notification-subscriptions/v2/webhooks/wh_2');
});

it('lists webhooks, filtering by product and event', function (): void {
    [$gateway, $http] = webhookMgmtGateway();
    $http->queueJson([
        ['webhookId' => 'wh_1', 'status' => 'ACTIVE'],
        ['webhookId' => 'wh_2', 'status' => 'INACTIVE'],
    ]);

    $webhooks = $gateway->listWebhooks(productId: 'payments', eventType: 'payments.payments.accept');

    expect($webhooks)->toHaveCount(2)
        ->and($webhooks[0]->isDelivering())->toBeTrue()
        ->and($webhooks[1]->status)->toBe(WebhookStatus::Inactive)
        ->and($webhooks[1]->isDelivering())->toBeFalse()
        ->and($http->lastRequest()?->url)->toBe(
            'https://apitest.cybersource.com/notification-subscriptions/v2/webhooks'
            .'?organizationId=test_merchant&productId=payments&eventType=payments.payments.accept'
        );
});

it('amends a subscription with a partial patch', function (): void {
    [$gateway, $http] = webhookMgmtGateway();

    $gateway->updateWebhook(new UpdateWebhookRequest(
        webhookId: 'wh_1',
        webhookUrl: 'https://shop.test/webhook-v2',
    ));

    expect($http->lastRequest()?->method)->toBe('PATCH')
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/notification-subscriptions/v2/webhooks/wh_1')
        ->and(webhookMgmtBody($http))->toBe(['webhookUrl' => 'https://shop.test/webhook-v2']);
});

it('pauses and resumes delivery without discarding the subscription', function (): void {
    [$gateway, $http] = webhookMgmtGateway();

    expect($gateway->setWebhookStatus('wh_1', WebhookStatus::Inactive))->toBeTrue()
        ->and($http->lastRequest()?->method)->toBe('PUT')
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/notification-subscriptions/v2/webhooks/wh_1/status')
        ->and(webhookMgmtBody($http))->toBe(['status' => 'INACTIVE']);

    $gateway->setWebhookStatus('wh_1', WebhookStatus::Active);
    expect(webhookMgmtBody($http))->toBe(['status' => 'ACTIVE']);
});

it('deletes a subscription and sends a test notification', function (): void {
    [$gateway, $http] = webhookMgmtGateway();

    expect($gateway->deleteWebhook('wh_1'))->toBeTrue()
        ->and($http->lastRequest()?->method)->toBe('DELETE')
        ->and($http->lastRequest()?->header('Digest'))->toBeNull();

    $http->queueJson(['httpStatus' => '200', 'message' => 'delivered']);
    $result = $gateway->testWebhook('wh_1');

    expect($result['message'])->toBe('delivered')
        ->and($http->lastRequest()?->method)->toBe('POST')
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/notification-subscriptions/v1/webhooks/wh_1')
        ->and($http->lastRequest()?->body)->toBe('');
});

it('discovers the products and events an account may subscribe to', function (): void {
    [$gateway, $http] = webhookMgmtGateway();
    $http->queueJson([['productId' => 'payments', 'eventTypes' => [['eventName' => 'payments.payments.accept']]]]);

    $products = $gateway->listWebhookProducts();

    expect($products[0]->productId)->toBe('payments')
        ->and($products[0]->eventNames())->toBe(['payments.payments.accept'])
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/notification-subscriptions/v2/products/test_merchant');
});

it('creates the signing key that becomes the webhook secret', function (): void {
    [$gateway, $http] = webhookMgmtGateway();
    $http->queueJson(['keyInformation' => [
        'keyId' => 'key_1',
        'key' => 'c2hhcmVkLXNlY3JldA==',
        'status' => 'ACTIVE',
        'keyType' => 'sharedSecret',
        'organizationId' => 'test_merchant',
        'expiryDuration' => '365',
    ]]);

    $key = $gateway->createWebhookSecurityKey(['provider' => 'nrtd', 'tenant' => 'pxsecurity', 'keyType' => 'sharedSecret']);

    expect($key->hasKey())->toBeTrue()
        ->and($key->keyId)->toBe('key_1')
        ->and($key->key)->toBe('c2hhcmVkLXNlY3JldA==')
        ->and($key->keyType)->toBe('sharedSecret')
        ->and($key->expiryDuration)->toBe('365')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/kms/egress/v2/keys-sym')
        ->and(webhookMgmtBody($http))->toBe([
            'clientRequestAction' => 'CREATE',
            'keyInformation' => [
                'provider' => 'nrtd',
                'tenant' => 'pxsecurity',
                'keyType' => 'sharedSecret',
                'organizationId' => 'test_merchant',
            ],
        ]);
});

it('reports a key response that carried no key value', function (): void {
    [$gateway] = webhookMgmtGateway();

    expect($gateway->createWebhookSecurityKey(['provider' => 'nrtd'])->hasKey())->toBeFalse();
});

it('surfaces the per-event metadata that decides how a subscription is configured', function (): void {
    [$gateway, $http] = webhookMgmtGateway();
    $http->queueJson([[
        'productId' => 'payments',
        'productName' => 'Payments',
        'eventTypes' => [
            ['eventName' => 'payments.payments.accept', 'displayName' => 'Payment accepted', 'frequency' => 1],
            ['eventName' => 'payments.payments.review', 'timeSensitivity' => true],
            ['eventName' => 'payments.payments.secret', 'payloadEncryption' => true],
        ],
    ]]);

    $product = $gateway->listWebhookProducts()[0];

    expect($product->productName)->toBe('Payments')
        ->and($product->eventNames())->toHaveCount(3)
        ->and($product->eventTypes[0]->displayName)->toBe('Payment accepted')
        ->and($product->eventTypes[0]->frequency)->toBe(1)
        ->and($product->eventTypes[0]->isTimeSensitive)->toBeFalse()
        ->and($product->timeSensitiveEvents())->toHaveCount(1)
        ->and($product->timeSensitiveEvents()[0]->eventName)->toBe('payments.payments.review')
        ->and($product->encryptedEvents())->toHaveCount(1)
        ->and($product->encryptedEvents()[0]->eventName)->toBe('payments.payments.secret');
});

it('turns a catalogue entry straight into a subscription entry', function (): void {
    [$gateway, $http] = webhookMgmtGateway();
    $http->queueJson([[
        'productId' => 'payments',
        'eventTypes' => [
            ['eventName' => 'payments.payments.accept'],
            ['eventName' => 'payments.refunds.accept'],
        ],
    ]]);

    $product = $gateway->listWebhookProducts()[0];

    expect($product->toSubscription()->toArray())->toBe([
        'productId' => 'payments',
        'eventTypes' => ['payments.payments.accept', 'payments.refunds.accept'],
    ])
        ->and($product->toSubscription(['payments.refunds.accept'])->toArray())->toBe([
            'productId' => 'payments',
            'eventTypes' => ['payments.refunds.accept'],
        ]);
});
