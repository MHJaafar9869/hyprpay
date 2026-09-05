<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Http\ApiResponse;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Infrastructure\Http\ApiResponseRecorder;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;
use Hyprpay\Payments\Infrastructure\Http\RecordingHttpClient;
use Hyprpay\Payments\Infrastructure\Support\Redactor;

it('masks credential headers and leaves the rest readable', function (): void {
    $headers = Redactor::headers([
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer live-token',
        'X-Signature' => 'sha256=deadbeef',
        'v-c-signature' => 'keyid="abc"',
        'X-Request-Id' => 'req_1',
    ]);

    expect($headers)->toBe([
        'Content-Type' => 'application/json',
        'Authorization' => Redactor::MASK,
        'X-Signature' => Redactor::MASK,
        'v-c-signature' => Redactor::MASK,
        'X-Request-Id' => 'req_1',
    ]);
});

it('joins a repeated header into one value', function (): void {
    expect(Redactor::headers(['X-Trace' => ['a', 'b']]))->toBe(['X-Trace' => 'a, b']);
});

it('masks cardholder fields at any depth and keeps the rest of the body', function (): void {
    $body = Redactor::body((string) json_encode([
        'statusCode' => 200,
        'card' => ['number' => '4111111111111111', 'cvv' => '123', 'expiryYear' => '2030'],
        'nested' => ['deeper' => ['sharedSecret' => 'shh', 'label' => 'keep me']],
        'signature' => 'abc',
    ]));

    $decoded = json_decode((string) $body, true);

    expect($decoded['statusCode'])->toBe(200)
        ->and($decoded['card']['number'])->toBe('****1111')
        ->and($decoded['card']['cvv'])->toBe(Redactor::MASK)
        ->and($decoded['card']['expiryYear'])->toBe(Redactor::MASK)
        ->and($decoded['nested']['deeper']['sharedSecret'])->toBe(Redactor::MASK)
        ->and($decoded['nested']['deeper']['label'])->toBe('keep me')
        ->and($decoded['signature'])->toBe(Redactor::MASK);
});

it('matches a sensitive key however it is cased or separated', function (): void {
    $decoded = json_decode((string) Redactor::body((string) json_encode([
        'CVV' => '123', 'security-code' => '123', 'securityCode' => '123',
    ])), true);

    expect(array_values($decoded))->toBe([Redactor::MASK, Redactor::MASK, Redactor::MASK]);
});

it("keeps a card number's last four, which is what an operator reconciles against", function (): void {
    $pan = static fn (mixed $v): mixed => json_decode((string) Redactor::body((string) json_encode(['cardNumber' => $v])), true)['cardNumber'];

    expect($pan('4111111111111111'))->toBe('****1111')
        ->and($pan(4111111111111111))->toBe('****1111');
});

it('reads the last four through a mask the gateway already applied', function (): void {
    $pan = static fn (string $v): mixed => json_decode((string) Redactor::body((string) json_encode(['pan' => $v])), true)['pan'];

    expect($pan('411111XXXXXX1111'))->toBe('****1111')
        ->and($pan('**** **** **** 1111'))->toBe('****1111')
        ->and($pan('4111-1111-1111-1111'))->toBe('****1111');
});

it('masks a card number outright rather than partially exposing a short one', function (): void {
    $pan = static fn (mixed $v): mixed => json_decode((string) Redactor::body((string) json_encode(['number' => $v])), true)['number'];

    expect($pan('111'))->toBe(Redactor::MASK)
        ->and($pan(''))->toBe(Redactor::MASK)
        ->and($pan(['nested']))->toBe(Redactor::MASK);
});

it('truncates only the card number, never the fields beside it', function (): void {
    $decoded = json_decode((string) Redactor::body((string) json_encode([
        'cardNumber' => '4111111111111111',
        'cvv' => '123',
        'expiryYear' => '2030',
        'accountNumber' => '12345678',
        'iban' => 'EG00-not-a-real-iban',
    ])), true);

    expect($decoded['cardNumber'])->toBe('****1111')
        ->and($decoded['cvv'])->toBe(Redactor::MASK)
        ->and($decoded['expiryYear'])->toBe(Redactor::MASK)
        ->and($decoded['accountNumber'])->toBe(Redactor::MASK)
        ->and($decoded['iban'])->toBe(Redactor::MASK);
});

