<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Credentials;

use Hyprpay\Payments\Domain\Contract\CredentialResolver;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Exception\MissingCredentialsException;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Support\Value;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Credential resolver that reads gateway settings from Laravel's config repository.
 *
 * Implements the {@see CredentialResolver} port by loading the config block for a
 * gateway under `gateway.gateways.*` and hydrating a {@see GatewayCredentials} DTO.
 */
final readonly class ConfigCredentialResolver implements CredentialResolver
{
    /**
     * Wire up the resolver with the application's config repository.
     *
     * @param  Config  $config  Laravel config repository the gateway settings are read from.
     */
    public function __construct(private Config $config) {}

    /**
     * Resolve and validate the credentials configured for the given gateway.
     *
     * Reads the gateway's config block, builds a {@see GatewayCredentials} DTO,
     * and throws when the block is missing/blank or the credentials are incomplete.
     *
     * @param  GatewayName  $gateway  The gateway whose credentials to resolve.
     *
     * @throws MissingCredentialsException When no config exists for the gateway or required fields are absent.
     */
    public function resolve(GatewayName $gateway): GatewayCredentials
    {
        $config = $this->config->get("gateway.gateways.{$gateway->value}");

        if (! is_array($config) || blank($config)) {
            throw MissingCredentialsException::forGateway($gateway);
        }

        $credentials = GatewayCredentials::fromConfig(Value::array($config));

        if (! $credentials->isComplete()) {
            throw MissingCredentialsException::forGateway($gateway);
        }

        return $credentials;
    }
}
