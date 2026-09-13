<?php

declare(strict_types=1);

namespace Naf\MCP\Tools;

interface ScopedToolInterface
{
    /**
     * @return array<int, string>
     */
    public function requiredScopes(): array;
}
