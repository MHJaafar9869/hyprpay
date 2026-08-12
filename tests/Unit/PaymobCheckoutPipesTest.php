<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\PaymobCheckoutContext;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\Pipes\Authenticate;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\Pipes\BuildCheckoutSession;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\Pipes\RegisterOrder;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\Pipes\RequestPaymentKey;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobCheckoutOptions;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobClient;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;
use Illuminate\Pipeline\Pipeline;

/**
 * Build a PaymobCheckoutContext whose client is backed by the given fake HTTP client.
 */
function paymobPipeContext(FakeHttpClient $http, string $integrationId = '111', ?string $iframeId = '999'): PaymobCheckoutContext
{
    $client = new PaymobClient($http, new GatewayCredentials(
        host: 'accept.paymob.com',
        merchantId: '',
        apiKeyId: '',
        sharedSecret: 'API_KEY',
    ));

    return new PaymobCheckoutContext(
        request: new CheckoutSessionRequest(
            money: Money::minor(10000, 'EGP'),
            orderReference: 'ORD1',
            paymentMethod: 'card',
            options: new PaymobCheckoutOptions(customerMobile: '01000000000'),
        ),
        client: $client,
        integrationId: $integrationId,
        iframeId: $iframeId,
    );
}

/**
 * An identity continuation that hands the context straight back.
 *
 * @return Closure(PaymobCheckoutContext): PaymobCheckoutContext
 */
function paymobPipeNext(): Closure
{
    return static fn (PaymobCheckoutContext $context): PaymobCheckoutContext => $context;
}

it('authenticate sets the auth token and calls next', function (): void {
    $http = (new FakeHttpClient)->queueJson(['token' => 'AUTH_TOKEN']);
    $context = paymobPipeContext($http);

    $result = (new Authenticate)->handle($context, paymobPipeNext());

    expect($result)->toBe($context)
        ->and($context->authToken)->toBe('AUTH_TOKEN')
        ->and($http->requestCount())->toBe(1);
});

it('register order posts the order with the bearer token and captures its id', function (): void {
    $http = (new FakeHttpClient)->queueJson(['id' => 12345]);
    $context = paymobPipeContext($http);
    $context->authToken = 'AUTH_TOKEN';

    (new RegisterOrder)->handle($context, paymobPipeNext());

    expect($context->order)->toBe(['id' => 12345])
        ->and($context->orderId)->toBe('12345')
        ->and($http->recorded()[0]->header('Authorization'))->toBe('Bearer AUTH_TOKEN');
});

it('request payment key sets the token and sends the integration id and default expiry', function (): void {
    $http = (new FakeHttpClient)->queueJson(['token' => 'PAY_TOKEN']);
    $context = paymobPipeContext($http, integrationId: '555');
    $context->authToken = 'AUTH_TOKEN';
    $context->orderId = '12345';

    (new RequestPaymentKey)->handle($context, paymobPipeNext());

    $body = json_decode((string) $http->recorded()[0]->body, true);

    expect($context->paymentToken)->toBe('PAY_TOKEN')
        ->and($body['integration_id'])->toBe('555')
        ->and($body['expiration'])->toBe(3600);
});

it('build checkout session assembles the redirect url, reference and raw payload', function (): void {
    $context = paymobPipeContext(new FakeHttpClient, iframeId: '999');
    $context->order = ['id' => 12345];
    $context->orderId = '12345';
    $context->paymentToken = 'PAY_TOKEN';

    (new BuildCheckoutSession)->handle($context, paymobPipeNext());

    expect($context->session)->toBeInstanceOf(CheckoutSession::class)
        ->and($context->session?->redirectUrl)->toBe('https://accept.paymob.com/api/acceptance/iframes/999?payment_token=PAY_TOKEN')
        ->and($context->session?->reference)->toBe('12345')
        ->and($context->session?->merchantReference)->toBe('ORD1')
        ->and($context->session?->raw)->toBe(['order' => ['id' => 12345], 'payment_token' => 'PAY_TOKEN']);
});

it('build checkout session omits the redirect url when no iframe id is configured', function (): void {
    $context = paymobPipeContext(new FakeHttpClient, iframeId: null);
    $context->orderId = '12345';
    $context->paymentToken = 'PAY_TOKEN';

    (new BuildCheckoutSession)->handle($context, paymobPipeNext());

    expect($context->session?->redirectUrl)->toBeNull()
        ->and($context->session?->reference)->toBe('12345');
});

it('runs the whole checkout as an ordered pipeline', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['token' => 'AUTH_TOKEN'])
        ->queueJson(['id' => 12345])
        ->queueJson(['token' => 'PAY_TOKEN']);
    $context = paymobPipeContext($http);

    $result = (new Pipeline)
        ->send($context)
        ->through([
            new Authenticate,
            new RegisterOrder,
            new RequestPaymentKey,
            new BuildCheckoutSession,
        ])
        ->thenReturn();

    expect($result)->toBe($context)
        ->and($context->authToken)->toBe('AUTH_TOKEN')
        ->and($context->orderId)->toBe('12345')
        ->and($context->paymentToken)->toBe('PAY_TOKEN')
        ->and($context->session?->redirectUrl)->toBe('https://accept.paymob.com/api/acceptance/iframes/999?payment_token=PAY_TOKEN')
        ->and($http->requestCount())->toBe(3);
});
