<?php

declare(strict_types=1);

namespace Naf\MCP\Support;

use JsonSerializable;

class Schema implements JsonSerializable
{
    private array $schema;

    private function __construct(array $schema)
    {
        $this->schema = $schema;
    }

    public static function object(): self
    {
        return new self(['type' => 'object', 'properties' => [], 'additionalProperties' => false]);
    }

    public static function string(): self
    {
        return new self(['type' => 'string']);
    }

    public static function integer(): self
    {
        return new self(['type' => 'integer']);
    }

    public static function boolean(): self
    {
        return new self(['type' => 'boolean']);
    }

    public static function array(Schema $items): self
    {
        return new self(['type' => 'array', 'items' => $items->toArray()]);
    }

    public function prop(string $name, Schema $schema): self
    {
        $this->schema['properties'][$name] = $schema->toArray();

        return $this;
    }

    public function required(string ...$names): self
    {
        $this->schema['required'] = array_values(array_unique([
            ...($this->schema['required'] ?? []),
            ...$names,
        ]));

        return $this;
    }

    public function nullable(): self
    {
        if (isset($this->schema['type'])) {
            $type = $this->schema['type'];

            if (is_array($type)) {
                if (!in_array('null', $type, true)) {
                    $type[] = 'null';
                }
                $this->schema['type'] = $type;
            } else {
                $this->schema['type'] = [$type, 'null'];
            }
        } else {
            $this->schema['type'] = ['null'];
        }

        return $this;
    }

    public function additionalProperties(bool $value): self
    {
        $this->schema['additionalProperties'] = $value;

        return $this;
    }

    public function description(string $text): self
    {
        $this->schema['description'] = $text;

        return $this;
    }

    public function enum(array $values): self
    {
        $this->schema['enum'] = array_values($values);

        return $this;
    }

    public function min(int $v): self
    {
        $this->schema['minimum'] = $v;

        return $this;
    }

    public function max(int $v): self
    {
        $this->schema['maximum'] = $v;

        return $this;
    }

    public function default(mixed $v): self
    {
        $this->schema['default'] = $v;

        return $this;
    }

    public function toArray(): array
    {
        return $this->schema;
    }

    public function jsonSerialize(): array
    {
        return $this->schema;
    }

    public function toJson(): string
    {
        return json_encode($this->schema, JSON_UNESCAPED_UNICODE);
    }
}
