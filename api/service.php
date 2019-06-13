#!/usr/local/bin/php
<?php

use React\Http\Response;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;

require __DIR__ . '/vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$handleResponseMiddleware = function (Psr\Http\Message\ServerRequestInterface $request) use ($loop) {
    $path = $request->getUri()->getPath();
    $method = $request->getMethod();

    echo "[".$method."] ".$path."\n";

    // sync response
    if ($path === '/' && $method === 'GET') {
        return new React\Http\Response(200, array('Content-Type' => 'text/plain'), "Hello World!\n");
    }

    // delayed response
    if ($path === '/delayed' && $method === 'GET') {
        return new React\Promise\Promise(function ($resolve, $reject) use ($loop) {
            $loop->addTimer(1.5, function() use ($resolve) {
                $response = new React\Http\Response(200, array('Content-Type' => 'text/plain'), "Hello world delayed");
                $resolve($response);
            });
        });
    }

    // stream response
    if ($path === '/stream' && $method === 'GET') {
        $stream = new React\Stream\ThroughStream();

        $timer = $loop->addPeriodicTimer(1, function () use ($stream) {
            $stream->write(microtime(true) . PHP_EOL);
        });

        $loop->addTimer(10, function() use ($loop, $timer, $stream) {
            $loop->cancelTimer($timer);
            $stream->end();
        });

        return new React\Http\Response(200, array('Content-Type' => 'text/plain'), $stream);
    }

    // unhandler error
    if ($path === '/error' && $method === 'GET') {
        throw new RuntimeException('Error test');
    }

    return new React\Http\Response(404, ['Content-Type' => 'text/plain'],  'Not found');
};

$addTimeToRequestHeaderMiddleware = function (Psr\Http\Message\ServerRequestInterface $request, callable $next) {
    $request = $request->withHeader('Request-Time', time());
    $request = $request->withHeader('Test-Message', 'Testing ReactPHP!');
    return $next($request);
};

$addContentTypeToResponseMiddleware = function (Psr\Http\Message\ServerRequestInterface $request, callable $next) {
    $promise = React\Promise\resolve($next($request));
    return $promise->then(function (Psr\Http\Message\ResponseInterface $response) {
        return $response->withHeader('Content-Type', 'text/html');
    });
};

$errorResponseMiddleware = function (Psr\Http\Message\ServerRequestInterface $request, callable $next) {
    $promise = new React\Promise\Promise(function ($resolve) use ($next, $request) {
        $resolve($next($request));
    });
    return $promise->then(null, function (Exception $e) {
        return new Response(500, array(), 'Internal error: ' . $e->getMessage());
    });
};

$server = new React\Http\Server([
    new LimitConcurrentRequestsMiddleware(100), // 100 concurrent buffering handlers
    new RequestBodyBufferMiddleware(16 * 1024 * 1024), // 16 MiB
    $addTimeToRequestHeaderMiddleware,
    $addContentTypeToResponseMiddleware,
    $errorResponseMiddleware,
    $handleResponseMiddleware,
]);

$server->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    if ($e->getPrevious() !== null) {
        $previousException = $e->getPrevious();
        echo $previousException->getMessage() . PHP_EOL;
    }
});

$socket = new React\Socket\Server('0.0.0.0:8080', $loop);
$server->listen($socket);

echo "Server running at http://0.0.0.0:8080\n";

$loop->addPeriodicTimer(5, function () {
    $memory = memory_get_usage() / 1024;
    $formatted = number_format($memory, 3).'K';
    echo "Current memory usage: {$formatted}\n";
});

$loop->run();