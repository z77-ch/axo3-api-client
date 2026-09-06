<?php

namespace Axo3\ApiClient\Store;

/**
 * Persistence port of the versioned snapshot. The port knows TREES (plain
 * arrays), never DTOs: what the tree contains is the contract's business
 * (docs/units-v1.md). Implement it to keep the snapshot somewhere other
 * than the file store this package ships.
 */
interface SnapshotStoreInterface
{
    /**
     * Current built tree, or null if none exists. The tree carries
     * `meta.hash` and `meta.builtAt`, plus whatever the writer added.
     */
    public function currentTree(): ?array;

    /**
     * Index entry of the current version — `hash`, `createdAt`,
     * `lastCheckedAt`, and every extra the writer stored (e.g.
     * `revalidateAfter`) — or null when there is no current version.
     */
    public function currentMeta(): ?array;

    /** Built tree of the version right before the current one, or null. */
    public function previousTree(): ?array;

    /**
     * Persist a new version (built, optionally raw), swap current atomically,
     * prune to retention.
     *
     * @param array      $tree      the built read model; `meta.hash`/`meta.builtAt` are set when missing
     * @param string     $hash      content hash of the version (the ETag on the wire)
     * @param array|null $raw       what the version was built from, for audit — null writes no raw file
     * @param array      $extraMeta further index fields of this version (e.g. revalidateAfter)
     */
    public function save(array $tree, string $hash, ?array $raw = null, array $extraMeta = []): void;

    /**
     * Bump lastCheckedAt of the current version without creating a new one.
     *
     * @param array $extraMeta further index fields to update on the current version
     */
    public function touchChecked(array $extraMeta = []): void;
}
