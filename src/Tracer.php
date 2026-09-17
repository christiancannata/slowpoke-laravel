<?php

namespace Slowpoke\Laravel;

/**
 * Collects one trace per HTTP request or queued job and turns it into OTLP/JSON for the agent:
 * a SERVER (or CONSUMER) span and one CLIENT span per query, with the SQL as the driver received
 * it (placeholders, never binding values) and the application line that ran it.
 */
class Tracer
{
    public const VERSION = '0.1.0';

    private const SERVER = 2;
    private const CLIENT = 3;
    private const CONSUMER = 5;

    /** @var OriginFinder */
    private $origin;
    /** @var callable(): Sender */
    private $sender;
    /** @var callable(): float */
    private $clock;
    /** @var callable(int): string */
    private $ids;
    /** @var string */
    private $service;
    /** @var string */
    private $version;
    /** @var int */
    private $maxQueries;
    /** @var int */
    private $maxSqlLength;
    /** @var array|null */
    private $trace;

    /**
     * @param callable(): Sender $sender resolved only when a trace is sent
     * @param array{service?: string, version?: string, max_queries?: int, max_sql_length?: int} $options
     */
    public function __construct(OriginFinder $origin, callable $sender, array $options, ?callable $clock = null, ?callable $ids = null)
    {
        $this->origin = $origin;
        $this->sender = $sender;
        $this->service = (string) ($options['service'] ?? 'laravel');
        $this->version = (string) ($options['version'] ?? self::VERSION);
        $this->maxQueries = max(0, (int) ($options['max_queries'] ?? 500));
        $this->maxSqlLength = max(1, (int) ($options['max_sql_length'] ?? 10000));
        $this->clock = $clock ?: function () { return microtime(true); };
        $this->ids = $ids ?: function (int $bytes) { return bin2hex(random_bytes($bytes)); };
    }

    public function startRequest(string $method, ?float $requestStart): void
    {
        try {
            $now = ($this->clock)();
            // php-fpm gives the moment the request arrived, which is what nginx measures too.
            // Long-running servers keep a stale value: fall back to now.
            $start = $requestStart !== null && $requestStart <= $now && $now - $requestStart < 300 ? $requestStart : $now;
            $this->trace = $this->newTrace(self::SERVER, strtoupper($method), $start);
            $this->trace['method'] = strtoupper($method);
        } catch (\Throwable $e) {
            $this->trace = null;
        }
    }

    public function finishRequest(?string $route, string $path, int $status): void
    {
        if (!$this->running(self::SERVER)) {
            return;
        }
        try {
            $t = &$this->trace;
            $t['end'] = ($this->clock)();
            $t['attributes'][] = self::kv('http.request.method', $t['method']);
            if ($route !== null && $route !== '') {
                $route = '/' . ltrim($route, '/');
                $t['name'] = $t['method'] . ' ' . $route;
                $t['attributes'][] = self::kv('http.route', $route);
            } else {
                $t['attributes'][] = self::kv('url.path', '/' . ltrim((string) strtok($path, '?'), '/'));
            }
            $t['attributes'][] = self::kv('http.response.status_code', $status);
            $t['error'] = $status >= 500;
        } catch (\Throwable $e) {
            $this->trace = null;
        }
    }

    public function startJob(string $name, string $queue): void
    {
        if ($this->trace !== null && $this->trace['end'] === null) {
            return; // a job run inside a request or another job belongs to it
        }
        try {
            $this->trace = $this->newTrace(self::CONSUMER, $name, ($this->clock)());
            $this->trace['attributes'][] = self::kv('messaging.destination.name', $queue);
        } catch (\Throwable $e) {
            $this->trace = null;
        }
    }

    public function finishJob(bool $failed): void
    {
        if (!$this->running(self::CONSUMER)) {
            return;
        }
        $this->trace['end'] = ($this->clock)();
        $this->trace['error'] = $failed;
    }

