<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Message;

if (!interface_exists(UploadedFileFactoryInterface::class)) {
    interface UploadedFileFactoryInterface
    {
        public function createUploadedFile(
            StreamInterface $stream,
            ?int $size = null,
            int $error = UPLOAD_ERR_OK,
            ?string $clientFilename = null,
            ?string $clientMediaType = null
        ): UploadedFileInterface;
    }
}
