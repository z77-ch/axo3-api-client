<?php

/**
 * Standalone test of the package — no framework, no socket, a throwaway
 * directory under the system temp dir.
 *
 * Run: php tests/api-client-test.php
 */

require __DIR__ . '/../autoload.php';

use Axo3\ApiClient\Client\ApiClient;
use Axo3\ApiClient\Client\ApiResult;
use Axo3\ApiClient\RevalidateResult;
use Axo3\ApiClient\Service\UnitsSnapshotService;
use Axo3\ApiClient\SnapshotConfig;
use Axo3\ApiClient\Store\SnapshotStore;

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok  {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}\n";
    }
}

$tmp = sys_get_temp_dir() . '/axo3-api-client-test-' . getmypid();
@mkdir($tmp, 0755, true);

$bundle = [
    'meta' => ['version' => 1, 'revalidateAfter' => 120, 'generatedAt' => '2026-09-03T10:00:00+02:00'],
    'properties' => [[
        'id' => 'p-1', 'title' => 'Sonnengarten', 'address' => ['zip' => '1234', 'city' => 'Musterlingen', 'state' => 'ZH'],
        'buildings' => [[
            'id' => 'b-1', 'propertyId' => 'p-1', 'title' => 'Haus A', 'street' => 'Gartenweg', 'streetNumber' => '3',
            'zip' => '1234', 'city' => 'Musterlingen', 'yearBuilt' => 2024, 'imageUrl' => null,
            'units' => [
                ['id' => 'u-1', 'buildingId' => 'b-1', 'title' => '3.5 Zimmer', 'rooms' => 3.5, 'status' => 'free',
                 'priceNet' => 1850, 'imageUrls' => ['https://x/1'], 'documentUrls' => [['url' => 'https://x/d', 'label' => 'Grundriss', 'isFloorPlan' => true]],
                 'someFutureField' => 'ignored'],
                ['id' => 'u-2', 'buildingId' => 'b-1', 'title' => '2.5 Zimmer', 'rooms' => 2.5, 'status' => 'taken', 'statusText' => 'vermietet'],
            ],
        ]],
    ]],
];
$json = json_encode($bundle);

echo "— ApiClient::interpret —\n";

$r = ApiClient::interpret(0, [], false, 'could not resolve host', true);
ok($r->status === 0 && $r->code === 'network' && !$r->isSuccess(), 'no response → synthetic code `network`');

$r = ApiClient::interpret(304, ['x-axo3-revalidate-after' => '90'], '', '', true);
ok($r->status === 304 && $r->isSuccess() && $r->bundle === null, '304 is success without content');
ok($r->revalidateAfter === 90, 'and carries the interval the API dictates from the header');

$r = ApiClient::interpret(401, ['www-authenticate' => 'Bearer'], '{"error":{"code":"unauthorized","message":"no"}}', '', true);
ok($r->status === 401 && $r->code === 'unauthorized' && !$r->isSuccess(), '401 → the envelope error code');

$r = ApiClient::interpret(200, [], $json, '', true);
ok($r->status === 200 && $r->code === 'internal' && str_contains($r->message, 'ETag'), '200 without ETag is refused — nothing to compare against next time');

$r = ApiClient::interpret(200, ['etag' => '"abc123"'], $json, '', true);
ok($r->status === 200 && $r->hash === 'abc123' && is_array($r->bundle), '200 + ETag → bundle, hash taken from the header without quotes');
ok($r->revalidateAfter === 120, 'no header → the interval falls back to meta.revalidateAfter in the body');

$r = ApiClient::interpret(200, ['etag' => 'abc', 'x-axo3-revalidate-after' => '30'], $json, '', true);
ok($r->revalidateAfter === 30, 'the header wins over the body');

ok(ApiClient::interpret(200, ['etag' => '"abc123"'], $json, '', true)->retryAfter === null, 'no hint header → no retryAfter');

