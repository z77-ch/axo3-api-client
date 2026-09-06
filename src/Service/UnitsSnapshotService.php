<?php

namespace Axo3\ApiClient\Service;

use Axo3\ApiClient\Client\ApiClient;
use Axo3\ApiClient\Client\ApiResult;
use Axo3\ApiClient\RevalidateResult;
use Axo3\ApiClient\SnapshotConfig;
use Axo3\ApiClient\Store\SnapshotStoreInterface;

/**
 * The consumer's whole life with the units bundle: read the snapshot, know
 * when to ask again, ask, keep the answer.
 *
 * Read paths touch the store and nothing else. There is NO cold-start fetch
 * in the hot path: no snapshot means an empty tree, the background
 * revalidation fills it. An API call never happens while somebody waits for
 * a page.
 *
 * The interval is the API's. Every successful answer may carry
 * `revalidateAfter`; the store remembers it on the current version and
 * {@see needsUpdate()} obeys it — the configured `ttlSeconds` applies only
 * until the API has spoken. A failed fetch closes the gate too, or a broken
 * API turns into a retry storm (every visitor would trigger another fetch).
 *
 * A short-term hint overrides the interval, bounded: when the API says «ask
 * again in N seconds» (`X-Axo3-Retry-After` on 200/304 while the stock is
 * being rebuilt, or `Retry-After` on a 503/429), the next check is due after
 * N — at most {@see MAX_RETRIES} answers in a row, then the normal interval
 * applies until an answer without the hint resets the count. The store
 * remembers `retryAfter` and `retryCount` on the current version, like
 * `revalidateAfter`.
 *
 * What a failure MEANS is the site's: pass `$onResult` and raise your alert
 * there — this class only logs one line per attempt.
 *
 * @param null|callable(ApiResult):void $onResult called after every fetch, success or not
 */
final class UnitsSnapshotService
{
    /** How many hinted short waits in a row are honoured before the normal interval takes over. */
    public const MAX_RETRIES = 5;

    /** @var null|callable(ApiResult):void */
    private $onResult;

    public function __construct(
        private readonly SnapshotStoreInterface $store,
        private readonly SnapshotConfig $config,
        private readonly ?ApiClient $client,
        ?callable $onResult = null,
    ) {
        $this->onResult = $onResult;
    }

    // ── read paths: the store, and nothing else ────────────────────────────

    /** The bundle as stored: {meta, properties}. Empty properties when nothing was fetched yet. */
    public function tree(): array
    {
        return $this->store->currentTree() ?? ['meta' => [], 'properties' => []];
    }

    /**
     * Flat list of units, each with its building's context merged in — the
     * address physically lives on the building, and lists and filters expect
     * it on the unit. Keys added: `buildingTitle`, `street`, `streetNumber`,
     * `zip`, `city`, `propertyId`, `propertyTitle`.
     *
     * @param null|callable(array):bool $filter keep the unit when it returns true
     * @return array<int,array>
     */
    public function units(?callable $filter = null): array
    {
        $units = [];
        foreach ($this->tree()['properties'] ?? [] as $property) {
            foreach ($property['buildings'] ?? [] as $building) {
                $context = [
                    'buildingTitle' => $building['title'] ?? null,
                    'street'        => $building['street'] ?? null,
                    'streetNumber'  => $building['streetNumber'] ?? null,
                    'zip'           => $building['zip'] ?? null,
                    'city'          => $building['city'] ?? null,
                    'propertyId'    => $property['id'] ?? null,
                    'propertyTitle' => $property['title'] ?? null,
                ];
                foreach ($building['units'] ?? [] as $unit) {
                    $row = $unit + $context;
                    if ($filter === null || $filter($row)) {
                        $units[] = $row;
                    }
                }
            }
        }

        return $units;
    }

    /** One unit by its axo3 id (with building context), or null. */
    public function findUnit(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        foreach ($this->units() as $unit) {
            if ((string) ($unit['id'] ?? '') === $id) {
                return $unit;
            }
        }

        return null;
    }

    public function previousTree(): ?array
    {
        return $this->store->previousTree();
    }

    /** Unix timestamp of the current version, or null when none exists. */
    public function snapshotCreatedAt(): ?int
    {
        $createdAt = (int) ($this->store->currentMeta()['createdAt'] ?? 0);

        return $createdAt > 0 ? $createdAt : null;
    }

