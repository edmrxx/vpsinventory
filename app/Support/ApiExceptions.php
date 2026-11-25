<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Turns any exception under /api into the one failure shape.
 *
 * The message is written for the person holding the phone. The details that
 * only help a developer stay in the log, except while running locally.
 */
class ApiExceptions
{
    public static function render(
        Response $response,
        Throwable $exception,
        Request $request,
    ): Response {
        return match (true) {
            $exception instanceof ValidationException => ApiResponse::error(
                'The details given are not valid.',
                422,
                $exception->errors()
            ),

            $exception instanceof AuthenticationException => ApiResponse::error(
                'This request needs a valid token.',
                401
            ),

            $exception instanceof AuthorizationException => ApiResponse::error(
                $exception->getMessage() ?: 'You may not do this.',
                403
            ),

            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => ApiResponse::error(
                'That record does not exist.',
                404
            ),

            $exception instanceof TooManyRequestsHttpException => ApiResponse::error(
                'Too many requests. Please wait and try again.',
                429
            ),

            default => static::fallback($response, $exception),
        };
    }

    /**
     * Anything else. An HTTP exception keeps its own status; everything else
     * is a 500, and its message is only shown while running locally.
     */
    protected static function fallback(Response $response, Throwable $exception): Response
    {
        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            return ApiResponse::error(
                $exception->getMessage() ?: static::statusMessage($status),
                $status
            );
        }

        return ApiResponse::error(
            app()->environment('local')
                ? $exception->getMessage()
                : 'Something went wrong. Please try again.',
            $response->getStatusCode() >= 400 ? $response->getStatusCode() : 500
        );
    }

    protected static function statusMessage(int $status): string
    {
        return match ($status) {
            400 => 'That request could not be read.',
            405 => 'That method is not allowed here.',
            409 => 'That clashes with something already there.',
            503 => 'The service is down for maintenance.',
            default => 'The request could not be finished.',
        };
    }
}
