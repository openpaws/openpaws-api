<?php

use App\Exceptions\DomainError;
use App\Http\Middleware\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global (not only the api group) so that 404s for unmatched routes also carry the id.
        $middleware->prepend(RequestId::class);
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $error = fn (string $code, string $message, int $status, ?array $fields = null): JsonResponse => response()->json([
            'error' => array_filter(
                ['code' => $code, 'message' => $message, 'fields' => $fields],
                fn ($v) => $v !== null,
            ),
        ], $status);

        $exceptions->render(fn (DomainError $e, Request $r) => $e->render());
        $exceptions->render(fn (ValidationException $e, Request $r) => $error('validation_failed', $e->getMessage(), 422, $e->errors()));
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $error('unauthenticated', 'Unauthenticated.', 401));
        $exceptions->render(fn (AuthorizationException $e, Request $r) => $error('forbidden', 'Forbidden.', 403));
        $exceptions->render(fn (ModelNotFoundException $e, Request $r) => $error('not_found', 'Not found.', 404));
        $exceptions->render(fn (NotFoundHttpException $e, Request $r) => $error('not_found', 'Not found.', 404));
        $exceptions->render(fn (ThrottleRequestsException $e, Request $r) => $error('rate_limited', 'Too many requests.', 429));
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($error) {
            $status = $e->getStatusCode();
            if ($status === 403) {
                return $error('forbidden', 'Forbidden.', 403);
            }
            if ($status === 429) {
                return $error('rate_limited', 'Too many requests.', 429);
            }

            return $error($status >= 500 ? 'server_error' : 'http_error', $e->getMessage() ?: 'Request failed.', $status);
        });
        $exceptions->render(fn (Throwable $e, Request $r) => $error(
            'server_error',
            config('app.debug') ? $e->getMessage() : 'Server error.',
            500,
        ));
    })->create();
