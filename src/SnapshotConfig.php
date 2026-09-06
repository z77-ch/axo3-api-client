<?php

namespace Axo3\ApiClient;

/**
 * Where the snapshot lives and how it ages — the WHOLE configuration a
 * consumer of the axo3 data API needs besides its access data.
 *
 * Deliberately no credential, token or provider field: a site that consumes
 * axo3 has no provider. The API key belongs to {@see Client\ApiClient}.
 *
 * `ttlSeconds` is the FALLBACK interval only: the API tells the client when
 * to ask again (`X-Axo3-Revalidate-After`, or `meta.revalidateAfter` in the
 * bundle), and the store remembers that value. This one applies until the
 * first successful answer.
 */
final class SnapshotConfig
{
    public function __construct(
        public readonly string $dataDir,                 // abs path of the snapshot directory
        public readonly string $logFile,                 // sync log (one line per revalidation)
        public readonly int    $ttlSeconds    = 15 * 60, // fallback revalidation gate
        public readonly int    $retentionDays = 3,       // keep versions of the last N days (+ current + previous)
    ) {
    }
}
