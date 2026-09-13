<?php

declare(strict_types=1);

namespace Naf\MCP\Auth;

final class McpIdentity
{
    /**
     * @param array<int, string> $scopes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $scopes = [],
    ) {}

    public function can(string $scope): bool
    {
        foreach ($this->scopes as $granted) {
            if ($granted === '*' || $granted === $scope) {
                return true;
            }

            if (str_ends_with($granted, ':*') && str_starts_with($scope, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $scopes
     */
    public function canAny(array $scopes): bool
    {
        if ($scopes === []) {
            return true;
        }

        foreach ($scopes as $scope) {
            if ($this->can($scope)) {
                return true;
            }
        }

        return false;
    }
}
