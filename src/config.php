<?php

declare(strict_types=1);

return [
    'csrf_exempt_routes' => ['mcp_server_rpc' => true],
    'mcp'                => [
        'transport' => [
            'streaming'         => false,
            'allowed_origins'   => [],
            'max_request_bytes' => 1048576,
        ],
        'oauth' => [
            'resource'              => null,
            'authorization_servers' => [],
            'scopes_supported'      => null,
        ],
        'auth' => [
            'enabled'         => true,
            'driver'          => 'file',
            'token_file'      => 'ENV:MCP_TOKEN_FILE',
            'discovery_scope' => null,
        ],
    ],
];
