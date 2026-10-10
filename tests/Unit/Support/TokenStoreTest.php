<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Naf\MCP\Auth\BearerTokenAuthenticator;
use Naf\MCP\Store\FileTokenStore;
use Nyholm\Psr7\ServerRequest;
use Tests\NafTestCase;

final class TokenStoreTest extends NafTestCase
{
    public function testFileTokensKeepTheirWildcardMatchingContract(): void
    {
        $directory = sys_get_temp_dir() . '/naf-file-scope-' . bin2hex(random_bytes(6));
        $path      = $directory . '/tokens.json';
        $store     = new FileTokenStore($path);

        try {
            foreach (['*', 'notes:*'] as $scope) {
                $created  = $store->create('Wildcard', [$scope]);
                $identity = (new BearerTokenAuthenticator($store))->authenticate(new ServerRequest('POST', '/mcp', ['Authorization' => 'Bearer ' . $created->plainToken]));
                self::assertTrue($identity->can('notes:delete'));
                self::assertSame($scope === '*', $identity->can('other:delete'));
                self::assertFalse($identity->userLinked);
            }
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testCreatesFindsTouchesAndRevokesToken(): void
    {
        $path  = sys_get_temp_dir() . '/naf-mcp-test-' . bin2hex(random_bytes(8)) . '/tokens.json';
        $store = new FileTokenStore($path);

        $created = $store->create('Test client', ['articles:read']);
        $this->assertStringStartsWith('mcp_', $created->plainToken);
        $this->assertFileExists($path);
        $this->assertStringNotContainsString($created->plainToken, (string) file_get_contents($path));

        $record = $store->findByToken($created->plainToken);
        $this->assertNotNull($record);
        $this->assertSame($created->record->id, $record->id);
        $this->assertSame(['articles:read'], $record->scopes);

        $store->touch($record->id);
        $this->assertNotNull($store->find($record->id)?->lastUsedAt);

        $this->assertTrue($store->revoke($record->id));
        $this->assertNull($store->findByToken($created->plainToken));
    }
}
