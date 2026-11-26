<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiExceptions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
    // Coolify's proxy handles HTTPS, so trust its forwarded headers.
    $middleware->trustProxies(at: '*');

    // Every reply, web and API alike, carries the safety headers.
    $middleware->append(SecurityHeaders::class);

    $middleware->web(append: [
        HandleInertiaRequests::class,
        AddLinkHeadersForPreloadedAssets::class,
    ]);

        $middleware->redirectGuestsTo(fn () => route('login'));

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            // The API answers in one shape, whatever went wrong, so a mobile
            // client never has to parse an HTML error page.
            if ($request->is('api/*')) {
                return ApiExceptions::render($response, $exception, $request);
            }

            $status = $response->getStatusCode();

            // A stale CSRF token means the session is gone. Send the user
            // back to the login page with a flag the front end turns into
            // an alert.
            if ($status === 419) {
                return redirect()->route('login')->with('sessionExpired', true);
            }

            // Inertia cannot show a plain error page inside the app shell, so
            // these statuses are rendered as a page of their own. 500s stay
            // as-is while developing, to keep the stack trace.
            $renderable = app()->environment('local')
                ? [403, 404]
                : [403, 404, 500, 503];

            if ($request->header('X-Inertia') && in_array($status, $renderable, true)) {
                return Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
