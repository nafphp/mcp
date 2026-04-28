<?php

declare(strict_types=1);

namespace NixPHP\MCP\Tools;

interface ScopedToolInterface
{
    /**
     * @return array<int, string>
     */
    public function requiredScopes(): array;
}
