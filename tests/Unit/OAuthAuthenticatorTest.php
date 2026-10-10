<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use Naf\Auth\Identity\IdentityInterface;
use Naf\Auth\Support\PasswordHasher;
use Naf\MCP\Auth\OAuthAuthenticator;
use Naf\OAuth\Server\Core\ResourceServer;
use Naf\OAuth\Server\Core\ScopePolicy;
use Naf\OAuth\Server\Migrations\OAuthServerMigration;
use Naf\OAuth\Server\Model\AuthorizationRequest;
use Naf\OAuth\Server\Store\PdoClients;
use Naf\OAuth\Server\Store\PdoTokens;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;

final class OAuthAuthenticatorTest extends TestCase
{
    public function testServiceAndUserTokensKeepAudienceLifecycleAndCurrentPermissions(): void
    {
        $connection = new PDO('sqlite::memory:');
        (new OAuthServerMigration())->up($connection);
        $clients  = new PdoClients($connection, new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]));
        [$client] = $clients->register('Alexa', ['https://alexa.example.test/callback'], ['authorization_code', 'refresh_token', 'client_credentials'], ['mcp:service', 'account:read'], true, ['https://tools.example.test/mcp']);
        $tokens   = new PdoTokens($connection);
        $policy   = new ScopePolicy(['account:read' => ['permission' => 'account.read']]);
        $account  = new class implements IdentityInterface {
            public array $permissions = ['account.read'];

            public function getIdentifier(): string
            {
                return '42';
            }

            public function getRoles(): iterable
            {
                return [];
            }

            public function getPermissions(): iterable
            {
                return $this->permissions;
            }
        };
        $load         = static fn() => $account;
        $service      = $tokens->issueForClient($client, ['mcp:service'], 'https://tools.example.test/mcp', 60);
        $authenticate = static function (string $token, string $audience, Closure $loader) use ($tokens, $policy) {
            $request = new ServerRequest('POST', 'https://tools.example.test/mcp', ['Authorization' => 'Bearer ' . $token, 'Cookie' => 'PHPSESSID=irrelevant']);

            return (new OAuthAuthenticator(new ResourceServer($tokens, $policy, $loader, $request, $audience)))->authenticate($request);
        };
        self::assertFalse($authenticate($service->accessToken, 'https://tools.example.test/mcp', $load)->userLinked);
        self::assertNull($authenticate($service->accessToken, 'https://other.example.test/mcp', $load));

        $verifier = str_repeat('v', 43);
        $code     = $tokens->issueCode(new AuthorizationRequest(
            $client->id,
            'https://alexa.example.test/callback',
            ['account:read'],
            'state',
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            null,
            'https://tools.example.test/mcp',
            'session',
            'database',
            '42',
        ), 60);
        $user     = $tokens->redeem($code, $client, 'https://alexa.example.test/callback', $verifier, 60, 600);
        $identity = $authenticate($user->accessToken, 'https://tools.example.test/mcp', $load);
        self::assertTrue($identity->userLinked);
        self::assertSame(['account:read'], $identity->scopes);
        $account->permissions = [];
        self::assertSame([], $authenticate($user->accessToken, 'https://tools.example.test/mcp', $load)->scopes);
        self::assertNull($authenticate($user->accessToken, 'https://tools.example.test/mcp', static fn() => null));
        $tokens->revoke($user->accessToken, $client);
        self::assertNull($authenticate($user->accessToken, 'https://tools.example.test/mcp', $load));
    }
}
