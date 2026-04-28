<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use NixPHP\MCP\Store\FileTokenStore;
use Tests\NixPHPTestCase;

final class TokenStoreTest extends NixPHPTestCase
{
    public function testCreatesFindsTouchesAndRevokesToken(): void
    {
        $path = sys_get_temp_dir() . '/nixphp-mcp-test-' . bin2hex(random_bytes(8)) . '/tokens.json';
        $store = new FileTokenStore($path);

        $created = $store->create('Test client', ['articles:read']);
        $this->assertStringStartsWith('mcp_', $created->plainToken);
        $this->assertFileExists($path);
        $this->assertStringNotContainsString($created->plainToken, (string)file_get_contents($path));

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
