# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [0.1.0] - 2026-11-20

### Added
- First public release of the A2A Laravel package on `tibor-src/a2a-php` ^0.1.0, targeting A2A specification **v1.0.1** (protocol version `1.0`).
- Service provider auto-discovery, config, `A2A` facade, and `Route::a2a()` for JSON-RPC, HTTP+JSON, SSE, and `/.well-known/agent-card.json`.
- Laravel AI SDK bridge: serve a `Laravel\Ai\Contracts\Agent` over A2A, and call a remote A2A agent as a `Laravel\Ai\Contracts\Tool`.
- Eloquent, cache, file, and in-memory task stores; queued execution; push notification jobs with retry; Sanctum/Passport bearer auth mapping.
- `A2A::fake()`, Pest tests, and an official TCK job against a Testbench workbench (sync and queued).
