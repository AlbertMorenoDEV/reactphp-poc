#!/usr/local/bin/php
<?php

require __DIR__ . '/vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$server = new React\Http\Server(function (Psr\Http\Message\ServerRequestInterface $request) {
    $path = $request->getUri()->getPath();
    $method = $request->getMethod();

    echo "[".$method."] ".$path."\n";

    if ($path === '/') {
        if ($method === 'GET') {
            return new React\Http\Response(
                200,
                array('Content-Type' => 'text/plain'),
                "Hello World!\n"
            );
        }
    }

    return new React\Http\Response(404, ['Content-Type' => 'text/plain'],  'Not found');
});

$socket = new React\Socket\Server('0.0.0.0:8080', $loop);
$server->listen($socket);

echo "Server running at http://0.0.0.0:8080\n";

$loop->run();
