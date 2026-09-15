<?php

declare(strict_types=1);

namespace Naf\MCP;

use Naf\MCP\Store\TokenStoreInterface;
use Naf\MCP\Support\ToolRegistry;

use function Naf\app;

function tool(): ToolRegistry
{
    return app()->container()->get(ToolRegistry::class);
}

function tokens(): TokenStoreInterface
{
    return app()->container()->get(TokenStoreInterface::class);
}
