<?php

namespace Slowpoke\Laravel\Tests\Feature;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Slowpoke\Laravel\Tests\Fixtures\App\SendInvoices;
use Slowpoke\Laravel\Tests\Fixtures\FakeSender;
use Slowpoke\Laravel\Tests\Fixtures\QueuedJob;

class JobTraceTest extends TestCase
{
    private function job(): QueuedJob
    {
        $payload = json_encode(['displayName' => SendInvoices::class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]);
        return new QueuedJob($this->app, $payload, 'redis', 'invoices');
    }

    public function testQueuedJobIsATraceOfItsOwn(): void
    {
        $job = $this->job();
        $this->app['events']->dispatch(new JobProcessing('redis', $job));
        (new SendInvoices())->handle();
        $this->app['events']->dispatch(new JobProcessed('redis', $job));

        [$root, $queries] = $this->sender->onlyTrace();
        $this->assertSame(5, $root['kind']);
        $this->assertSame(SendInvoices::class, $root['name']);
        $this->assertSame('invoices', FakeSender::attr($root, 'messaging.destination.name'));
        $this->assertCount(1, $queries);
        $this->assertSame('tests/Fixtures/App/SendInvoices.php', FakeSender::attr($queries[0], 'code.file.path'));
        $this->assertSame((string) SendInvoices::QUERY_LINE, FakeSender::attr($queries[0], 'code.line.number'));
    }

    public function testFailedJob(): void
    {
        $job = $this->job();
        $this->app['events']->dispatch(new JobProcessing('redis', $job));
        $this->app['events']->dispatch(new JobFailed('redis', $job, new \RuntimeException('boom')));
        [$root] = $this->sender->onlyTrace();
        $this->assertSame(2, $root['status']['code']);
        $this->assertStringNotContainsString('boom', $this->sender->payloads[0]);
    }

    public function testSyncJobsInsideARequestAreNotSeparateTraces(): void
    {
        $job = $this->job();
        $this->app['events']->dispatch(new JobProcessing('sync', $job));
        (new SendInvoices())->handle();
        $this->app['events']->dispatch(new JobProcessed('sync', $job));
        $this->assertSame([], $this->sender->payloads);
    }

    public function testWorkerQueriesBetweenJobsAreIgnored(): void
    {
        (new SendInvoices())->handle();
        $this->app->terminate();
        $this->assertSame([], $this->sender->payloads);
    }
}
