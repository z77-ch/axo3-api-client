<?php

namespace Axo3\ApiClient\Store;

use Axo3\ApiClient\SnapshotConfig;

/**
 * File-backed versioned snapshot store.
 *
 *   raw/      <stamp>_<hash12>.json   what the version was built from (audit / fallback)
 *   built/    <stamp>_<hash12>.php    the tree as `return [...]` (OPcache-able)
 *   current.php                       copy of the active built file (hot path: include)
 *   index.json                        [{hash, createdAt, lastCheckedAt, isCurrent, rawFile, builtFile, …}]
 *
 * Writes go to a temp file and are then renamed (atomic on the same volume),
 * so concurrent readers never see a half-written current.php.
 */
final class SnapshotStore implements SnapshotStoreInterface
{
    public function __construct(private readonly SnapshotConfig $config)
    {
    }

    public function currentTree(): ?array
    {
        $f = $this->dir() . '/current.php';

        return is_file($f) ? (include $f) : null;
    }

    public function currentMeta(): ?array
    {
        foreach ($this->index() as $e) {
            if (!empty($e['isCurrent'])) {
                return $e;
            }
        }

        return null;
    }

    public function previousTree(): ?array
    {
        $prev = null;
        foreach ($this->index() as $e) {
            if (!empty($e['isCurrent'])) {
                continue;
            }
            if ($prev === null || (int) ($e['createdAt'] ?? 0) > (int) ($prev['createdAt'] ?? 0)) {
                $prev = $e;
            }
        }
        if ($prev === null || empty($prev['builtFile'])) {
            return null;
        }
        $f = $this->dir() . '/' . $prev['builtFile'];

        return is_file($f) ? (include $f) : null;
    }

    public function save(array $tree, string $hash, ?array $raw = null, array $extraMeta = []): void
    {
        $dir = $this->dir();
        @mkdir($dir . '/built', 0775, true);

        $now       = time();
        $stamp     = date('Ymd-His', $now);
        $h12       = substr($hash, 0, 12);
        $rawFile   = null;
        $builtFile = "built/{$stamp}_{$h12}.php";

        if ($raw !== null) {
            @mkdir($dir . '/raw', 0775, true);
            $rawFile = "raw/{$stamp}_{$h12}.json";
            file_put_contents(
                $dir . '/' . $rawFile,
                (string) json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $tree['meta'] = (array) ($tree['meta'] ?? []) + ['hash' => $hash, 'builtAt' => $now];

        $builtPhp = "<?php return " . var_export($tree, true) . ";\n";
        $this->atomicWrite($dir . '/' . $builtFile, $builtPhp);
        $this->atomicWrite($dir . '/current.php', $builtPhp); // atomic swap of the read model

        $index = $this->index();
        foreach ($index as &$e) {
            $e['isCurrent'] = false;
        }
        unset($e);
        $index[] = $extraMeta + [
            'hash'          => $hash,
            'createdAt'     => $now,
            'lastCheckedAt' => $now,
            'isCurrent'     => true,
            'rawFile'       => $rawFile,
            'builtFile'     => $builtFile,
        ];

        $this->writeIndex($this->prune($index));
    }

    public function touchChecked(array $extraMeta = []): void
    {
        $index = $this->index();
        foreach ($index as &$e) {
            if (!empty($e['isCurrent'])) {
                $e = array_merge($e, $extraMeta, ['lastCheckedAt' => time()]);
            }
        }
        unset($e);
        $this->writeIndex($index);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * Drop entries older than retentionDays — except the current one AND the
     * most-recent previous version. A before/after view diffs current against
     * previous, so the previous must survive retention even when the data was
     * stable for longer than retentionDays.
     */
    private function prune(array $index): array
    {
        $cutoff = time() - ($this->config->retentionDays * 86400);
        $dir    = $this->dir();

        $prevKey     = null;
        $prevCreated = -1;
        foreach ($index as $k => $e) {
            if (empty($e['isCurrent']) && (int) ($e['createdAt'] ?? 0) > $prevCreated) {
                $prevCreated = (int) ($e['createdAt'] ?? 0);
                $prevKey     = $k;
            }
        }

        $kept = [];
        foreach ($index as $k => $e) {
            $expired = (int) ($e['createdAt'] ?? 0) < $cutoff;
            if ($expired && empty($e['isCurrent']) && $k !== $prevKey) {
                if (!empty($e['rawFile'])) {
                    @unlink($dir . '/' . $e['rawFile']);
                }
                if (!empty($e['builtFile'])) {
                    @unlink($dir . '/' . $e['builtFile']);
                }
                continue;
            }
            $kept[] = $e;
        }

        return $kept;
    }

    /**
     * Write-then-rename, and evict the path from OPcache.
     *
     * The store INCLUDES the files it writes (current.php, built/*.php), and a
     * revalidation does both in the SAME request: read the tree, fetch, write
     * the new one, read it again to render. OPcache validates a path once per
     * request (and at most every `opcache.revalidate_freq` seconds across
     * requests), so without the eviction that second include still returns
     * the PREVIOUS compilation — the caller renders the old snapshot right
     * after writing the new one. Invisible under `php -S` and CLI, where
     * OPcache is off. One path only — never opcache_reset(): on shared
     * hosting one OPcache serves every site of the account.
     */
    private function atomicWrite(string $path, string $data): void
    {
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $data);
        rename($tmp, $path);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    private function index(): array
    {
        $f = $this->dir() . '/index.json';
        if (!is_file($f)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($f), true);

        return is_array($data) ? $data : [];
    }

    private function writeIndex(array $index): void
    {
        @mkdir($this->dir(), 0775, true);
        $this->atomicWrite(
            $this->dir() . '/index.json',
            (string) json_encode(array_values($index), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function dir(): string
    {
        return rtrim(str_replace('\\', '/', $this->config->dataDir), '/');
    }
}
