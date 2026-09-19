<?php

declare(strict_types=1);

namespace Naf\MCP\Support;

use DirectoryIterator;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class FilesystemStore
{
    public function __construct(
        private readonly string $root,
        private readonly int $maxBytes = 5_000_000, // 5 MB default safety cap
    ) {
        if (!is_dir($this->root)) {
            mkdir($this->root, 0770, true);
        }
    }

    /**
     * Resolve a relative path safely inside the root.
     */
    public function resolve(string $relPath): string
    {
        $relPath = ltrim($relPath, "/ \t\n\r\0\x0B");

        if ($relPath === '' || str_contains($relPath, "\0")) {
            throw new RuntimeException('Invalid path');
        }
        // block traversal
        if (preg_match('#(^|/)\.\.(?:/|$)#', $relPath)) {
            throw new RuntimeException('Path traversal not allowed');
        }
        // allow a conservative charset
        if (!preg_match('#^[a-zA-Z0-9/_\-.]+$#', $relPath)) {
            throw new RuntimeException('Invalid characters in path');
        }

        $full = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relPath;

        // Ensure containment based on parent realpath (best effort)
        $rootReal = realpath($this->root);
        $parent   = dirname($full);
        if (!is_dir($parent)) {
            mkdir($parent, 0770, true);
        }
        $parentReal = realpath($parent);

        if ($rootReal === false || $parentReal === false || !str_starts_with($parentReal, $rootReal)) {
            throw new RuntimeException('Path escapes storage root');
        }

        return $full;
    }

    public function write(
        string $relPath,
        string $content,
        string $encoding = 'utf8',
        string $mode = 'overwrite',
    ): array {
        $path = $this->resolve($relPath);

        $bytes = $this->decode($content, $encoding);
        if (strlen($bytes) > $this->maxBytes) {
            throw new RuntimeException('Content too large');
        }

        if ($mode === 'create_only' && file_exists($path)) {
            throw new RuntimeException('File exists');
        }

        $flags = ($mode === 'append') ? FILE_APPEND : 0;

        if (file_put_contents($path, $bytes, $flags) === false) {
            throw new RuntimeException('Write failed');
        }

        return [
            'ok'    => true,
            'path'  => $relPath,
            'bytes' => strlen($bytes),
        ];
    }

    public function read(string $relPath, string $encoding = 'utf8'): array
    {
        $path = $this->resolve($relPath);

        if (!is_file($path)) {
            throw new RuntimeException('Not found');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Read failed');
        }

        return [
            'path'     => $relPath,
            'encoding' => $encoding,
            'content'  => $this->encode($raw, $encoding),
            'bytes'    => strlen($raw),
        ];
    }

    /**
     * @return array<array{path:string,type:string,bytes?:int}>
     */
    public function list(string $relDir = '', bool $recursive = false): array
    {
        $relDir = trim($relDir);
        if ($relDir === '' || $relDir === '.') {
            $base   = rtrim($this->root, DIRECTORY_SEPARATOR);
            $prefix = '';
        } else {
            $base   = $this->resolve(rtrim($relDir, '/') . '/.');
            $prefix = rtrim($relDir, '/') . '/';
        }

        if (!is_dir($base)) {
            throw new RuntimeException('Not a directory');
        }

        $items = [];

        if ($recursive) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($it as $f) {
                /** @var SplFileInfo $f */
                $rel     = $prefix . ltrim(str_replace($base, '', $f->getPathname()), DIRECTORY_SEPARATOR);
                $items[] = $f->isDir()
                    ? ['path' => $rel, 'type' => 'dir']
                    : ['path' => $rel, 'type' => 'file', 'bytes' => $f->getSize()];
            }
        } else {
            $it = new DirectoryIterator($base);
            foreach ($it as $f) {
                if ($f->isDot()) {
                    continue;
                }
                $rel     = $prefix . $f->getFilename();
                $items[] = $f->isDir()
                    ? ['path' => $rel, 'type' => 'dir']
                    : ['path' => $rel, 'type' => 'file', 'bytes' => $f->getSize()];
            }
        }

        return $items;
    }

    public function delete(string $relPath): array
    {
        $path = $this->resolve($relPath);

        if (!file_exists($path)) {
            return ['ok' => true, 'path' => $relPath, 'deleted' => false];
        }

        if (is_dir($path)) {
            throw new RuntimeException('Refusing to delete directories');
        }

        if (!unlink($path)) {
            throw new RuntimeException('Delete failed');
        }

        return ['ok' => true, 'path' => $relPath, 'deleted' => true];
    }

    private function decode(string $content, string $encoding): string
    {
        return match ($encoding) {
            'utf8'   => $content,
            'base64' => base64_decode($content, true) ?: throw new RuntimeException('Invalid base64'),
            default  => throw new RuntimeException('Unsupported encoding'),
        };
    }

    private function encode(string $raw, string $encoding): string
    {
        return match ($encoding) {
            'utf8'   => $raw,
            'base64' => base64_encode($raw),
            default  => throw new RuntimeException('Unsupported encoding'),
        };
    }
}