$r = ApiClient::interpret(304, ['x-axo3-retry-after' => '30'], '', '', true);
ok($r->status === 304 && $r->retryAfter === 30, 'X-Axo3-Retry-After on 304 → retryAfter 30 (a rebuild is running)');
$r = ApiClient::interpret(200, ['etag' => '"abc123"', 'x-axo3-retry-after' => '30'], $json, '', true);
ok($r->status === 200 && $r->retryAfter === 30 && $r->hash === 'abc123', 'and on 200 as well');
$r = ApiClient::interpret(503, ['retry-after' => '30'], '{"error":{"code":"snapshot_pending","message":"soon"}}', '', true);
ok($r->code === 'snapshot_pending' && $r->retryAfter === 30, 'the standard Retry-After on a 503 lands in the same field');

$r = ApiClient::interpret(200, [], '{"status":"ok"}', '', false);
ok($r->status === 200 && $r->bundle === null && $r->isSuccess(), 'health: 200 is the whole answer, no ETag needed');

$r = ApiClient::interpret(200, ['etag' => 'x'], 'not json', '', true);
ok($r->code === 'internal', 'a 200 that is not JSON is a failure, not a crash');

echo "\n— SnapshotStore —\n";

$config = new SnapshotConfig(dataDir: $tmp . '/units', logFile: $tmp . '/units/sync.log', ttlSeconds: 60, retentionDays: 1);
$store  = new SnapshotStore($config);
ok($store->currentTree() === null && $store->currentMeta() === null && $store->previousTree() === null, 'empty store: three nulls, no exception');

$store->save($bundle, 'abc123', null, ['revalidateAfter' => 120]);
$tree = $store->currentTree();
ok(is_array($tree) && $tree['meta']['hash'] === 'abc123' && isset($tree['meta']['builtAt']), 'save writes current.php with meta.hash and meta.builtAt');
ok($tree['meta']['version'] === 1, 'and keeps the meta the writer put there');
ok($store->currentMeta()['revalidateAfter'] === 120 && $store->currentMeta()['rawFile'] === null, 'index carries the extra field; no raw file when none was given');
ok(!is_dir($tmp . '/units/raw'), 'raw/ is not even created');

$store->touchChecked(['revalidateAfter' => 45]);
ok($store->currentMeta()['revalidateAfter'] === 45 && $store->currentMeta()['hash'] === 'abc123', 'touchChecked updates the extra field and keeps the version');

$bundle2 = $bundle;
$bundle2['properties'][0]['buildings'][0]['units'][0]['status'] = 'reserved';
$store->save($bundle2, 'def456', ['raw' => true]);
ok($store->currentTree()['properties'][0]['buildings'][0]['units'][0]['status'] === 'reserved', 'a second save swaps current');
ok($store->previousTree()['meta']['hash'] === 'abc123', 'and the previous version stays readable');
ok(is_file($tmp . '/units/' . $store->currentMeta()['rawFile']), 'a raw bundle is written when given');
ok(!file_exists($tmp . '/units/current.php.tmp'), 'no temp file left behind');

echo "\n— UnitsSnapshotService —\n";

final class FakeClient extends ApiClient
{
    public array $queue = [];
    public ?string $sentHash = null;

    public function __construct()
    {
        parent::__construct('https://api.example', 'k');
    }

    public function fetchUnits(?string $currentHash): ApiResult
    {
        $this->sentHash = $currentHash;

        return array_shift($this->queue) ?? ApiResult::failure(0, 'network', 'queue empty');
    }
}

$dir2   = $tmp . '/site';
$cfg2   = new SnapshotConfig(dataDir: $dir2, logFile: $dir2 . '/sync.log', ttlSeconds: 60);
$client = new FakeClient();
$seen   = [];
$svc    = new UnitsSnapshotService(new SnapshotStore($cfg2), $cfg2, $client, function (ApiResult $r) use (&$seen): void {
    $seen[] = $r->describe();
});

ok($svc->tree()['properties'] === [] && $svc->units() === [], 'no snapshot: empty tree, never a fetch in the read path');
ok($svc->needsUpdate() === true && $svc->revalidateAfter() === 60, 'cold start: due, fallback interval from config');

