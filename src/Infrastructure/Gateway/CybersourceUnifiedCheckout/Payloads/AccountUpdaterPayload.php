<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\CreateAccountUpdaterBatchRequest;
use Hyprpay\Payments\Domain\ValueObject\AccountUpdaterToken;

/**
 * Builds the CyberSource Account Updater batch request body.
 *
 * The batch carries only vault token ids — never card numbers — so the cards being refreshed
 * never leave the vault. Each token may optionally restate the expiry currently on file, which
 * the networks match against.
 */
final class AccountUpdaterPayload
{
    /**
     * Build the POST /accountupdater/v1/batches request body.
     *
     * @param  CreateAccountUpdaterBatchRequest  $request  The tokens to submit for refresh.
     * @return array<string, mixed>
     */
    public static function build(CreateAccountUpdaterBatchRequest $request): array
    {
        $payload = [
            'type' => $request->type->value,
            'included' => [
                'tokens' => array_values(array_map(
                    static fn (AccountUpdaterToken $token): array => $token->toArray(),
                    $request->tokens,
                )),
            ],
        ];

        if (filled($request->merchantReference)) {
            $payload['merchantReference'] = $request->merchantReference;
        }

        return $payload;
    }
}
