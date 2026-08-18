# hyprpay/payments developer MCP

A [Model Context Protocol](https://modelcontextprotocol.io) server that exposes this SDK's
surface to AI agents as tools, so an assistant can explore the package and generate correct
integration code. It is the hyprpay analogue of the CyberSource developer MCP — a *developer*
tool (docs/codegen), not a runtime payment gateway.

Everything it reports is reflected from the live `Hyprpay\Payments\*` classes at call time, so
it never drifts from the code.

## Tools

| Tool | Purpose |
|---|---|
| `get_sdk_overview` | Orientation: what the SDK is, install/config, gateway roster, tool index. |
| `list_gateways` | Every gateway with its `GatewayName` key, driver class, and supported operations. |
| `get_operation_details` | An operation's request DTO, return shape, and which gateways support it. |
| `get_class_details` | Full reflection of any class, interface, enum, or trait (short name or FQCN). |
| `get_code_template` | A ready-to-adapt PHP snippet for a gateway + operation, imports included. |
| `search` | Find types by name or one-line purpose across the package. |

## Setup

The server needs both the SDK's dependencies and its own:

```bash
composer install                 # in the package root (installs the SDK + framework deps)
composer install --working-dir=mcp   # installs php-mcp/server for this server
```

`server.php` loads both autoloaders, so the SDK classes reflect cleanly.

## Registering with Claude Code

A project-scoped `.mcp.json` at the repository root already registers this server for anyone
who clones the package:

```json
{ "mcpServers": { "hyprpay-payments": { "type": "stdio", "command": "php", "args": ["mcp/server.php"] } } }
```

To register it globally (available in every project on your machine), the way a published SDK
tool would be:

```bash
claude mcp add -s user hyprpay-payments -- php /absolute/path/to/mcp/server.php
```

## Running it directly

```bash
php mcp/server.php
```

It speaks JSON-RPC over stdio; keep application output off stdout (errors go to stderr).
