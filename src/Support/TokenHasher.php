<?php

declare(strict_types=1);

namespace Naf\MCP\Support;

final class TokenHasher
{
    public function hash(string $token): string
    {
        return password_hash($token, PASSWORD_DEFAULT);
    }

    public function verify(string $token, string $hash): bool
    {
        return password_verify($token, $hash);
    }
}