    /** The interval in force: what the API said last, else the configured fallback. */
    public function revalidateAfter(): int
    {
        $stored = (int) ($this->store->currentMeta()['revalidateAfter'] ?? 0);

        return $stored > 0 ? $stored : $this->config->ttlSeconds;
    }

    /**
     * Seconds until the next check is due, counted from the last one: the
     * API's short-term hint while it is in force, else the interval.
     */
    public function nextCheckIn(): int
    {
        $meta  = $this->store->currentMeta() ?? [];
        $hint  = (int) ($meta['retryAfter'] ?? 0);
        $count = (int) ($meta['retryCount'] ?? 0);

        return $hint > 0 && $count <= self::MAX_RETRIES ? $hint : $this->revalidateAfter();
    }

    /** True when there is no snapshot yet or the wait ({@see nextCheckIn()}) has elapsed. */
    public function needsUpdate(): bool
    {
        $meta = $this->store->currentMeta();
        if ($meta === null) {
            return true;
        }

        return (time() - (int) ($meta['lastCheckedAt'] ?? 0)) > $this->nextCheckIn();
    }

    // ── write path ─────────────────────────────────────────────────────────

    /**
     * Gated revalidation, for a background trigger. True only when a NEW
     * snapshot was written — then the caller re-renders.
     */
    public function revalidateIfDue(): bool
    {
        if (!$this->needsUpdate()) {
            return false;
        }

        return $this->revalidate() === RevalidateResult::Changed;
    }

    /**
     * Fetch now, ignoring the gate. Unlike revalidateIfDue() the result tells
     * a failed fetch apart from "fetched fine, nothing changed" — a backend
     * button must not report "up to date" when the API was unreachable.
     */
    public function revalidate(): RevalidateResult
    {
        if ($this->client === null) {
            // Not wired up yet: no fetch, no alert — an installation without
            // a key is being set up, not broken.
            $this->log('no api access data — skipping');

            return RevalidateResult::Failed;
        }

        $result = $this->client->fetchUnits($this->currentHash());
        $this->log('units: ' . $result->describe());

        if ($this->onResult !== null) {
            ($this->onResult)($result);
        }

        $hint = $this->hint($result);

        if (!$result->isSuccess()) {
            // A 503 with Retry-After (the stock is being built right now)
            // shortens the next wait like a hint on a success would; any
            // other failure closes the gate for the interval — no retry storm.
            $this->store->touchChecked($hint);

            return RevalidateResult::Failed;
        }

        $remember = $hint + ($result->revalidateAfter !== null ? ['revalidateAfter' => $result->revalidateAfter] : []);

        if ($result->bundle === null) {   // 304
            $this->store->touchChecked($remember);

            return RevalidateResult::Unchanged;
        }

        $this->store->save($result->bundle, $result->hash, null, $remember);

        return RevalidateResult::Changed;
    }

    /**
     * The hint's bookkeeping: with a hint, count it; without one, forget the
     * streak. Always written, so a stale hint never outlives the answer that
     * dropped it.
     *
     * @return array{retryAfter: ?int, retryCount: int}
     */
    private function hint(ApiResult $result): array
    {
        if ($result->retryAfter === null) {
            return ['retryAfter' => null, 'retryCount' => 0];
        }
        $count = (int) ($this->store->currentMeta()['retryCount'] ?? 0);

        return ['retryAfter' => $result->retryAfter, 'retryCount' => $count + 1];
    }

    /** Keyed liveness probe for a backend's "check connection" button. */
    public function health(): ApiResult
    {
        return $this->client?->health() ?? ApiResult::failure(0, 'not_configured', 'no api access data');
    }

    // ── plumbing ───────────────────────────────────────────────────────────

    /** Hash of the snapshot we hold — sent as If-None-Match; null on cold start. */
    private function currentHash(): ?string
    {
        $hash = (string) ($this->store->currentMeta()['hash'] ?? '');

        return $hash === '' ? null : $hash;
    }

    private function log(string $line): void
    {
        @mkdir(dirname($this->config->logFile), 0775, true);
        @file_put_contents($this->config->logFile, '[' . date('c') . "] $line\n", FILE_APPEND);
    }
}
