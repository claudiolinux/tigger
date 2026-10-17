# Tigger

A tiny, Express.js-inspired micro-framework for **PHP 8.3+** with **zero dependencies**.
Runs on any Apache host (including free shared hosting such as InfinityFree).

```bash
composer require tigger/tigger
```

## Quick start

Create `index.php` in your project root (it plays the role of `server.js`):

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Tigger\App;
use Tigger\Http\Request;
use Tigger\Http\Response;

$app = new App();

$app->use(function (Request $req, Response $res, callable $next): void {
    $next(); // global middleware
});

$app->get('/', function (Request $req, Response $res): void {
    $res->json(['message' => 'Hello, World!']);
});

$app->get('/users/:id', fn (Request $req, Response $res) => $res->json(['id' => $req->param('id')]))
    ->where('id', '[0-9]+')
    ->middleware($auth)
    ->name('users.show');

$app->run();
```

Copy `examples/.htaccess` next to your `index.php`. It routes everything to `index.php`,
serves real files (your `public/` assets) directly and blocks `vendor/`, `composer.json`, etc.

## Handlers & middleware

* Handler: `function (Request $req, Response $res): mixed`
* Middleware: `function (Request $req, Response $res, callable $next): void` — call `$next()` to continue.
* A handler may respond through `$res`, or simply **return** an array/object (JSON) or a string (HTML).
* A `: void` handler must not `return` a value — call `$res->json(...)` without `return`.

## Router

```php
$api = new Tigger\Routing\Router();
$api->get('/ping', fn () => ['pong' => true]);
$app->use('/api', $api);   // GET /api/ping
```

Patterns: `/users/:id`, `/files/*` (captured as `wildcard`). Constrain with `->where('id', '[0-9]+')`.
Unknown path → 404, known path with another method → 405 + `Allow`.

## Built-in middleware

* `Tigger\Middleware\SecureHeaders` — nosniff, frame, referrer policy, HSTS (HTTPS only).
* `Tigger\Middleware\Cors` — origin allow-list, preflight, credentials.

## Security notes

* Header/cookie values containing line breaks are rejected (no header injection).
* The client IP is `REMOTE_ADDR` only; proxy headers are never trusted.
* JSON/urlencoded bodies are capped at 1 MiB (413 beyond that); invalid JSON → 400.
* With `new App(debug: false)` (default) internals are never shown and `display_errors` is off.
* `$res->redirect()` does not validate the URL — never pass raw user input.

## Tests

```bash
php tests/smoke.php
```
