<?php

declare(strict_types=1);

namespace Naf\MCP\Store;

final class TokenRecord
{
    /**
     * @param array<int, string> $scopes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $hash,
        public readonly array $scopes,
        public readonly string $createdAt,
        public readonly ?string $expiresAt = null,
        public readonly ?string $revokedAt = null,
        public readonly ?string $lastUsedAt = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string)($data['id'] ?? ''),
            name: (string)($data['name'] ?? ''),
            hash: (string)($data['hash'] ?? ''),
            scopes: array_values(array_map('strval', is_array($data['scopes'] ?? null) ? $data['scopes'] : [])),
            createdAt: (string)($data['created_at'] ?? ''),
            expiresAt: isset($data['expires_at']) ? (string)$data['expires_at'] : null,
            revokedAt: isset($data['revoked_at']) ? (string)$data['revoked_at'] : null,
            lastUsedAt: isset($data['last_used_at']) ? (string)$data['last_used_at'] : null,
        );
    }

    public function isActive(): bool
    {
        if ($this->id === '' || $this->hash === '' || $this->revokedAt !== null) {
            return false;
        }

        if ($this->expiresAt === null || $this->expiresAt === '') {
            return true;
        }

        return strtotime($this->expiresAt) > time();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'hash' => $this->hash,
            'scopes' => $this->scopes,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'revoked_at' => $this->revokedAt,
            'last_used_at' => $this->lastUsedAt,
        ];
    }
}
