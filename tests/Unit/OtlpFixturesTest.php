<?php

namespace Slowpoke\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slowpoke\Laravel\OriginFinder;
use Slowpoke\Laravel\Tests\Fixtures\FakeSender;
use Slowpoke\Laravel\Tracer;

/**
 * spec/laravel_otlp_fixtures.json holds payloads exactly as this package sends them, with what the
 * agent must read from each one. The Go receiver test replays them: change both together.
 * Regenerate with UPDATE_FIXTURES=1 ./bin/test 8.3 --filter OtlpFixtures
 */
class OtlpFixturesTest extends TestCase
{
    private function file(): string
    {
        return dirname(__DIR__, 4) . '/spec/laravel_otlp_fixtures.json';
    }

    private function scenarios(): array
    {
        $out = [];

        [$tracer, $sender, $clock, $origin] = $this->tracer();
        $tracer->startRequest('GET', $clock->now);
        $clock->now += 0.045;
        $origin->at = ['app/Http/Controllers/OrderController.php', 18];
        $tracer->recordQuery('select * from `orders` where `status` = ? order by `created_at` desc limit 25', 41.2, 'mysql');
        $origin->at = ['resources/views/orders/index.blade.php', null];
        for ($i = 0; $i < 6; $i++) {
            $clock->now += 0.002;
            $tracer->recordQuery('select * from `customers` where `customers`.`id` = ? limit 1', 0.9, 'mysql');
        }
        $clock->now += 0.01;
        $tracer->finishRequest('orders', '/orders', 200);
        $tracer->flush();
        $out[] = [
            'name' => 'request with an N+1 in a Blade view',
            'payload' => json_decode($sender->payloads[0], true),
            'expect' => [
                'route' => 'GET /orders', 'status' => 200, 'requests' => 1, 'source' => 'otlp:shop',
                'queries' => [
                    ['statement' => 'select * from `orders` where `status` = ? order by `created_at` desc limit 25', 'n' => 1, 'origin' => 'app/Http/Controllers/OrderController.php:18', 'n_plus_one' => false],
                    ['statement' => 'select * from `customers` where `customers`.`id` = ? limit 1', 'n' => 6, 'origin' => 'resources/views/orders/index.blade.php', 'n_plus_one' => true],
                ],
            ],
        ];

        [$tracer, $sender, $clock, $origin] = $this->tracer();
        $tracer->startRequest('PUT', $clock->now);
        $origin->at = ['app/Http/Controllers/OrderController.php', 40];
        $clock->now += 0.02;
        $tracer->recordQuery('update "orders" set "status" = ?, "updated_at" = ? where "id" = ?', 3.5, 'pgsql');
        $clock->now += 0.001;
        $tracer->finishRequest('api/orders/{order}', '/api/orders/981', 500);
        $tracer->flush();
        $out[] = [
            'name' => 'route template with a parameter, server error, Postgres',
            'payload' => json_decode($sender->payloads[0], true),
            'expect' => [
                'route' => 'PUT /api/orders/{order}', 'status' => 500, 'requests' => 1, 'source' => 'otlp:shop',
                'queries' => [
                    ['statement' => 'update "orders" set "status" = ?, "updated_at" = ? where "id" = ?', 'n' => 1, 'origin' => 'app/Http/Controllers/OrderController.php:40', 'n_plus_one' => false],
                ],
            ],
        ];

        [$tracer, $sender, $clock, $origin] = $this->tracer();
        $tracer->startRequest('GET', $clock->now);
        $clock->now += 0.001;
        $tracer->finishRequest(null, '/.env', 404);
        $tracer->flush();
        $out[] = [
            'name' => 'no matching route: the path stands in for the route',
            'payload' => json_decode($sender->payloads[0], true),
            'expect' => ['route' => 'GET /.env', 'status' => 404, 'requests' => 1, 'source' => 'otlp:shop', 'queries' => []],
        ];

        [$tracer, $sender, $clock, $origin] = $this->tracer();
        $tracer->startJob('App\\Jobs\\SendInvoices', 'emails');
        $origin->at = ['app/Jobs/SendInvoices.php', 31];
        $clock->now += 0.3;
        $tracer->recordQuery('select * from `invoices` where `sent_at` is null', 250.0, 'mysql');
        $origin->at = null; // a query issued from framework code only
        $clock->now += 0.01;
        $tracer->recordQuery('delete from `cache` where `key` in (?)', 1.0, 'mysql');
        $clock->now += 0.1;
        $tracer->finishJob(false);
        $tracer->flush();
        $out[] = [
            'name' => 'queued job: queries without an HTTP request',
            'payload' => json_decode($sender->payloads[0], true),
            'expect' => [
                // A job is a run on the Jobs page, never an endpoint, and its queries hang from it.
                'route' => 'job App\\Jobs\\SendInvoices', 'status' => 0, 'requests' => 1, 'source' => '',
                'job' => ['kind' => 'job', 'name' => 'App\\Jobs\\SendInvoices', 'runs' => 1, 'failed' => 0],
                'queries' => [
                    ['statement' => 'select * from `invoices` where `sent_at` is null', 'n' => 1, 'origin' => 'app/Jobs/SendInvoices.php:31', 'n_plus_one' => false],
                    ['statement' => 'delete from `cache` where `key` in (?)', 'n' => 1, 'origin' => '', 'n_plus_one' => false],
                ],
            ],
        ];

        return $out;
    }

    private function tracer(): array
    {
        $sender = new FakeSender();
        $clock = new \stdClass();
        $clock->now = 1760000000.0;
        $origin = new class('/var/www/app', 40, []) extends OriginFinder {
            public $at = null;
            public function find(): ?array { return $this->at; }
        };
        $n = 0;
        $ids = function (int $bytes) use (&$n) {
            $n++;
            return str_pad(dechex($n), $bytes * 2, $bytes === 16 ? 'a' : 'b', STR_PAD_LEFT);
        };
        $tracer = new Tracer($origin, function () use ($sender) { return $sender; }, ['service' => 'shop', 'version' => 'fixture'],
            function () use ($clock) { return $clock->now; }, $ids);
        return [$tracer, $sender, $clock, $origin];
    }

    public function testPayloadsMatchTheSharedFixtures(): void
    {
        $scenarios = $this->scenarios();
        if (getenv('UPDATE_FIXTURES')) {
            file_put_contents($this->file(), json_encode($scenarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
        if (!is_file($this->file())) {
            $this->markTestSkipped('spec/ is only in the Slowpoke repository');
        }
        $this->assertSame(json_decode(file_get_contents($this->file()), true), $scenarios);
    }
}
