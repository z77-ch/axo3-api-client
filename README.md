# axo3 API client

PHP client for the [axo3](https://axo3.ch) real-estate data API: fetch your
stock as JSON, keep it as a local file snapshot, revalidate at the interval
the API dictates. Framework-free — works in WordPress, Joomla, any CMS, or
plain PHP.

**You need an API key.** Keys are issued per connection in the axo3 backend;
the code here is free (MIT), the API access is part of your axo3 plan.

## Install

**With Composer:**

```
composer require axo3/api-client
```

**Without Composer** (WordPress, Joomla, plain PHP): download the release
ZIP, unpack it anywhere in your project and load the bundled autoloader:

```php
require __DIR__ . '/axo3-api-client/autoload.php';
```

Requirements: PHP >= 8.2 with `curl` and `json`.

## Use

```php
use Axo3\ApiClient\Client\ApiClient;
use Axo3\ApiClient\Service\UnitsSnapshotService;
use Axo3\ApiClient\SnapshotConfig;
use Axo3\ApiClient\Store\SnapshotStore;

$config = new SnapshotConfig(
    dataDir: '/path/to/storage/axo3-units',   // outside the web root
    logFile: '/path/to/storage/axo3-units/sync.log',
);

$service = new UnitsSnapshotService(
    new SnapshotStore($config),
    $config,
    new ApiClient('https://api.axo3.ch', 'YOUR-API-KEY'),
);

// Render path: reads the local snapshot, NEVER calls the API.
foreach ($service->units() as $unit) {
    echo $unit['title'], ' — ', $unit['status'], "\n";
}

// Background path (after rendering, or from a cron/scheduled job):
// asks the API only when the interval it dictated has elapsed.
$service->revalidateIfDue();
```

Two rules the service enforces for you, and that you should not work around:

1. **Render first, ask afterwards.** `units()`, `findUnit()` and `tree()`
   read the local snapshot only. `revalidateIfDue()` belongs after the page
   is built or in a background job — never inside the render path.
2. **The API dictates the pace.** Every answer says when to ask again;
   the service stores it and `revalidateIfDue()` obeys it. No polling loops.

What the data looks like — fields, vocabulary, media links, ETag rules —
is specified in [docs/units-v1.md](docs/units-v1.md).

## Failure behaviour

A failed fetch (network down, key revoked, API rebuilding) never breaks your
page: the current snapshot stays in place and keeps being served, the attempt
is logged to `logFile`, and the next attempt waits for the normal interval.
To raise your own alert, pass a callback:

```php
$service = new UnitsSnapshotService($store, $config, $client,
    function ($result) {
        if (!$result->isSuccess()) {
            // notify yourself; $result->code and $result->message say why
        }
    }
);
```

`$service->health()` is a keyed liveness probe for a "check connection"
button in your own admin UI.

## License

MIT — see [LICENSE](LICENSE).