$client->queue[] = ApiResult::bundle($bundle, 'abc123', 120);
ok($svc->revalidateIfDue() === true, 'first revalidation writes the bundle');
ok($client->sentHash === null, 'and sent no If-None-Match on the cold start');
ok($svc->revalidateAfter() === 120 && $svc->needsUpdate() === false, 'the API\'s interval is now in force and the gate is closed');
ok(count($svc->units()) === 2 && $svc->units()[0]['street'] === 'Gartenweg' && $svc->units()[0]['propertyTitle'] === 'Sonnengarten',
    'units() merges the building and property context down');
ok($svc->findUnit('u-2')['statusText'] === 'vermietet' && $svc->findUnit('nope') === null, 'findUnit by axo3 id');

$client->queue[] = ApiResult::unchanged(30);
ok($svc->revalidate() === RevalidateResult::Unchanged, 'a 304 is Unchanged');
ok($client->sentHash === 'abc123', 'and the held hash travelled as If-None-Match');
ok($svc->revalidateAfter() === 30, 'a 304 may shorten the interval too');

$client->queue[] = ApiResult::failure(503, 'snapshot_pending', 'not yet');
$before = $svc->snapshotCreatedAt();
ok($svc->revalidate() === RevalidateResult::Failed, 'a failure is Failed');
ok($svc->snapshotCreatedAt() === $before && count($svc->units()) === 2, 'and the snapshot is kept — warm fallback');
ok($svc->needsUpdate() === false, 'and the gate closes on failure too — no retry storm');
ok($seen === ['changed (200)', 'unchanged (304)', 'failed (503 snapshot_pending): not yet'], 'the hook saw every outcome, in order');
ok(str_contains((string) file_get_contents($dir2 . '/sync.log'), 'units: failed (503 snapshot_pending)'), 'and the sync log has the line');

echo "\n— the short-term hint, bounded —\n";

$client->queue[] = ApiResult::unchanged(120, 30);
$svc->revalidate();
ok($svc->nextCheckIn() === 30 && $svc->revalidateAfter() === 120, 'a hint of 30 s shortens the NEXT wait; the interval itself stays 120');
foreach ([2, 3, 4, 5] as $n) {
    $client->queue[] = ApiResult::unchanged(120, 30);
    $svc->revalidate();
}
ok($svc->nextCheckIn() === 30, 'the fifth hinted answer in a row is still honoured');
$client->queue[] = ApiResult::unchanged(120, 30);
$svc->revalidate();
ok($svc->nextCheckIn() === 120, 'the sixth is not — back to the interval until the hint disappears');
$client->queue[] = ApiResult::unchanged(120);
$svc->revalidate();
$client->queue[] = ApiResult::unchanged(120, 30);
$svc->revalidate();
ok($svc->nextCheckIn() === 30, 'an answer without the hint resets the streak — the next hint counts as the first');
$client->queue[] = ApiResult::failure(503, 'snapshot_pending', 'building', 30);
$svc->revalidate();
ok($svc->nextCheckIn() === 30 && count($svc->units()) === 2, 'a 503 with Retry-After 30 is a hint too — and the snapshot stays');
$client->queue[] = ApiResult::failure(503, 'snapshot_pending', 'not yet');
$svc->revalidate();
ok($svc->nextCheckIn() === 120, 'a failure without Retry-After closes the gate for the whole interval');

$none = new UnitsSnapshotService(new SnapshotStore($cfg2), $cfg2, null);
ok($none->revalidate() === RevalidateResult::Failed && $none->health()->code === 'not_configured', 'no client: Failed without a fetch, health says not_configured');

// cleanup
$rm = function (string $d) use (&$rm): void {
    foreach (scandir($d) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $d . '/' . $f;
        is_dir($p) ? $rm($p) : @unlink($p);
    }
    @rmdir($d);
};
$rm($tmp);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
