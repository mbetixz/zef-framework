<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

final class UploadedFile implements UploadedFileInterface
{
    private bool $moved = false;

    public function __construct(
        private readonly StreamInterface $stream,
        private readonly ?int $size = null,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {
        // Mirror Psr17Factory::createUploadedFile(): a negative size is
        // meaningless (PSR-7 §6.5) and must not be stored verbatim.
        if ($size !== null && $size < 0) {
            throw new \InvalidArgumentException('Uploaded file size must not be negative.');
        }
    }

    #[\Override]
    public function getStream(): StreamInterface
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException("Uploaded file is not available (error {$this->error}).");
        }
        if ($this->moved) {
            throw new \RuntimeException('Uploaded file has already been moved.');
        }

        return $this->stream;
    }

    /**
     * Bug fix #11: replaced @fopen/@rename with scoped error handler;
     * removed pointless function_exists('fflush').
     */
    #[\Override]
    public function moveTo(string $targetPath): void
    {
        if ($targetPath === '') {
            throw new \InvalidArgumentException('Target path must not be empty.');
        }
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException("Cannot move uploaded file with error code {$this->error}.");
        }
        if ($this->moved) {
            throw new \RuntimeException('Uploaded file has already been moved.');
        }
        $directory = dirname($targetPath);
        if (!is_dir($directory)) {
            throw new \RuntimeException("Destination directory does not exist: '{$directory}'.");
        }
        $tmpTarget = $targetPath . '.zef-tmp-' . bin2hex(random_bytes(8));
        $warning = null;
        set_error_handler(static function (int $s, string $m) use (&$warning): bool {
            $warning = $m;

            return true;
        });

        try {
            $dest = fopen($tmpTarget, 'x+b');
        } finally {
            restore_error_handler();
        }
        if ($dest === false) {
            throw new \RuntimeException('Unable to create temporary upload target' . ($warning !== null ? ": {$warning}" : '.'));
        }
        $success = false;
        $originalPosition = null;

        try {
            if ($this->stream->isSeekable()) {
                $originalPosition = $this->stream->tell();
                $this->stream->rewind();
            }
            while (!$this->stream->eof()) {
                $chunk = $this->stream->read(8192);
                if ($chunk === '') {
                    break;
                }
                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $written = fwrite($dest, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Unable to write uploaded file.');
                    }
                    $offset += $written;
                }
            }
            @fflush($dest);
            @fclose($dest);
            $dest = null;
            $renameWarning = null;
            set_error_handler(static function (int $s, string $m) use (&$renameWarning): bool {
                $renameWarning = $m;

                return true;
            });

            try {
                $renamed = rename($tmpTarget, $targetPath);
            } finally {
                restore_error_handler();
            }
            if (!$renamed) {
                throw new \RuntimeException("Unable to finalize uploaded file to '{$targetPath}'" . ($renameWarning !== null ? ": {$renameWarning}" : '.'));
            }
            $success = true;
        } finally {
            if (is_resource($dest)) {
                @fclose($dest);
            }
            if (!$success) {
                @unlink($tmpTarget);
            }
            if (!$success && $originalPosition !== null) {
                try {
                    $this->stream->seek($originalPosition);
                } catch (\Throwable) {
                }
            }
        }
        $this->moved = true;
        $this->stream->close();
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->size ?? $this->stream->getSize();
    }

    #[\Override]
    public function getError(): int
    {
        return $this->error;
    }

    #[\Override]
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    #[\Override]
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}
