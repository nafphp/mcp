<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use Naf\Auth\Identity\Identity;
use Naf\Auth\Identity\IdentityInterface;
use Naf\Auth\Support\PasswordHasher;
use Naf\MCP\Auth\AuthenticatorInterface;
use Naf\MCP\Auth\AuthorizationException;
use Naf\MCP\Auth\OAuthAuthenticator;
use Naf\MCP\Support\ToolRegistry;
use Naf\MCP\Tools\ScopedToolInterface;
use Naf\MCP\Tools\UserToolInterface;
use Naf\OAuth\Server\Core\ResourceServer;
use Naf\OAuth\Server\Core\ScopePolicy;
use Naf\OAuth\Server\Migrations\OAuthServerMigration;
use Naf\OAuth\Server\Model\AuthorizationRequest;
use Naf\OAuth\Server\Store\PdoClients;
use Naf\OAuth\Server\Store\PdoTokens;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function Naf\app;

final class OAuthAuthenticatorTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSharedAuthenticatorUsesTheSuppliedRequestForEveryMcpCall(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        $connection = new PDO('sqlite::memory:');
        (new OAuthServerMigration())->up($connection);
        $clients  = new PdoClients($connection, new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]));
        [$client] = $clients->register('Service', [], ['client_credentials'], ['mcp:service'], true);
        $tokens   = new PdoTokens($connection);
        $issued   = $tokens->issueForClient($client, ['mcp:service'], '', 60);
        $first    = new ServerRequest('POST', 'https://tools.example.test/mcp', [
            'Authorization' => 'Bearer ' . $issued->accessToken,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json, text/event-stream',
        ], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        $authenticator = new OAuthAuthenticator(new ResourceServer($tokens, new ScopePolicy([]), static fn(): null => null, $first));
        self::assertNotNull($authenticator->authenticate($first));
        self::assertNull($authenticator->authenticate(new Request('POST', '/mcp')));
        self::assertNotNull($authenticator->authenticate($first));
        self::assertNull($authenticator->authenticate($first->withHeader('Authorization', 'Bearer invalid')));

        if (!method_exists(app(), 'handle')) {
            self::markTestSkipped('Supplied-request integration requires naf/framework >= 0.2.12.');
        }
        require dirname(__DIR__, 2) . '/bootstrap.php';
        require dirname(__DIR__, 2) . '/src/routes.php';
        app()->container()->set(AuthenticatorInterface::class, $authenticator);
        self::assertSame(200, app()->handle($first)->getStatusCode());
        self::assertSame(401, app()->handle($first->withoutHeader('Authorization'))->getStatusCode());
        self::assertSame(401, app()->handle($first->withHeader('Authorization', 'Bearer invalid'))->getStatusCode());
        self::assertSame(200, app()->handle($first)->getStatusCode());
    }

    public static function literalScopes(): iterable
    {
        yield ['notes:*'];
        yield ['*'];
    }

    #[DataProvider('literalScopes')]
    public function testOAuthScopesNeverAcquireFileTokenWildcardRights(string $scope): void
    {
        $connection = new PDO('sqlite::memory:');
        (new OAuthServerMigration())->up($connection);
        [$client] = (new PdoClients($connection, new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4])))
            ->register('App', ['https://app.example.test/callback'], ['authorization_code'], [$scope], false);
        $tokens   = new PdoTokens($connection);
        $verifier = str_repeat('v', 43);
        $code     = $tokens->issueCode(new AuthorizationRequest(
            $client->id,
            'https://app.example.test/callback',
            [$scope],
            '',
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            null,
            '',
            'session',
            'test',
            '42',
        ), 60);
        $issued   = $tokens->redeem($code, $client, 'https://app.example.test/callback', $verifier, 60, 600);
        $account  = new Identity('42', permissions: ['notes.read']);
        $request  = new ServerRequest('POST', '/mcp', ['Authorization' => 'Bearer ' . $issued->accessToken]);
        $identity = (new OAuthAuthenticator(new ResourceServer(
            $tokens,
            new ScopePolicy([$scope => ['permission' => 'notes.read']]),
            static fn() => $account,
            $request,
        )))->authenticate($request);
        $tool = new class implements UserToolInterface, ScopedToolInterface {
            public function name(): string
            {
                return 'delete_note';
            }

            public function description(): string
            {
                return 'Delete a note.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function requiredScopes(): array
            {
                return ['notes:delete'];
            }

            public function handle(array $args): mixed
            {
                Assert::fail('Unauthorized handler ran.');
            }
        };
        $registry = new ToolRegistry('mcp:service');
        $registry->register($tool);
        self::assertSame([], $registry->definitions($identity));
        $this->expectException(AuthorizationException::class);
        $registry->call('delete_note', [], $identity);
    }

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
