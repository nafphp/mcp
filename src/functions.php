<?php

declare(strict_types=1);

namespace NixPHP\MCP;

use NixPHP\MCP\Store\TokenStoreInterface;
use NixPHP\MCP\Support\ToolRegistry;
use function NixPHP\app;

function tool(): ToolRegistry
{
    return app()->container()->get(ToolRegistry::class);
}

function tokens(): TokenStoreInterface
{
    return app()->container()->get(TokenStoreInterface::class);
}
