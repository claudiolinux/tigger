<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Tigger\App;
use Tigger\Http\Request;
use Tigger\Http\Response;
use Tigger\Middleware\Cors;
use Tigger\Middleware\SecureHeaders;
use Tigger\Routing\Router;

$app = new App(debug: false);

// Global middleware
$app->use(new SecureHeaders());
$app->use(new Cors(['origins' => ['*']]));
$app->use(function (Request $req, Response $res, callable $next): void {
    $start = microtime(true);
    $next();
    $res->header('X-Response-Time', round((microtime(true) - $start) * 1000, 2) . 'ms');
});

// Plain route (a `void` handler must NOT `return` a value)
$app->get('/', function (Request $req, Response $res): void {
    $res->json(['message' => 'Hello, World!']);
});

// Named route with a constrained parameter
$app->get('/users/:id', function (Request $req, Response $res) {
    return $res->json(['id' => (int) $req->param('id')]);
})->where('id', '[0-9]+')->name('users.show');

// Route-level middleware
$auth = function (Request $req, Response $res, callable $next): void {
    if ($req->bearerToken() === null) {
        $res->status(401)->json(['error' => 'Unauthorized']);
        return;
    }
    $next();
};

$app->get('/me', fn (Request $req, Response $res) => ['user' => 'claudio'])->middleware($auth);

// Mounted router, like express.Router()
$api = new Router();
$api->get('/ping', fn () => ['pong' => true]);
$app->use('/api', $api);

$app->run();
