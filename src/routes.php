<?php

declare(strict_types=1);

use Naf\MCP\Core\MCPController;

use function Naf\route;

route()->add('GET', '/mcp', [MCPController::class, 'get'], 'mcp_server_stream');
route()->add('POST', '/mcp', [MCPController::class, 'post'], 'mcp_server_rpc');
route()->add('GET', '/.well-known/oauth-protected-resource', [MCPController::class, 'metadata'], 'mcp_resource_metadata');
route()->add('GET', '/.well-known/oauth-protected-resource/mcp', [MCPController::class, 'metadata'], 'mcp_resource_metadata_path');
