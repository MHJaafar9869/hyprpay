<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * Build a CyberSource-style `v-c-signature` header for the given body.
 */
function signedWebhookHeader(string $body, string $secretBase64, ?int $timestampMs = null): string
{
    $timestampMs ??= (int) (microtime(true) * 1000);
    $signature = base64_encode(hash_hmac('sha256', $timestampMs.'.'.$body, base64_decode($secretBase64, true), true));

    return "t={$timestampMs};keyId=key_1;sig={$signature}";
}

function webhookGateway(): CybersourceUnifiedCheckoutGateway
{
    return new CybersourceUnifiedCheckoutGateway(testCredentials(), new FakeHttpClient);
}

it('verifies a correctly signed webhook and extracts the event details', function (): void {
    $body = (string) json_encode([
        'eventType' => 'payment:captured',
        'payload' => ['id' => 'txn_123', 'status' => 'CAPTURED'],
    ]);

    $event = webhookGateway()->verifyWebhook($body, [
        'v-c-signature' => signedWebhookHeader($body, base64_encode('webhook_secret')),
    ]);

    expect($event->verified)->toBeTrue()
        ->and($event->eventType)->toBe('payment:captured')
        ->and($event->transactionId)->toBe('txn_123')
        ->and($event->status)->toBe(PaymentStatus::Captured);
});

it('rejects a webhook signed with the wrong secret', function (): void {
    $body = (string) json_encode(['payload' => ['id' => 'x']]);

    $event = webhookGateway()->verifyWebhook($body, [
        'v-c-signature' => signedWebhookHeader($body, base64_encode('the_wrong_secret')),
    ]);

    expect($event->verified)->toBeFalse();
});

it('rejects a webhook whose body was tampered with after signing', function (): void {
    $header = signedWebhookHeader((string) json_encode(['payload' => ['id' => 'original']]), base64_encode('webhook_secret'));

    $event = webhookGateway()->verifyWebhook((string) json_encode(['payload' => ['id' => 'tampered']]), [
        'v-c-signature' => $header,
    ]);

    expect($event->verified)->toBeFalse();
});

it('rejects a webhook older than five minutes', function (): void {
    $body = (string) json_encode(['payload' => ['id' => 'x']]);
    $staleTimestamp = (int) (microtime(true) * 1000) - 600_000;

    $event = webhookGateway()->verifyWebhook($body, [
        'v-c-signature' => signedWebhookHeader($body, base64_encode('webhook_secret'), $staleTimestamp),
    ]);

    expect($event->verified)->toBeFalse();
});
