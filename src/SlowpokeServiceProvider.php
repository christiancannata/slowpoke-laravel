<?php

namespace Slowpoke\Laravel;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Slowpoke\Laravel\Http\TraceRequest;

class SlowpokeServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/slowpoke.php', 'slowpoke');
    }

    /** @return void */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/slowpoke.php' => $this->app->configPath('slowpoke.php')], 'slowpoke-config');
        }
        try {
            if (!filter_var($this->app['config']->get('slowpoke.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
                return;
            }
            $this->wire();
        } catch (\Throwable $e) {
            // a monitoring package must never stop the application from booting
        }
    }

    private function wire(): void
    {
        $app = $this->app;
        $config = $app['config'];

        $app->bindIf(Sender::class, function () use ($config) {
            return HttpSender::fromUrl((string) $config->get('slowpoke.endpoint'), (float) $config->get('slowpoke.timeout', 0.1)) ?: new NullSender();
        }, true);

        $app->singleton(Tracer::class, function ($app) use ($config) {
            $root = $config->get('slowpoke.code_root') ?: $app->basePath();
            return new Tracer(
                new OriginFinder($root, (int) $config->get('slowpoke.backtrace_limit', 60), [__DIR__]),
                function () use ($app) { return $app->make(Sender::class); },
                [
                    'service' => (string) ($config->get('slowpoke.service') ?: $config->get('app.name') ?: 'laravel'),
                    'max_queries' => (int) $config->get('slowpoke.max_queries', 500),
                    'max_sql_length' => (int) $config->get('slowpoke.max_sql_length', 10000),
                ]
            );
        });
        $tracer = $app->make(Tracer::class);
        $events = $app['events'];

        $events->listen(QueryExecuted::class, function ($event) use ($tracer) {
            $tracer->recordQuery((string) $event->sql, (float) $event->time, (string) $event->connection->getDriverName());
        });

        $this->traceRequests($tracer);
        if (filter_var($config->get('slowpoke.jobs', true), FILTER_VALIDATE_BOOLEAN)) {
            $this->traceJobs($tracer);
        }
    }

    private function traceRequests(Tracer $tracer): void
    {
        $prepend = function ($kernel) {
            if (method_exists($kernel, 'hasMiddleware') && $kernel->hasMiddleware(TraceRequest::class)) {
                return;
            }
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(TraceRequest::class);
            }
        };
        if ($this->app->resolved(HttpKernel::class)) {
            $prepend($this->app->make(HttpKernel::class));
        } else {
            $this->app->afterResolving(HttpKernel::class, $prepend);
        }
        // Terminating callbacks run after the response reached the client (fastcgi_finish_request).
        $this->app->terminating(function () use ($tracer) {
            $tracer->flush();
        });
    }

    private function traceJobs(Tracer $tracer): void
    {
        $events = $this->app['events'];
        $events->listen(JobProcessing::class, function ($event) use ($tracer) {
            if ($event->connectionName === 'sync') {
                return; // runs inside whatever dispatched it
            }
            $tracer->startJob((string) $event->job->resolveName(), (string) $event->job->getQueue());
        });
        $finish = function ($failed) use ($tracer) {
            return function ($event) use ($tracer, $failed) {
                if ($event->connectionName === 'sync') {
                    return;
                }
                $tracer->finishJob($failed);
                // A worker has no response to wait for: send now.
                $tracer->flush();
            };
        };
        $events->listen(JobProcessed::class, $finish(false));
        $events->listen(JobFailed::class, $finish(true));
        $events->listen(JobExceptionOccurred::class, $finish(true));
    }
}