it('replaces a body it cannot parse rather than guessing at its fields', function (): void {
    expect(Redactor::body('merchant=acme&card=4111111111111111'))->toBe(Redactor::MASK)
        ->and(Redactor::body(null))->toBeNull()
        ->and(Redactor::body(''))->toBeNull();
});

it('records the redacted request and response of each call', function (): void {
    $recorder = new ApiResponseRecorder;
    $fake = new FakeHttpClient;
    $fake->queue(new HttpResponse(201, (string) json_encode(['id' => 'pay_1', 'secret' => 'nope']), [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer live',
    ]));

    (new RecordingHttpClient($fake, $recorder))->send(new HttpRequest(
        'POST',
        'https://gateway.test/payments',
        ['Authorization' => 'Bearer live', 'Accept' => 'application/json'],
        (string) json_encode(['amount' => 100, 'cvv' => '123']),
    ));

    $recorded = $recorder->take();

    expect($recorded)->toHaveCount(1);

    $call = $recorded[0];

    expect($call)->toBeInstanceOf(ApiResponse::class)
        ->and($call->method)->toBe('POST')
        ->and($call->url)->toBe('https://gateway.test/payments')
        ->and($call->status)->toBe(201)
        ->and($call->requestHeaders['Authorization'])->toBe(Redactor::MASK)
        ->and($call->requestHeaders['Accept'])->toBe('application/json')
        ->and($call->responseHeaders['Authorization'])->toBe(Redactor::MASK)
        ->and($call->requestBody)->toContain(Redactor::MASK)
        ->and($call->requestBody)->toContain('100')
        ->and($call->responseBody)->toContain('pay_1')
        ->and($call->responseBody)->toContain(Redactor::MASK);
});

it('still records a failed call, which is the one worth reading', function (): void {
    $recorder = new ApiResponseRecorder;
    $fake = new FakeHttpClient;
    $fake->queue(new HttpResponse(422, (string) json_encode(['error' => 'declined'])));

    (new RecordingHttpClient($fake, $recorder))->send(new HttpRequest('POST', 'https://gateway.test/x'));

    expect($recorder->take()[0]->status)->toBe(422);
});

it('empties the buffer when it is drained', function (): void {
    $recorder = new ApiResponseRecorder;
    $fake = (new FakeHttpClient)->queue(new HttpResponse(200, '{}'));
    $client = new RecordingHttpClient($fake, $recorder);

    $client->send(new HttpRequest('GET', 'https://gateway.test/a'));

    expect($recorder->take())->toHaveCount(1)
        ->and($recorder->take())->toBe([]);
});

it('drops the oldest calls once the cap is reached', function (): void {
    $recorder = new ApiResponseRecorder(2);
    $client = new RecordingHttpClient(new FakeHttpClient, $recorder);

    foreach (['a', 'b', 'c'] as $path) {
        $client->send(new HttpRequest('GET', 'https://gateway.test/'.$path));
    }

    expect(array_map(static fn (ApiResponse $r): string => $r->url, $recorder->take()))
        ->toBe(['https://gateway.test/b', 'https://gateway.test/c']);
});

it('forgets buffered calls without handing them out', function (): void {
    $recorder = new ApiResponseRecorder;
    (new RecordingHttpClient(new FakeHttpClient, $recorder))->send(new HttpRequest('GET', 'https://gateway.test/a'));

    $recorder->forget();

    expect($recorder->take())->toBe([]);
});

it('never keeps the 3-D Secure cryptogram, whatever the scheme calls it', function (): void {
    $decoded = json_decode((string) Redactor::body((string) json_encode([
        'consumerAuthenticationInformation' => [
            'cavv' => 'not-a-real-cryptogram',
            'authenticationValue' => 'not-a-real-cryptogram',
            'ucafAuthenticationData' => 'not-a-real-ucaf-value',
            'eci' => '05',
            'authenticationTransactionId' => 'PA-1',
        ],
    ])), true)['consumerAuthenticationInformation'];

    expect($decoded['cavv'])->toBe(Redactor::MASK)
        ->and($decoded['authenticationValue'])->toBe(Redactor::MASK)
        ->and($decoded['ucafAuthenticationData'])->toBe(Redactor::MASK)
        ->and($decoded['eci'])->toBe('05')
        ->and($decoded['authenticationTransactionId'])->toBe('PA-1');
});
