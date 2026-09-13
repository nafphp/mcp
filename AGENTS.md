# Working on naf/mcp

NAF is a small PHP framework with optional Composer plugins. Its core owns boot,
configuration, the service container, routing, events and PSR-7 responses. Prefer existing
NAF helpers, services and extension interfaces; keep application business rules in the host.
This package declares `type: naf-plugin` and is discovered after installation in a NAF host.
The plugin repository itself is not the application's web root.

Before changing code, read the [shared contribution workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md)
and [release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md).
In the multi-repository workspace, the same documents are in the sibling `docs/` checkout;
use the linked copies when working from a standalone clone. Preserve other contributors' work.
Review and update user documentation with every behavior change. Source fixes use an RC branch;
verified documentation-only changes can be merged and published by the agent.

## What this plugin does

`naf/mcp` exposes registered executable tools over the host's `/mcp` endpoint. Install with
`composer require naf/mcp`. Token authentication is enabled by default; configure `mcp:auth`
and register tools in host/plugin bootstrap. Optional `naf/cli` provides token commands.

## Use it

This small, read-only tool shows the real registration contract:

```php
<?php
use Naf\MCP\Support\ToolResult;
use Naf\MCP\Tools\ToolInterface;
use function Naf\MCP\tool;

tool()->register(new class implements ToolInterface {
    public function name(): string { return 'app_ping'; }
    public function description(): string { return 'Check that this application responds.'; }
    public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function handle(array $args): mixed { return ToolResult::text('pong'); }
});
```

Add `ScopedToolInterface::requiredScopes()` for restricted tools. Validate arguments and
call existing application/domain services inside the tool; metadata alone does not validate
business input. Derive capability lists from registrations instead of maintaining duplicates.

## Change it here

[ToolRegistry](src/Support/ToolRegistry.php), [tool contracts](src/Tools/),
[MCPRouter](src/Core/MCPRouter.php), [MCPController](src/Core/MCPController.php),
[authenticators](src/Auth/) and [token stores](src/Store/) are the extension points.
Token secrets are hashed in the store. Check the current scope semantics: the registry uses
`canAny()` and a null identity does not enforce scope restrictions. HTTP callers must pass
through the authenticator; direct registry calls are not proof of authenticated access.
Do not use disabling auth as a setup shortcut for publicly reachable endpoints.

## Verify

Run `composer test` and `composer validate --strict`. Use [tests](tests/) for token lifecycle,
JSON-RPC, tool listing/calling and scope filtering. Verify unauthenticated, unauthorized and
allowed calls through the HTTP endpoint when changing the boundary. No `analyse` script exists.

User docs: [MCP](https://nafphp.github.io/docs/mcp/).
