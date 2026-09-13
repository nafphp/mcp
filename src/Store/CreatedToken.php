<?php

declare(strict_types=1);

namespace Naf\MCP\Store;

final class CreatedToken
{
    public function __construct(
        public readonly TokenRecord $record,
        public readonly string $plainToken,
    ) {}
}
