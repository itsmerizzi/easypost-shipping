<?php

use App\Exceptions\LabelNotPersistedException;
use App\Exceptions\NoUspsRateException;
use App\Services\EasyPost\Exceptions\EasyPostException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Provider 5xx/401/403/transport failures are still reported (logged) by the handler.
        $exceptions->render(fn (EasyPostException $e, Request $request) => $e->isClientError()
            ? response()->json(['message' => $e->getMessage()], 422)
            : response()->json(['message' => 'Shipping provider unavailable, please try again.'], 502));

        $exceptions->render(fn (NoUspsRateException $e, Request $request) => response()->json(
            ['message' => 'No USPS rate available for this shipment.'],
            422,
        ));

        $exceptions->render(fn (LabelNotPersistedException $e, Request $request) => response()->json(
            ['message' => 'The label was purchased but could not be saved. Please contact support.'],
            502,
        ));
    })->create();
