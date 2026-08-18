<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Mcp;

use Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use PhpMcp\Server\Attributes\McpTool;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * MCP tool surface for the hyprpay/payments SDK.
 *
 * Each method is exposed to AI agents as a tool that reflects the live package —
 * its gateways, operations, request/result DTOs, enums, and value objects — so an
 * agent can discover the SDK and generate correct integration code without guessing.
 * Mirrors the shape of the CyberSource developer MCP (overview, list, class details,
 * code templates) against this package instead.
 */
final class HyprpayTools
{
    private const MONEY_MOVING = [
        'charge', 'capture', 'refund', 'void', 'reverseAuthorization', 'chargeStoredCredential',
    ];

    private PackageReflector $reflector;

    public function __construct()
    {
        $this->reflector = new PackageReflector;
    }

    /**
     * Get an orientation guide for the hyprpay/payments SDK: what it is, how to install
     * and configure it, the gateway roster, and the other tools this MCP exposes. Call
     * this first when starting any integration task against the package.
     */
    #[McpTool(name: 'get_sdk_overview')]
    public function getSdkOverview(): string
    {
        $root = $this->reflector->packageRoot();
        $composer = $this->readJson($root.'/composer.json');
        $readme = @file_get_contents($root.'/README.md');

        $gateways = [];
        foreach (GatewayName::cases() as $case) {
            $gateways[] = sprintf('- **%s** — `GatewayName::%s` (`%s`)', $case->label(), $case->name, $case->value);
        }

        $tools = [
            '- `get_sdk_overview` — this guide.',
            '- `list_gateways` — every gateway and the operations each one supports.',
            '- `get_operation_details` — an operation\'s request DTO, return type, and supporting gateways.',
            '- `get_class_details` — full reflection of any class, interface, enum, or trait in the SDK.',
            '- `get_code_template` — a ready-to-adapt PHP snippet for a gateway + operation.',
            '- `search` — find types by name or purpose across the package.',
        ];

        $header = sprintf(
            "# %s\n\n%s\n\n**Version:** %s  \n**Install:** `composer require %s`\n",
            $composer['name'] ?? 'hyprpay/payments',
            $composer['description'] ?? '',
            $composer['version'] ?? 'dev',
            $composer['name'] ?? 'hyprpay/payments',
        );

        return implode("\n", [
            $header,
            "## Gateways\n",
            implode("\n", $gateways),
            "\n## MCP tools in this server\n",
            implode("\n", $tools),
            "\n## Package README\n",
            is_string($readme) ? $readme : '(README unavailable)',
        ]);
    }