    /** @param float $milliseconds as measured by Laravel around the statement */
    public function recordQuery(string $sql, float $milliseconds, string $driver): void
    {
        if ($this->trace === null || $this->trace['end'] !== null) {
            return; // outside requests and jobs (commands, worker polling), or after the response
        }
        try {
            $end = ($this->clock)();
            if (count($this->trace['queries']) >= $this->maxQueries) {
                $this->trace['dropped']++;
                return;
            }
            $this->trace['queries'][] = [
                'sql' => strlen($sql) > $this->maxSqlLength ? substr($sql, 0, $this->maxSqlLength) : $sql,
                'system' => self::dbSystem($driver),
                'start' => max($this->trace['start'], $end - max(0.0, $milliseconds) / 1000),
                'end' => $end,
                'origin' => $this->origin->find(),
            ];
        } catch (\Throwable $e) {
            // never let observability break the query that was just run
        }
    }

    /** Sends the finished trace, if any, and forgets it. */
    public function flush(): void
    {
        if ($this->trace === null || $this->trace['end'] === null) {
            return;
        }
        $trace = $this->trace;
        $this->trace = null;
        try {
            ($this->sender)()->send($this->encode($trace));
        } catch (\Throwable $e) {
            // the agent is optional: a missing or broken one costs a trace, nothing else
        }
    }

    public function reset(): void
    {
        $this->trace = null;
    }

    private function running(int $kind): bool
    {
        return $this->trace !== null && $this->trace['kind'] === $kind && $this->trace['end'] === null;
    }

    private function newTrace(int $kind, string $name, float $start): array
    {
        return [
            'kind' => $kind, 'name' => $name, 'start' => $start, 'end' => null, 'error' => false,
            'traceId' => ($this->ids)(16), 'spanId' => ($this->ids)(8),
            'attributes' => [], 'queries' => [], 'dropped' => 0,
        ];
    }

    private function encode(array $t): string
    {
        $root = [
            'traceId' => $t['traceId'],
            'spanId' => $t['spanId'],
            'name' => $t['name'],
            'kind' => $t['kind'],
            'startTimeUnixNano' => self::nanos($t['start']),
            'endTimeUnixNano' => self::nanos($t['end']),
            'attributes' => $t['attributes'],
        ];
        if ($t['dropped'] > 0) {
            $root['attributes'][] = self::kv('slowpoke.dropped_queries', $t['dropped']);
        }
        if ($t['error']) {
            $root['status'] = ['code' => 2];
        }
        $spans = [$root];
        foreach ($t['queries'] as $q) {
            $attributes = [self::kv('db.system.name', $q['system']), self::kv('db.query.text', $q['sql'])];
            if ($q['origin'] !== null) {
                $attributes[] = self::kv('code.file.path', $q['origin'][0]);
                if ($q['origin'][1] !== null) {
                    $attributes[] = self::kv('code.line.number', $q['origin'][1]);
                }
            }
            $spans[] = [
                'traceId' => $t['traceId'],
                'spanId' => ($this->ids)(8),
                'parentSpanId' => $t['spanId'],
                'name' => strtoupper((string) strtok(ltrim($q['sql']), " \t\r\n(")),
                'kind' => self::CLIENT,
                'startTimeUnixNano' => self::nanos($q['start']),
                'endTimeUnixNano' => self::nanos($q['end']),
                'attributes' => $attributes,
            ];
        }
        $payload = ['resourceSpans' => [[
            'resource' => ['attributes' => [
                self::kv('service.name', $this->service),
                self::kv('telemetry.sdk.name', 'slowpoke-laravel'),
                self::kv('telemetry.sdk.language', 'php'),
                self::kv('telemetry.sdk.version', $this->version),
            ]],
            'scopeSpans' => [[
                'scope' => ['name' => 'slowpoke/laravel', 'version' => $this->version],
                'spans' => $spans,
            ]],
        ]]];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException('cannot encode trace');
        }
        return $json;
    }

    /** OTLP/JSON: 64-bit integers travel as strings. Microsecond precision is all PHP measures. */
    private static function nanos(float $seconds): string
    {
        return ((int) round($seconds * 1e6)) . '000';
    }

    private static function kv(string $key, $value): array
    {
        return ['key' => $key, 'value' => is_int($value) ? ['intValue' => (string) $value] : ['stringValue' => (string) $value]];
    }

    private static function dbSystem(string $driver): string
    {
        switch ($driver) {
            case 'pgsql':
                return 'postgresql';
            case 'sqlsrv':
                return 'microsoft.sql_server';
            default:
                return $driver;
        }
    }
}
