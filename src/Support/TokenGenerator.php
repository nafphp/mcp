<?php

declare(strict_types=1);

namespace NixPHP\MCP\Support;

final class TokenGenerator
{
    public function generate(): string
    {
        return 'mcp_' . bin2hex(random_bytes(32));
    }
}
