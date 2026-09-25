<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Domain layer (ports, contracts, value objects)
 * Ecosystem Ports: storage exception hierarchy (map all backend errors here).
 */

namespace Zef\Framework\Storage;

/**
 * Base exception for every object-storage adapter failure.
 *
 * Adapters MUST translate backend-specific errors (filesystem I/O, HTTP
 * status codes, SDK errors) onto this hierarchy — the port never leaks
 * driver exception types, mirroring the DatabaseException contract.
 */
class StorageException extends \RuntimeException {}
