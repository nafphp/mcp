<?php

declare(strict_types=1);

namespace Naf\MCP\Support;

use Naf\MCP\Auth\McpIdentity;
use Naf\MCP\Tools\ScopedToolInterface;
use Naf\MCP\Tools\ToolInterface;
use RuntimeException;

final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** Tool definitions for tools/list */
    public function definitions(?McpIdentity $identity = null): array
    {
        $result = [];
        foreach ($this->tools as $tool) {
            if ($this->isAllowed($tool, $identity)) {
                $result[] = [
                    'name'        => $tool->name(),
                    'description' => $tool->description(),
                    'inputSchema' => $this->ensureValidInputSchema($tool->inputSchema()),
                ];
            }
        }

        return $result;
    }

    /**
     * Ensures that a tool input schema is a valid JSON Schema object.
     * If the schema is empty or invalid, returns a minimal valid object schema.
     */
    private function ensureValidInputSchema(array $schema): array
    {
        // Ensure we have a valid type field with a string value
        if (!isset($schema['type']) || !is_string($schema['type']) || $schema['type'] === '') {
            return [
                'type'                 => 'object',
                'properties'           => [],
                'additionalProperties' => false,
            ];
        }

        // Ensure object type has required fields
        if ($schema['type'] === 'object') {
            $schema['properties']           = $schema['properties'] ?? [];
            $schema['additionalProperties'] = $schema['additionalProperties'] ?? false;

            if ($schema['properties'] === []) {
                $schema['properties'] = (object) [];
            }
        }

        return $schema;
    }

    public function call(string $name, array $args, ?McpIdentity $identity = null): mixed
    {
        if (!isset($this->tools[$name])) {
            throw new RuntimeException("Unknown tool: $name");
        }

        $tool = $this->getTool($name);
        if (!$this->isAllowed($tool, $identity)) {
            throw new RuntimeException("Not allowed to call tool: $name");
        }

        return $tool->handle($args);
    }

    public function getTool(string $name): ToolInterface
    {
        if (empty($this->tools[$name])) {
            throw new RuntimeException("Unknown tool: $name");
        }

        return $this->tools[$name];
    }

    private function isAllowed(ToolInterface $tool, ?McpIdentity $identity): bool
    {
        if (!$tool instanceof ScopedToolInterface) {
            return true;
        }

        return $identity === null || $identity->canAny($tool->requiredScopes());
    }
}
