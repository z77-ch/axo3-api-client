<?php

namespace Axo3\ApiClient\Client;

/**
 * Outcome of one {@see ApiClient} call — a value, not an exception, because
 * every outcome except "new bundle" is a normal operating state the caller
 * has to act on: 304 is success, and a failure means "keep the snapshot and
 * report it", never "break the page".
 *
 * `code` carries the API's machine-readable error code (`unauthorized`,
 * `throttled`, `snapshot_pending`, `internal`, …) or the synthetic `network`
 * when the request never reached the server. It is what goes into the log
 * line and into the alert.
 *
 * `revalidateAfter` is the interval the API DICTATES (seconds) — present on
 * 200 and on 304, null when the server did not say. The consumer stores it
 * and asks again no sooner.
 *
 * `retryAfter` is a SHORT-TERM hint: `X-Axo3-Retry-After` on 200/304 while
 * the stock behind the answer is being rebuilt («ask again in 30 s»), or the
 * standard `Retry-After` on a 503/429. The consumer honours it at most five
 * times in a row, then falls back to `revalidateAfter` (docs/units-v1.md,
 * «ETag and revalidation»). Null = no hint, the normal interval.
 */
final class ApiResult
{
    private function __construct(
        public readonly int $status,
        public readonly ?array $bundle = null,
        public readonly string $hash = '',
        public readonly ?int $revalidateAfter = null,
        public readonly string $code = '',
        public readonly string $message = '',
        public readonly ?int $retryAfter = null,
    ) {
    }

    /** 200 — a new bundle; the hash comes from the ETag header, never from the body. */
    public static function bundle(array $bundle, string $hash, ?int $revalidateAfter = null, ?int $retryAfter = null): self
    {
        return new self(200, $bundle, $hash, $revalidateAfter, retryAfter: $retryAfter);
    }

    /** 304 — our copy is current. Success without content. */
    public static function unchanged(?int $revalidateAfter = null, ?int $retryAfter = null): self
    {
        return new self(304, null, '', $revalidateAfter, retryAfter: $retryAfter);
    }

    /** 200 on an endpoint that carries no snapshot (health probe). */
    public static function ok(): self
    {
        return new self(200);
    }

    /** Any other outcome: HTTP status (0 = request never completed) + code. */
    public static function failure(int $status, string $code, string $message = '', ?int $retryAfter = null): self
    {
        return new self($status, null, '', null, $code, $message, $retryAfter);
    }

    public function isSuccess(): bool
    {
        return $this->status === 200 || $this->status === 304;
    }

    /** Short line for the sync log. */
    public function describe(): string
    {
        return match (true) {
            $this->status === 200 => 'changed (200)',
            $this->status === 304 => 'unchanged (304)',
            default => 'failed (' . ($this->status ?: 'no response') . ' ' . $this->code . ')'
                . ($this->message !== '' ? ': ' . $this->message : ''),
        };
    }
}
