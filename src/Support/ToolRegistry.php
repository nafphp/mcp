<?php

declare(strict_types=1);

namespace Naf\MCP\Support;

use InvalidArgumentException;
use Naf\MCP\Auth\AuthorizationException;
use Naf\MCP\Auth\McpIdentity;
use Naf\MCP\Tools\ScopedToolInterface;
use Naf\MCP\Tools\ToolInterface;
use Naf\MCP\Tools\ToolMetadataInterface;
use Naf\MCP\Tools\UserToolInterface;
use Opis\JsonSchema\CompliantValidator;
use RuntimeException;

final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function __construct(private readonly ?string $discoveryScope = null)
    {
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** Tool definitions for tools/list */
    public function definitions(?McpIdentity $identity = null): array
    {
        $result      = [];
        $canDiscover = $identity !== null && !$identity->userLinked
            && $this->discoveryScope !== null && $identity->can($this->discoveryScope);
        foreach ($this->tools as $tool) {
            if ($this->isAllowed($tool, $identity) || $canDiscover) {
                $definition = [
                    'name'        => $tool->name(),
                    'description' => $tool->description(),
                    'inputSchema' => $this->ensureValidInputSchema($tool->inputSchema()),
                ];
                if ($tool instanceof ToolMetadataInterface) {
                    $definition += array_intersect_key($tool->metadata(), array_flip(['title', 'annotations', 'outputSchema', '_meta']));
                }
                $result[] = $definition;
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
                'properties'           => (object) [],
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
        [$tool, $arguments] = $this->prepare($name, json_decode(json_encode((object) $args, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR), $identity);

        return $tool->handle($arguments);
    }

    /** Validate the original JSON object before converting it to the PHP tool contract. */
    public function prepare(string $name, object $args, ?McpIdentity $identity = null): array
    {
        $tool = $this->getTool($name);
        $this->authorize($tool, $identity);
        $schema = json_decode(json_encode($this->ensureValidInputSchema($tool->inputSchema()), JSON_THROW_ON_ERROR));
        $result = (new CompliantValidator())->validate($args, $schema);
        if (!$result->isValid()) {
            throw new InvalidArgumentException('Tool arguments do not match the input schema.');
        }

        return [$tool, json_decode(json_encode($args, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)];
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function normalizeResult(ToolInterface $tool, mixed $payload): array
    {
        $result = ToolResult::normalize($payload);
        $schema = $tool instanceof ToolMetadataInterface ? ($tool->metadata()['outputSchema'] ?? null) : null;
        if ($schema !== null && ($result['isError'] ?? false) !== true) {
            $content = $result['structuredContent'] ?? null;
            if ($content === null || !(new CompliantValidator())->validate(
                json_decode(json_encode($content, JSON_THROW_ON_ERROR)),
                json_decode(json_encode($schema, JSON_THROW_ON_ERROR)),
            )->isValid()) {
                throw new RuntimeException('Tool output does not match its output schema.');
            }
        }
        // Validate encodability before a streaming response starts emitting this result.
        json_encode($result, JSON_THROW_ON_ERROR);

        return $result;
    }

    public function authorize(ToolInterface $tool, ?McpIdentity $identity): void
    {
        if ($tool instanceof UserToolInterface && !($identity?->userLinked ?? false)) {
            throw new AuthorizationException('A linked user is required to call this tool.', 401);
        }
        if (!$this->isAllowed($tool, $identity)) {
            throw new AuthorizationException('Not allowed to call this tool.');
        }
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
        if ($tool instanceof UserToolInterface && !($identity?->userLinked ?? false)) {
            return false;
        }
        if (!$tool instanceof ScopedToolInterface) {
            return true;
        }

        return $identity === null || $identity->canAny($tool->requiredScopes());
    }
}
