<?php

declare(strict_types=1);

namespace NixPHP\MCP\Support;

class ToolResult
{
    public static function text(string $text, bool $isError = false): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => $text,
            ]],
            'isError' => $isError,
        ];
    }

    public static function json(mixed $payload, bool $pretty = true, bool $isError = false): array
    {
        if (is_string($payload)) {
            return self::text($payload, $isError);
        }

        $flags = JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0);

        return self::text(json_encode($payload, $flags) ?: 'null', $isError);
    }

    public static function error(string $message): array
    {
        return self::text($message, true);
    }
}
