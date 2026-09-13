<?php

declare(strict_types=1);

namespace Naf\MCP\Auth;

use Naf\MCP\Store\TokenStoreInterface;
use Psr\Http\Message\RequestInterface;

final class BearerTokenAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly TokenStoreInterface $tokens,
        private readonly bool $enabled = true,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function authenticate(RequestInterface $request): ?McpIdentity
    {
        if (!$this->enabled) {
            return null;
        }

        $header = trim($request->getHeaderLine('Authorization'));
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        $record = $this->tokens->findByToken(trim($matches[1]));
        if ($record === null) {
            return null;
        }

        $this->tokens->touch($record->id);

        return new McpIdentity(
            id: $record->id,
            name: $record->name,
            scopes: $record->scopes,
        );
    }
}
