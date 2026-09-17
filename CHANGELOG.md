# Changelog

## 0.1.0 - 2026-09-17

First release.

- One trace per HTTP request and queued job, sent to the local Slowpoke agent (OTLP/JSON) after the response.
- Route templates, status codes, job classes and queues.
- Every query with placeholders, its engine, duration and the application file and line that ran it
  (Blade views by template), skipping `vendor/` and the package itself.
- Hard 0.1 s budget, errors swallowed, plain http to local or private hosts only.
- PHP 7.4 to 8.x, Laravel 5.8 to 13.
