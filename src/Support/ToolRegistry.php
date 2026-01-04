<?php

declare(strict_types=1);

namespace NixPHP\MCP\Support;

use NixPHP\MCP\Tools\ToolInterface;

final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** Tool definitions for tools/list */
    public function definitions(): array
    {
        return array_values(array_map(
            fn(ToolInterface $t) => [
                'name' => $t->name(),
                'description' => $t->description(),
                'inputSchema' => $t->inputSchema(),
            ],
            $this->tools
        ));
    }

    public function call(string $name, array $args): mixed
    {
        if (!isset($this->tools[$name])) {
            throw new \RuntimeException("Unknown tool: $name");
        }

        return $this->getTool($name)->handle($args);
    }

    public function getTool(string $name): ToolInterface
    {
        if (empty($this->tools[$name])) {
            throw new \RuntimeException("Unknown tool: $name");
        }

        return $this->tools[$name];
    }
}
