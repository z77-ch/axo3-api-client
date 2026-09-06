<?php

namespace Axo3\ApiClient\Client;

/**
 * Thin client for the axo3 data API (contract: docs/units-v1.md).
 *
 * One request per revalidation:
 *
 *   GET {apiUrl}/api/v1/units
 *   Authorization: Bearer {key}
 *   If-None-Match: "{hash we hold}"
 *
 *   304                        our copy is current — success without content
 *   200 + ETag + body          new bundle; the hash is the ETag HEADER, not the body
 *   anything else              {"error":{"code","message"}} — the code is what gets logged
 *
 * Both success answers may carry `X-Axo3-Revalidate-After: <seconds>` — the
 * interval the API dictates. A 200 carries the same value in the bundle
 * (`meta.revalidateAfter`) as well; the client reads the header first and
 * falls back to the body.
 *
 * Both may also carry `X-Axo3-Retry-After: <seconds>`: the stock behind the
 * answer is being rebuilt right now, the answer is the stand as it lies —
 * ask again shortly. A 503/429 says the same with the standard `Retry-After`.
 * Either lands in {@see ApiResult::$retryAfter}.
 *
 * ⚠️ 304 is checked BEFORE the >=400 branch. It is not an error, and treating
 * it as one would turn every quiet TTL tick into an alert.
 *
 * Nothing here throws for a failed request: an unreachable API is a normal
 * operating state for a snapshot client (the site keeps rendering the last
 * good stand), so every outcome comes back as {@see ApiResult}. What a
 * failure MEANS (log, alert) is the caller's decision.
 *
 * This class knows the transport and nothing else — not where the data came
 * from, not what a unit is. That is the point of the package.
 *
 * Not final on purpose: a test replaces {@see fetchUnits()} with hand-made
 * results instead of opening a socket.
 */
class ApiClient
{
    public const HEADER_REVALIDATE_AFTER = 'x-axo3-revalidate-after';
    public const HEADER_RETRY_AFTER      = 'x-axo3-retry-after';

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $userAgent = 'axo3-api-client/1.0',
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 15,
    ) {
    }

    /**
     * Fetch the units bundle.
     *
     * @param string|null $currentHash hash of the snapshot we hold; null on a
     *                                 cold start (no If-None-Match sent)
     */
    public function fetchUnits(?string $currentHash): ApiResult
    {
        return $this->request('/api/v1/units', $currentHash, true);
    }

    /**
     * Keyed liveness probe — the transport self-test behind a "check
     * connection" button. 200 proves route, header pass-through and key in
     * one call.
     */
    public function health(): ApiResult
    {
        return $this->request('/api/v1/health', null, false);
    }

    private function request(string $path, ?string $currentHash, bool $expectBundle): ApiResult
    {
        $url     = rtrim($this->apiUrl, '/') . $path;
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent,
        ];
        if ($currentHash !== null && $currentHash !== '') {
            $headers[] = 'If-None-Match: "' . $currentHash . '"';
        }

        $received = [];
        $ch       = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $received[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return self::interpret($http, $received, $raw, $err, $expectBundle);
    }

    /**
     * The whole answer logic, without the socket — so it can be tested with
     * hand-made responses.
     *
     * @param array<string,string> $headers lower-cased header name => value
     * @param string|false         $raw     response body, false when the request never completed
     */
    public static function interpret(int $http, array $headers, string|false $raw, string $curlError, bool $expectBundle): ApiResult
    {
        // The request never completed — DNS, TLS, timeout, no route. There is
        // no HTTP status to report, so the code is synthetic.
        if ($raw === false) {
            return ApiResult::failure(0, 'network', $curlError);
        }

        $revalidateAfter = self::seconds($headers[self::HEADER_REVALIDATE_AFTER] ?? null);
        $retryAfter      = self::seconds($headers[self::HEADER_RETRY_AFTER] ?? null);

        // BEFORE the error branch: 304 means "your copy is current".
        if ($http === 304) {
            return ApiResult::unchanged($revalidateAfter, $retryAfter);
        }

        $body = json_decode((string) $raw, true);

        if ($http !== 200) {
            return ApiResult::failure(
                $http,
                (string) ($body['error']['code'] ?? 'internal'),
                (string) ($body['error']['message'] ?? ''),
                self::seconds($headers['retry-after'] ?? null)   // 503 snapshot_pending, 429 throttled
            );
        }
        if (!is_array($body)) {
            return ApiResult::failure(200, 'internal', 'response body is not JSON');
        }
        if (!$expectBundle) {
            return ApiResult::ok();   // health: 200 is the whole answer
        }

        // A 200 without an ETag has no hash to store — the snapshot would then
        // never compare equal and every tick would rewrite it.
        $etag = trim((string) ($headers['etag'] ?? ''), " \t\"");
        if ($etag === '') {
            return ApiResult::failure(200, 'internal', 'missing ETag header on 200');
        }

        return ApiResult::bundle(
            $body,
            $etag,
            $revalidateAfter ?? self::seconds($body['meta']['revalidateAfter'] ?? null),
            $retryAfter
        );
    }

    private static function seconds(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $seconds = (int) $value;

        return $seconds > 0 ? $seconds : null;
    }
}
