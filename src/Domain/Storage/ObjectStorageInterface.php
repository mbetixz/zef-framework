<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Domain layer (ports, contracts, value objects)
 * Ecosystem Ports: the object-storage port (ROADMAP "CDN/storage adapters").
 *
 * The port is deliberately transport-neutral and payload-string based:
 * binary-safe contents flow through as opaque strings, and every adapter
 * (Local filesystem, S3-compatible HTTP, future GCS/Azure) maps the six
 * primitive operations onto its backend without leaking driver semantics.
 */

namespace Zef\Framework\Storage;

/**
 * Outbound port for object/blob storage backends.
 *
 * Contract notes (pinned by EcosystemPortsV30Test):
 *
 * - Keys are flat, slash-separated identifiers WITHOUT a leading slash;
 *   `StorageKeys::assertValidKey()` is the single validation authority and
 *   every implementation MUST route its key parameters through it, so
 *   traversal segments (`.` / `..`) can never reach a backend.
 * - `get()` and `stat()` throw {@see ObjectNotFoundException} when the key
 *   does not exist — callers that tolerate absence use `exists()` first.
 * - `delete()` is idempotent: deleting an absent key is NOT an error.
 * - `put()` overwrites atomically from the point of view of the backend
 *   (readers observe either the old or the new contents, never a torn mix).
 * - `list()` returns keys lexicographically sorted, files only, bounded by
 *   the limit argument; a `$prefix` of `''` lists the whole bucket.
 */
interface ObjectStorageInterface
{
    /**
     * Store $contents under $key (create or overwrite).
     *
     * @param string $key      validated storage key (StorageKeys contract)
     * @param string $contents binary-safe payload (0..maxObjectBytes)
     *
     * @throws StorageException when the backend rejects the write
     */
    public function put(string $key, string $contents): void;

    /**
     * Read the full contents stored under $key.
     *
     * @throws ObjectNotFoundException when the key does not exist
     * @throws StorageException        when the backend read fails
     */
    public function get(string $key): string;

    /**
     * Remove $key if present; absent keys are silently accepted.
     *
     * @throws StorageException when the backend delete fails
     */
    public function delete(string $key): void;

    /**
     * Whether $key currently resolves to an object.
     *
     * @throws StorageException when the backend probe fails
     */
    public function exists(string $key): bool;

    /**
     * Size + last-modified metadata for $key, or null when absent.
     *
     * @throws StorageException when the backend stat fails
     */
    public function stat(string $key): ?ObjectStat;

    /**
     * Keys under $prefix, lexicographically sorted, at most $limit entries.
     *
     * @param string $prefix '' lists everything; validated like a key but
     *                       empty is allowed (root listing)
     * @param int    $limit   1..1000 — backends that page server-side cap a
     *                       single request at 1000 keys (S3 ListObjectsV2)
     *
     * @return list<string>
     *
     * @throws StorageException when the backend listing fails
     */
    public function list(string $prefix = '', int $limit = 1000): array;
}
