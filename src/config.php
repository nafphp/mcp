<?php

declare(strict_types=1);

return [
    'mcp' => [
        'auth' => [
            'enabled' => true,
            'driver' => 'file',
            'token_file' => 'ENV:MCP_TOKEN_FILE',
        ],
    ],
];
