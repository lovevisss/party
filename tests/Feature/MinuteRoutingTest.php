<?php

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('minute routes match the same endpoints with and without production route caching', function (string $method, string $uri, string $name) {
    $router = app('router');
    $routes = $router->getRoutes();

    expect($routes->match(Request::create($uri, $method))->getName())->toBe($name);

    foreach ($routes as $route) {
        $route->prepareForSerialization();
    }

    $router->setCompiledRoutes($routes->compile());

    expect($router->getRoutes()->match(Request::create($uri, $method))->getName())->toBe($name);
})->with([
    ['GET', '/minutes/party-branch', 'minutes.type.index'],
    ['GET', '/minutes/party-government-joint', 'minutes.type.index'],
    ['GET', '/minutes/party-branch/create', 'minutes.create'],
    ['GET', '/minutes/party-government-joint/create', 'minutes.create'],
    ['POST', '/minutes/party-branch', 'minutes.store'],
    ['POST', '/minutes/party-government-joint', 'minutes.store'],
    ['GET', '/minutes/01990000-0000-7000-8000-000000000001', 'minutes.show'],
    ['GET', '/minutes/01990000-0000-7000-8000-000000000001/edit', 'minutes.edit'],
    ['PUT', '/minutes/01990000-0000-7000-8000-000000000001', 'minutes.update'],
]);

test('unknown meeting slugs cannot be matched as minute identifiers', function (bool $cached) {
    $router = app('router');
    if ($cached) {
        $router->setCompiledRoutes($router->getRoutes()->compile());
    }

    expect(fn () => $router->getRoutes()->match(Request::create('/minutes/unknown-type')))
        ->toThrow(NotFoundHttpException::class);
})->with([false, true]);
