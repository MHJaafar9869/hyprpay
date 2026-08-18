<?php

/**
 * hyprpay/payments developer MCP server.
 *
 * Exposes the SDK's surface (gateways, operations, request/result DTOs, enums,
 * value objects) to AI agents as MCP tools over stdio. Every tool reflects the
 * live `Hyprpay\Payments\*` classes, so it never drifts from the package.
 *
 * Run:  php mcp/server.php
 * It speaks JSON-RPC on stdin/stdout; keep application output off stdout.
 */

declare(strict_types=1);

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$sdkAutoload = dirname(__DIR__).'/vendor/autoload.php';
$mcpAutoload = __DIR__.'/vendor/autoload.php';

foreach ([$sdkAutoload, $mcpAutoload] as $autoload) {
    if (! is_file($autoload)) {
        fwrite(STDERR, "Missing autoloader: {$autoload}\nRun `composer install` in the package root and in mcp/.\n");
        exit(1);
    }

    require $autoload;
}

$server = Server::make()
    ->withServerInfo('hyprpay-payments', '0.1.0')
    ->withInstructions(
        'Developer tools for the hyprpay/payments PHP SDK. Call get_sdk_overview first, '
        .'then list_gateways, get_operation_details, get_class_details, get_code_template, and search '
        .'to explore the package and generate correct integration code.'
    )
    ->build();

$server->discover(__DIR__, ['src']);

$server->listen(new StdioServerTransport);
