# Security

## Reporting a vulnerability

Please do not open a public issue. Report it privately through
[GitHub security advisories](https://github.com/christiancannata/slowpoke-laravel/security/advisories/new),
or write to christiancannata@gmail.com. Reports are answered as soon as possible, and fixed releases credit the reporter unless asked otherwise.

## Supported versions

The latest 0.x release receives security fixes.

## What this package does, and what it never does

It runs inside your application, so it is kept small and easy to read (about 700 lines in `src/`).

- It only **reads** what Laravel already exposes: the matched route, the response status, the SQL of each query as
  passed to PDO, and a stack trace **without arguments** to find the application file and line.
- It **never reads** binding values, request parameters, headers, cookies, sessions, users or exception messages.
- It **sends** one OTLP/JSON trace per request or job, after the response, only to a plain `http://` address on the
  same machine or a private network (`127.0.0.1:4318` by default). Public addresses are refused.
- It has a hard 0.1 s budget and swallows every error: a missing or broken agent can never break or slow a request.
- It does not write files, run shell commands, load remote code or phone home. Its only runtime dependencies are
  `illuminate/*` packages your application already has.

## How releases can be verified

- Every push runs the test suite on PHP 7.4 to 8.5 and Laravel 5.8 to 13, PHPStan and `composer audit`.
- The repository is scored by [OpenSSF Scorecard](https://scorecard.dev/viewer/?uri=github.com/christiancannata/slowpoke-laravel).
- Release archives carry a signed build provenance (Sigstore). To verify one:

  ```sh
  gh attestation verify slowpoke-laravel-v0.1.2.zip --repo christiancannata/slowpoke-laravel
  ```
