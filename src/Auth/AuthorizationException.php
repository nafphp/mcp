<?php

declare(strict_types=1);

namespace Naf\MCP\Auth;

use RuntimeException;

final class AuthorizationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 403)
    {
        parent::__construct($message);
    }
}
