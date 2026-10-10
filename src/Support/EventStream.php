<?php

declare(strict_types=1);

namespace Naf\MCP\Support;

use Generator;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/** A lazy, read-only PSR-7 stream. Each read advances at most one MCP message. */
final class EventStream implements StreamInterface
{
    private string $buffer = '';
    private int $position  = 0;
    private bool $started  = false;
    private bool $advance  = false;
    private bool $closed   = false;

    public function __construct(private ?Generator $messages)
    {
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->messages = null;
        $this->buffer   = '';
        $this->closed   = true;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->closed;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Event streams cannot seek.');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Event streams are read-only.');
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Read length cannot be negative.');
        }
        if ($length === 0 || $this->closed) {
            return '';
        }
        if ($this->buffer === '') {
            if (!$this->started) {
                $this->messages->rewind();
                $this->started = true;
            } elseif ($this->advance) {
                $this->messages->next();
            }
            if (!$this->messages->valid()) {
                $this->close();

                return '';
            }
            $this->buffer = 'event: message' . "\n" . 'data: '
                . json_encode($this->messages->current(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            $this->advance = true;
        }
        $chunk        = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($chunk));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        $metadata = ['seekable' => false, 'stream_type' => 'mcp-event-stream'];

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }
}
