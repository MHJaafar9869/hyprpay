<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Mcp;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use ReflectionClass;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Reflects the live `Hyprpay\Payments\*` classes into JSON-serialisable structures.
 *
 * Every fact this class reports (the gateway roster, the operation set, the
 * per-gateway support matrix, DTO shapes) is derived from the real source at
 * runtime, so the MCP never drifts from the SDK it describes.
 */
final class PackageReflector
{
    private const NAMESPACE_PREFIX = 'Hyprpay\\Payments\\';

    /** @var list<string>|null */
    private ?array $typeCache = null;

    /**
     * Absolute path to the SDK package root (the repository root).
     */
    public function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Absolute path to the SDK's `src/` directory.
     */
    public function srcPath(): string
    {
        return $this->packageRoot().'/src';
    }

    /**
     * Every fully-qualified type name declared under `src/`, derived from PSR-4 paths.
     *
     * @return list<string>
     */
    public function allTypes(): array
    {
        if ($this->typeCache !== null) {
            return $this->typeCache;
        }

        $src = $this->srcPath();
        $types = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($src) + 1, -4);
            $types[] = self::NAMESPACE_PREFIX.str_replace('/', '\\', $relative);
        }

        sort($types);

        return $this->typeCache = $types;
    }

    /**
     * Resolve a user-supplied type reference to matching fully-qualified names.
     *
     * Accepts a fully-qualified name, a namespace-relative name (with or without the
     * `Hyprpay\Payments\` prefix), or a bare short name (matched case-insensitively).
     *
     * @return list<string>
     */
    public function findTypes(string $name): array
    {
        $name = ltrim(trim($name), '\\');

        if ($name === '') {
            return [];
        }

        foreach ([$name, self::NAMESPACE_PREFIX.$name] as $candidate) {
            if ($this->typeExists($candidate)) {
                return [$candidate];
            }
        }

        $lowerShort = strtolower($name);
        $matches = [];

        foreach ($this->allTypes() as $type) {
            $short = strtolower(substr((string) strrchr($type, '\\'), 1));

            if ($short === $lowerShort || strtolower($type) === $lowerShort) {
                $matches[] = $type;
            }
        }

        return $matches;
    }

    /**
     * Whether a class, interface, enum, or trait with this exact name exists.
     */
    public function typeExists(string $fqcn): bool
    {
        return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);
    }

    /**
     * The ordered list of payment operations declared by PaymentGatewayInterface.
     *
     * Excludes the two identity accessors (`name`, `credentials`) that every driver
     * implements regardless of the gateway's capabilities.
     *
     * @return list<string>
     */
    public function operations(): array
    {
        $reflection = new ReflectionClass(PaymentGatewayInterface::class);
        $operations = [];

        foreach ($reflection->getMethods() as $method) {
            if (! in_array($method->getName(), ['name', 'credentials'], true)) {
                $operations[] = $method->getName();
            }
        }

        return $operations;
    }

    /**
     * Map every GatewayName case to the concrete driver class the factory builds for it.
     *
     * Parsed from PaymentGatewayFactory's match expression (the authoritative wiring),
     * falling back to the package's naming convention when a case is not matched.
     *
     * @return array<string, string> Case name => driver FQCN.
     */
    public function gatewayClassMap(): array
    {
        $factory = $this->srcPath().'/Application/PaymentGatewayFactory.php';
        $map = [];

        if (is_file($factory)) {
            $source = (string) file_get_contents($factory);

            $useMap = [];
            if (preg_match_all('/^use\s+([^;]+);/m', $source, $uses)) {
                foreach ($uses[1] as $import) {
                    $import = trim($import);
                    $short = substr((string) strrchr('\\'.$import, '\\'), 1);
                    $useMap[$short] = $import;
                }
            }

            if (preg_match_all('/GatewayName::(\w+)\s*=>\s*new\s+(\w+)\s*\(/', $source, $arms, PREG_SET_ORDER)) {
                foreach ($arms as $arm) {
                    $map[$arm[1]] = $useMap[$arm[2]] ?? (self::NAMESPACE_PREFIX.$arm[2]);
                }
            }
        }

        foreach (GatewayName::cases() as $case) {
            if (! isset($map[$case->name])) {
                $map[$case->name] = sprintf(
                    '%sInfrastructure\\Gateway\\%s\\%sGateway',
                    self::NAMESPACE_PREFIX,
                    $case->name,
                    $case->name,
                );
            }
        }

        return $map;
    }

    /**
     * For a concrete gateway class, the operations it actually implements.
     *
     * An operation counts as supported when the driver (or a trait it uses) declares
     * the method itself, rather than inheriting AbstractPaymentGateway's rejecting stub.
     *
     * @return list<string>
     */
    public function supportedOperations(string $gatewayFqcn): array
    {
        if (! class_exists($gatewayFqcn)) {
            return [];
        }

        $supported = [];

        foreach ($this->operations() as $operation) {
            if (! method_exists($gatewayFqcn, $operation)) {
                continue;
            }

            $declaring = (new ReflectionMethod($gatewayFqcn, $operation))->getDeclaringClass()->getName();

            if ($declaring !== AbstractPaymentGateway::class) {
                $supported[] = $operation;
            }
        }

        return $supported;
    }

    /**
     * A compact roster of every gateway: identity, driver class, purpose, and capabilities.
     *
     * @return list<array<string, mixed>>
     */
    public function gatewayRoster(): array
    {
        $map = $this->gatewayClassMap();
        $roster = [];

        foreach (GatewayName::cases() as $case) {
            $fqcn = $map[$case->name];

            $roster[] = [
                'label' => $case->label(),
                'key' => $case->value,
                'enum_case' => 'GatewayName::'.$case->name,
                'driver_class' => $fqcn,
                'summary' => $this->summaryOf($fqcn),
                'supported_operations' => $this->supportedOperations($fqcn),
            ];
        }

        return $roster;
    }

    /**
     * Full structural detail for a single type: kind, docs, constructor, properties, methods.
     *
     * @return array<string, mixed>
     */
    public function describeType(string $fqcn): array
    {
        $reflection = new ReflectionClass($fqcn);
        $doc = $this->parseDocblock($reflection->getDocComment());

        $description = [
            'name' => $reflection->getName(),
            'short_name' => $reflection->getShortName(),
            'namespace' => $reflection->getNamespaceName(),
            'kind' => $this->kindOf($reflection),
            'summary' => $doc['summary'],
            'description' => $doc['body'],
        ];

        if (($parent = $reflection->getParentClass()) !== false) {
            $description['extends'] = $parent->getShortName();
        }

        $interfaces = array_map(fn (string $i): string => $this->shorten($i), array_values($reflection->getInterfaceNames()));
        if ($interfaces !== []) {
            $description['implements'] = $interfaces;
        }

        if ($reflection->isEnum()) {
            $description['enum'] = $this->describeEnum($fqcn);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getDeclaringClass()->getName() === $reflection->getName()) {
            $description['constructor'] = [
                'summary' => $this->parseDocblock($constructor->getDocComment())['summary'],
                'parameters' => $this->describeParameters($constructor),
            ];
        }

        $properties = $this->describeProperties($reflection);
        if ($properties !== []) {
            $description['properties'] = $properties;
        }

        $methods = $this->describeMethods($reflection);
        if ($methods !== []) {
            $description['methods'] = $methods;
        }

        if ($reflection->isSubclassOf(AbstractPaymentGateway::class) && ! $reflection->isAbstract()) {
            $description['supported_operations'] = $this->supportedOperations($fqcn);
        }

        return $description;
    }

    /**
     * The one-line docblock summary of a type, or an empty string when it has none.
     */
    public function summaryOf(string $fqcn): string
    {
        if (! $this->typeExists($fqcn)) {
            return '';
        }

        return $this->parseDocblock((new ReflectionClass($fqcn))->getDocComment())['summary'];
    }

    /**
     * The request-DTO / return type mapping for a single interface operation.
     *
     * @return array<string, mixed>
     */
    public function describeOperation(string $operation): array
    {
        $method = new ReflectionMethod(PaymentGatewayInterface::class, $operation);
        $doc = $this->parseDocblock($method->getDocComment());

        return [
            'operation' => $operation,
            'summary' => $doc['summary'],
            'description' => $doc['body'],
            'signature' => $this->renderSignature($method),
            'parameters' => $this->describeParameters($method),
            'returns' => [
                'type' => $this->typeToString($method->getReturnType()),
                'description' => $doc['return'],
            ],
        ];
    }

    /**
     * Reflect an enum's backing type and cases.
     *
     * @return array<string, mixed>
     */
    private function describeEnum(string $fqcn): array
    {
        $enum = new ReflectionEnum($fqcn);
        $cases = [];

        foreach ($enum->getCases() as $case) {
            $entry = ['name' => $case->getName()];

            if ($case instanceof \ReflectionEnumBackedCase) {
                $entry['value'] = $case->getBackingValue();
            }

            $summary = $this->parseDocblock($case->getDocComment())['summary'];
            if ($summary !== '') {
                $entry['summary'] = $summary;
            }

            $cases[] = $entry;
        }

        return [
            'backing_type' => $enum->isBacked() && $enum->getBackingType() !== null
                ? $this->typeToString($enum->getBackingType())
                : null,
            'cases' => $cases,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function describeParameters(ReflectionMethod $method): array
    {
        $paramDocs = $this->parseDocblock($method->getDocComment())['params'];
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $parameters[] = [
                'name' => $parameter->getName(),
                'type' => $this->typeToString($parameter->getType()),
                'optional' => $parameter->isOptional(),
                'default' => $this->renderDefault($parameter),
                'promoted' => $parameter->isPromoted(),
                'description' => $paramDocs[$parameter->getName()] ?? '',
            ];
        }

        return $parameters;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return list<array<string, mixed>>
     */
    private function describeProperties(ReflectionClass $reflection): array
    {
        $properties = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $properties[] = [
                'name' => $property->getName(),
                'type' => $this->typeToString($property->getType()),
                'readonly' => $property->isReadOnly(),
                'summary' => $this->parseDocblock($property->getDocComment())['summary'],
            ];
        }

        return $properties;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return list<array<string, mixed>>
     */
    private function describeMethods(ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() && $method->getName() === '__construct') {
                continue;
            }

            if ($method->getName() === '__construct') {
                continue;
            }

            $declaring = $method->getDeclaringClass()->getName();

            $entry = [
                'name' => $method->getName(),
                'static' => $method->isStatic(),
                'signature' => $this->renderSignature($method),
                'summary' => $this->parseDocblock($method->getDocComment())['summary'],
            ];

            if ($declaring !== $reflection->getName()) {
                $entry['inherited_from'] = $this->shorten($declaring);
            }

            $methods[] = $entry;
        }

        return $methods;
    }

    /**
     * Render a method's PHP signature as a single readable line.
     */
    private function renderSignature(ReflectionMethod $method): string
    {
        $parts = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $this->typeToString($parameter->getType());
            $fragment = ($type !== 'mixed' ? $type.' ' : '').'$'.$parameter->getName();

            if ($parameter->isOptional()) {
                $fragment .= ' = '.$this->renderDefault($parameter);
            }

            $parts[] = $fragment;
        }

        $return = $this->typeToString($method->getReturnType());

        return sprintf('%s(%s): %s', $method->getName(), implode(', ', $parts), $return);
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function kindOf(ReflectionClass $reflection): string
    {
        return match (true) {
            $reflection->isEnum() => 'enum',
            $reflection->isInterface() => 'interface',
            $reflection->isTrait() => 'trait',
            $reflection->isAbstract() => 'abstract class',
            default => 'class',
        };
    }

    /**
     * Render a type declaration (named, nullable, union, or intersection) as a string.
     */
    private function typeToString(?ReflectionType $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn (ReflectionType $t): string => $this->typeToString($t), $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(fn (ReflectionType $t): string => $this->typeToString($t), $type->getTypes()));
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $nullable = $type->allowsNull() && $name !== 'null' && $name !== 'mixed';

            return ($nullable ? '?' : '').$this->shorten($name);
        }

        return (string) $type;
    }

    /**
     * Reduce a fully-qualified name to its trailing short name, leaving scalars untouched.
     */
    private function shorten(string $name): string
    {
        return str_contains($name, '\\') ? substr((string) strrchr($name, '\\'), 1) : $name;
    }

    /**
     * Render a parameter's default value as a PHP literal, or null when it has none.
     */
    private function renderDefault(ReflectionParameter $parameter): ?string
    {
        if (! $parameter->isDefaultValueAvailable()) {
            return null;
        }

        if ($parameter->isDefaultValueConstant()) {
            return (string) $parameter->getDefaultValueConstantName();
        }

        return $this->renderValue($parameter->getDefaultValue());
    }

    /**
     * Render an arbitrary PHP value as a compact source literal.
     */
    private function renderValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_string($value) => "'".str_replace("'", "\\'", $value)."'",
            is_array($value) => $value === [] ? '[]' : json_encode($value, JSON_UNESCAPED_SLASHES),
            is_int($value), is_float($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
        };
    }

    /**
     * Parse a raw docblock into its summary, body, @param map, and @return description.
     *
     * @return array{summary: string, body: string, params: array<string, string>, return: string}
     */
    public function parseDocblock(string|false $raw): array
    {
        $empty = ['summary' => '', 'body' => '', 'params' => [], 'return' => ''];

        if ($raw === false || $raw === '') {
            return $empty;
        }

        $stripped = preg_replace('/^\s*\/\*\*|\*\/\s*$/', '', $raw);
        $lines = preg_split('/\R/', (string) $stripped) ?: [];

        $clean = [];
        foreach ($lines as $line) {
            $clean[] = preg_replace('/^\s*\*\s?/', '', $line);
        }

        $summary = [];
        $body = [];
        $params = [];
        $return = '';
        $inSummary = true;

        foreach ($clean as $line) {
            $trimmed = trim((string) $line);

            if (str_starts_with($trimmed, '@param')) {
                if (preg_match('/@param\s+(\S+)\s+\$(\w+)\s*(.*)/', $trimmed, $m) === 1) {
                    $params[$m[2]] = trim($m[3]);
                }
                $inSummary = false;

                continue;
            }

            if (str_starts_with($trimmed, '@return')) {
                if (preg_match('/@return\s+\S+\s*(.*)/', $trimmed, $m) === 1) {
                    $return = trim($m[1]);
                }
                $inSummary = false;

                continue;
            }

            if (str_starts_with($trimmed, '@')) {
                $inSummary = false;

                continue;
            }

            if ($inSummary) {
                if ($trimmed === '' && $summary !== []) {
                    $inSummary = false;

                    continue;
                }

                if ($trimmed !== '') {
                    $summary[] = $trimmed;
                }

                continue;
            }

            $body[] = $trimmed;
        }

        return [
            'summary' => trim(implode(' ', $summary)),
            'body' => trim(implode("\n", $body)),
            'params' => $params,
            'return' => $return,
        ];
    }
}
