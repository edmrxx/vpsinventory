<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * One shape for every API reply.
 *
 * Success:
 *   { "success": true, "message": "...", "data": {...}, "meta": {...} }
 *
 * Failure:
 *   { "success": false, "message": "...", "errors": {...} }
 *
 * `errors` is always a map of field name to a list of messages, even when the
 * failure has nothing to do with a field, so a mobile client can read it the
 * same way every time.
 */
class ApiResponse
{
    /**
     * A plain success.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * A single record, through its API resource.
     */
    public static function resource(
        JsonResource $resource,
        ?string $message = null,
        int $status = 200,
    ): JsonResponse {
        return static::success(
            $resource->resolve(request()),
            $message,
            $status
        );
    }

    /**
     * A page of records. The paging figures go into `meta`, so `data` stays
     * a plain list and a client never has to dig for the rows.
     *
     * @param  array<string, mixed>  $extraMeta  Figures about the whole set
     *                                           rather than this page, such
     *                                           as a stock valuation.
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        string $resourceClass,
        array $extraMeta = [],
        ?string $message = null,
    ): JsonResponse {
        /** @var ResourceCollection $collection */
        $collection = $resourceClass::collection($paginator->getCollection());

        return static::success(
            $collection->resolve(request()),
            $message,
            meta: array_merge([
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ], $extraMeta)
        );
    }

    /**
     * A failure.
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function error(
        string $message,
        int $status = 400,
        array $errors = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    /**
     * Nothing to send back, for example after a logout.
     */
    public static function noContent(?string $message = null): JsonResponse
    {
        return static::success(null, $message);
    }
}
