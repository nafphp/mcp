<?php

declare(strict_types=1);

namespace Naf\MCP\Support;

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

        $result = self::text(json_encode($payload, $flags | JSON_THROW_ON_ERROR), $isError);
        if (is_object($payload) || (is_array($payload) && !array_is_list($payload))) {
            $result['structuredContent'] = $payload;
        }

        return $result;
    }

    public static function normalize(mixed $payload): array
    {
        if (is_array($payload) && isset($payload['content']) && is_array($payload['content']) && array_is_list($payload['content'])) {
            return $payload;
        }

        return self::json($payload);
    }

    public static function error(string $message): array
    {
        return self::text($message, true);
    }
}
