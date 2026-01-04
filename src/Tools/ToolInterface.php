<?php

declare(strict_types=1);

namespace NixPHP\MCP\Tools;

interface ToolInterface
{
    public function name(): string;
    public function description(): string;
    public function inputSchema(): array;
    public function handle(array $args): mixed;

}