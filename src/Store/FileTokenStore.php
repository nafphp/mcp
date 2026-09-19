<?php

declare(strict_types=1);

namespace Naf\MCP\Store;

use DateTimeImmutable;
use DateTimeInterface;
use Naf\MCP\Support\TokenGenerator;
use Naf\MCP\Support\TokenHasher;
use RuntimeException;

final class FileTokenStore implements TokenStoreInterface
{
    public function __construct(
        private readonly string $path,
        private readonly TokenHasher $hasher = new TokenHasher(),
        private readonly TokenGenerator $generator = new TokenGenerator(),
    ) {
    }

    public function create(string $name, array $scopes = ['*'], ?DateTimeInterface $expiresAt = null): CreatedToken
    {
        $plainToken = $this->generator->generate();
        $now        = $this->now();

        $normalizedScopes = array_values(array_unique(array_filter(array_map('strval', $scopes))));
        if ($normalizedScopes === []) {
            $normalizedScopes = ['*'];
        }

        $record = new TokenRecord(
            id: 'tok_' . bin2hex(random_bytes(12)),
            name: trim($name) !== '' ? trim($name) : 'MCP token',
            hash: $this->hasher->hash($plainToken),
            scopes: $normalizedScopes,
            createdAt: $now,
            expiresAt: $expiresAt?->format(DATE_ATOM),
        );

        $this->mutate(function (array $data) use ($record): array {
            $data['tokens'][] = $record->toArray();

            return $data;
        });

        return new CreatedToken($record, $plainToken);
    }

    public function findByToken(string $plainToken): ?TokenRecord
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return null;
        }

        foreach ($this->read()['tokens'] as $item) {
            $record = TokenRecord::fromArray($item);
            if (!$record->isActive()) {
                continue;
            }

            if ($this->hasher->verify($plainToken, $record->hash)) {
                return $record;
            }
        }

        return null;
    }

    public function find(string $id): ?TokenRecord
    {
        foreach ($this->read()['tokens'] as $item) {
            $record = TokenRecord::fromArray($item);
            if ($record->id === $id) {
                return $record;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_map(
            fn(array $item) => TokenRecord::fromArray($item),
            $this->read()['tokens'],
        );
    }

    public function revoke(string $id): bool
    {
        $revoked = false;
        $now     = $this->now();

        $this->mutate(function (array $data) use ($id, $now, &$revoked): array {
            foreach ($data['tokens'] as &$item) {
                if (($item['id'] ?? null) !== $id) {
                    continue;
                }

                $item['revoked_at'] = $now;
                $revoked            = true;
            }

            return $data;
        });

        return $revoked;
    }

    public function touch(string $id): void
    {
        $now = $this->now();

        $this->mutate(function (array $data) use ($id, $now): array {
            foreach ($data['tokens'] as &$item) {
                if (($item['id'] ?? null) === $id) {
                    $item['last_used_at'] = $now;
                    break;
                }
            }

            return $data;
        });
    }

    /**
     * @return array{tokens: array<int, array<string, mixed>>}
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return ['tokens' => []];
        }

        $json = file_get_contents($this->path);
        if ($json === false || trim($json) === '') {
            return ['tokens' => []];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid MCP token store JSON.');
        }

        $tokens = $data['tokens'] ?? [];
        if (!is_array($tokens)) {
            throw new RuntimeException('Invalid MCP token store structure.');
        }

        return ['tokens' => array_values($tokens)];
    }

    /**
     * @param callable(array{tokens: array<int, array<string, mixed>>}): array{tokens: array<int, array<string, mixed>>} $callback
     */
    private function mutate(callable $callback): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create MCP token store directory.');
        }

        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open MCP token store.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock MCP token store.');
            }

            $raw  = stream_get_contents($handle);
            $data = ['tokens' => []];
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Invalid MCP token store JSON.');
                }
                $data = ['tokens' => array_values(is_array($decoded['tokens'] ?? null) ? $decoded['tokens'] : [])];
            }

            $data = $callback($data);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{"tokens":[]}');
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
