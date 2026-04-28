<?php

declare(strict_types=1);

namespace NixPHP\MCP\Store;

interface TokenStoreInterface
{
    /**
     * @param array<int, string> $scopes
     */
    public function create(string $name, array $scopes = ['*'], ?\DateTimeInterface $expiresAt = null): CreatedToken;

    public function findByToken(string $plainToken): ?TokenRecord;

    public function find(string $id): ?TokenRecord;

    /**
     * @return array<int, TokenRecord>
     */
    public function all(): array;

    public function revoke(string $id): bool;

    public function touch(string $id): void;
}
