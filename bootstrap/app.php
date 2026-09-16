<?php

use App\Http\Middleware\StaffToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'staff' => StaffToken::class,
        ]);

        /*
         | Railway (and any other platform-as-a-service) terminates TLS at its
         | edge and forwards plain HTTP to the container. Without trusting that
         | proxy, Laravel believes every request is http://, so route() builds
         | http:// links on an https:// site, the redirect after the staff login
         | is downgraded, and a `secure` session cookie is never sent back -
         | which looks exactly like "the login silently does nothing".
         |
         | '*' is correct here because the only route to the container is
         | Railway's own edge; there is no path for a client to set these
         | headers itself.
         */
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