    /**
     * List every payment gateway the SDK can drive, with its GatewayName key, concrete
     * driver class, one-line purpose, and the exact set of operations it supports (some
     * gateways implement only a subset). Use this to pick a gateway for a task.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_gateways')]
    public function listGateways(): array
    {
        return [
            'operations' => $this->reflector->operations(),
            'gateways' => $this->reflector->gatewayRoster(),
            'note' => 'supported_operations lists only what each driver implements; every other operation throws UnsupportedOperationException.',
        ];
    }

    /**
     * Describe a single payment operation from PaymentGatewayInterface: its signature,
     * the request DTO it takes (fully reflected), the result DTO it returns, and which
     * gateways support it. Pass the method name, e.g. "charge", "refund", "createCheckoutSession".
     *
     * @param  string  $operation  The interface method name, e.g. "charge", "capture", "refund", "verifyWebhook".
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_operation_details')]
    public function getOperationDetails(string $operation): array
    {
        if (! in_array($operation, $this->reflector->operations(), true)) {
            return [
                'error' => 'unknown_operation',
                'operation' => $operation,
                'available_operations' => $this->reflector->operations(),
            ];
        }

        $details = $this->reflector->describeOperation($operation);
        $method = new ReflectionMethod(PaymentGatewayInterface::class, $operation);

        $related = [];
        foreach ($method->getParameters() as $parameter) {
            $fqcn = $this->hyprpayTypeName($parameter->getType());
            if ($fqcn !== null) {
                $related[$parameter->getName()] = $this->reflector->describeType($fqcn);
            }
        }

        $returnFqcn = $this->hyprpayTypeName($method->getReturnType());
        if ($returnFqcn !== null) {
            $details['returns']['shape'] = $this->reflector->describeType($returnFqcn);
        }

        $supporting = [];
        foreach ($this->reflector->gatewayRoster() as $gateway) {
            if (in_array($operation, $gateway['supported_operations'], true)) {
                $supporting[] = $gateway['key'];
            }
        }

        $details['request_dtos'] = $related;
        $details['supported_by'] = $supporting;
        $details['money_moving'] = in_array($operation, self::MONEY_MOVING, true);

        return $details;
    }

    /**
     * Reflect any type in the SDK — class, interface, enum, or trait — returning its kind,
     * docblock, constructor parameters, public properties, and methods. Accepts a short
     * name ("ChargeRequest", "Money", "PaymentStatus") or a fully-qualified name.
     *
     * @param  string  $name  The type to describe: short name or fully-qualified class name.
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_class_details')]
    public function getClassDetails(string $name): array
    {
        $matches = $this->reflector->findTypes($name);

        if ($matches === []) {
            return [
                'error' => 'type_not_found',
                'query' => $name,
                'hint' => 'Use the search tool to find a type by keyword, or list_gateways for drivers.',
            ];
        }

        if (count($matches) > 1) {
            return [
                'error' => 'ambiguous_type',
                'query' => $name,
                'candidates' => $matches,
                'hint' => 'Re-run get_class_details with one of the fully-qualified candidates.',
            ];
        }

        return $this->reflector->describeType($matches[0]);
    }

    /**
     * Generate a ready-to-adapt PHP code snippet that performs one operation through one
     * gateway, built from the real request-DTO constructor. Returns an error listing the
     * supporting gateways when the chosen gateway does not implement the operation.
     *
     * @param  string  $gateway  Gateway key or enum case, e.g. "cybersource_uc" or "CybersourceUnifiedCheckout".
     * @param  string  $operation  The operation to perform, e.g. "charge", "refund", "createCheckoutSession".
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_code_template')]
    public function getCodeTemplate(string $gateway, string $operation): array
    {
        $case = $this->resolveGateway($gateway);

        if ($case === null) {
            return [
                'error' => 'unknown_gateway',
                'gateway' => $gateway,
                'available_gateways' => array_map(static fn (GatewayName $g): string => $g->value, GatewayName::cases()),
            ];
        }

        if (! in_array($operation, $this->reflector->operations(), true)) {
            return [
                'error' => 'unknown_operation',
                'operation' => $operation,
                'available_operations' => $this->reflector->operations(),
            ];
        }

        $driver = $this->reflector->gatewayClassMap()[$case->name];

        if (! in_array($operation, $this->reflector->supportedOperations($driver), true)) {
            $supporting = [];
            foreach ($this->reflector->gatewayRoster() as $entry) {
                if (in_array($operation, $entry['supported_operations'], true)) {
                    $supporting[] = $entry['key'];
                }
            }

            return [
                'error' => 'operation_unsupported_by_gateway',
                'gateway' => $case->value,
                'operation' => $operation,
                'supported_by' => $supporting,
            ];
        }

        return [
            'gateway' => $case->value,
            'operation' => $operation,
            'money_moving' => in_array($operation, self::MONEY_MOVING, true),
            'code' => $this->renderTemplate($case, $operation),
            'note' => in_array($operation, self::MONEY_MOVING, true)
                ? 'This operation moves money. Resolve credentials in test mode and require human confirmation before running it autonomously.'
                : 'Credentials are resolved by the factory/CredentialResolver — never pass secrets as arguments.',
        ];
    }

    /**
     * Search the whole package for types whose name or one-line purpose matches a keyword.
     * Returns matching classes, interfaces, enums, and traits with their summaries. Use it
     * to locate the right DTO, gateway, enum, or value object when you only know a concept.
     *
     * @param  string  $query  A keyword to match against type names and their docblock summaries.
     * @return array<string, mixed>
     */
    #[McpTool(name: 'search')]
    public function search(string $query): array
    {
        $needle = strtolower(trim($query));

        if ($needle === '') {
            return ['error' => 'empty_query'];
        }

        $results = [];

        foreach ($this->reflector->allTypes() as $fqcn) {
            $short = substr((string) strrchr($fqcn, '\\'), 1);
            $summary = $this->reflector->summaryOf($fqcn);

            if (str_contains(strtolower($short), $needle) || str_contains(strtolower($summary), $needle)) {
                $results[] = [
                    'name' => $fqcn,
                    'short_name' => $short,
                    'summary' => $summary,
                ];
            }

            if (count($results) >= 60) {
                break;
            }
        }

        return [
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ];
    }

