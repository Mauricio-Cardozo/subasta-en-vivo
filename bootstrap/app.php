<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureHost;
use App\Http\Middleware\EnsureParticipant;
use App\Http\Middleware\ResolveBroadcastIdentity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // `/broadcasting/auth` autoriza el canal de presencia con la identidad de
    // la sesión: no hay cuentas de usuario (5.5).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => ['web', ResolveBroadcastIdentity::class],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignCorrelationId::class);

        $middleware->alias([
            'host' => EnsureHost::class,
            'participant' => EnsureParticipant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