    /**
     * Resolve a user-supplied gateway reference (backing value or enum case name) to a case.
     */
    private function resolveGateway(string $gateway): ?GatewayName
    {
        $gateway = trim($gateway);

        if (($case = GatewayName::tryFrom($gateway)) !== null) {
            return $case;
        }

        foreach (GatewayName::cases() as $candidate) {
            if (strcasecmp($candidate->name, $gateway) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Build the PHP snippet for one gateway + operation from the interface + request DTO.
     */
    private function renderTemplate(GatewayName $case, string $operation): string
    {
        $method = new ReflectionMethod(PaymentGatewayInterface::class, $operation);
        $uses = [
            'Hyprpay\\Payments\\Application\\PaymentGatewayFactory',
            'Hyprpay\\Payments\\Domain\\Enum\\GatewayName',
        ];

        $call = $this->renderCall($method, $uses);

        $returnType = $method->getReturnType();
        $returnFqcn = $this->hyprpayTypeName($returnType);

        if ($returnFqcn !== null) {
            $uses[] = $returnFqcn;
        }

        $uses = array_values(array_unique($uses));
        sort($uses);
        $useLines = implode("\n", array_map(static fn (string $u): string => "use {$u};", $uses));

        $returnDisplay = $this->renderReturnDisplay($returnType);

        return <<<PHP
        <?php

        {$useLines}

        /** @var PaymentGatewayFactory \$factory */
        \$gateway = \$factory->make(GatewayName::{$case->name});

        /** @var {$returnDisplay} \$result */
        \$result = \$gateway->{$call};
        PHP;
    }

    /**
     * Render a return type for a @var annotation, preferring the SDK's short class name.
     */
    private function renderReturnDisplay(?\ReflectionType $type): string
    {
        if (! $type instanceof ReflectionNamedType) {
            return 'mixed';
        }

        $name = $type->getName();
        $nullable = $type->allowsNull() && $name !== 'null' && $name !== 'mixed';
        $short = str_starts_with($name, 'Hyprpay\\Payments\\') ? $this->shortName($name) : $name;

        return ($nullable ? '?' : '').$short;
    }

    /**
     * Render the operation call, expanding a single request-DTO argument when present.
     *
     * @param  list<string>  $uses
     */
    private function renderCall(ReflectionMethod $method, array &$uses): string
    {
        $parameters = $method->getParameters();

        if (count($parameters) === 1 && ($dto = $this->hyprpayTypeName($parameters[0]->getType())) !== null) {
            $uses[] = $dto;

            return sprintf("%s(new %s(\n%s\n))", $method->getName(), $this->shortName($dto), $this->renderDtoArgs($dto, $uses));
        }

        $args = [];
        foreach ($parameters as $parameter) {
            $args[] = $this->placeholder($parameter, $uses);
        }

        return sprintf('%s(%s)', $method->getName(), implode(', ', $args));
    }

    /**
     * Render the named-argument body for a request DTO constructor, one argument per line.
     *
     * Required arguments are emitted live; optional ones are shown commented with their default.
     *
     * @param  list<string>  $uses
     */
    private function renderDtoArgs(string $dtoFqcn, array &$uses): string
    {
        $constructor = (new \ReflectionClass($dtoFqcn))->getConstructor();

        if ($constructor === null) {
            return '';
        }

        $lines = [];
        foreach ($constructor->getParameters() as $parameter) {
            $value = $this->placeholder($parameter, $uses);
            $line = sprintf('    %s: %s,', $parameter->getName(), $value);

            $lines[] = $parameter->isOptional() ? '    // '.ltrim($line) : $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Produce a placeholder expression for a parameter, based on its type.
     *
     * @param  list<string>  $uses
     */
    private function placeholder(ReflectionParameter $parameter, array &$uses): string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            return "'…'";
        }

        $name = $type->getName();

        if ($name === 'Hyprpay\\Payments\\Domain\\ValueObject\\Money') {
            $uses[] = $name;

            return "Money::minor(10000, 'USD')";
        }

        if (enum_exists($name)) {
            $uses[] = $name;
            $first = (new ReflectionEnum($name))->getCases()[0] ?? null;

            return $first !== null ? $this->shortName($name).'::'.$first->getName() : "'…'";
        }

        if (str_starts_with($name, 'Hyprpay\\Payments\\')) {
            $uses[] = $name;

            return 'new '.$this->shortName($name).'(/* … */)';
        }

        return match ($name) {
            'string' => "'<".$this->kebab($parameter->getName()).">'",
            'int' => $parameter->isDefaultValueAvailable() ? (string) (int) $parameter->getDefaultValue() : '0',
            'float' => '0.0',
            'bool' => $parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() ? 'true' : 'false',
            'array' => '[]',
            default => 'null',
        };
    }

    /**
     * The fully-qualified Hyprpay type behind a reflection type, or null if it is not one.
     */
    private function hyprpayTypeName(?\ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType && str_starts_with($type->getName(), 'Hyprpay\\Payments\\')) {
            return $type->getName();
        }

        return null;
    }

    /**
     * The trailing short name of a fully-qualified class name.
     */
    private function shortName(string $fqcn): string
    {
        return substr((string) strrchr('\\'.$fqcn, '\\'), 1);
    }

    /**
     * Convert a camelCase identifier to a kebab-case placeholder token.
     */
    private function kebab(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $value));
    }

    /**
     * Decode a JSON file into an associative array, tolerating a missing file.
     *
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
