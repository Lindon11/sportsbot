<?php

namespace App\Core\Http\Controllers;

use App\Core\Exceptions\GameException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

abstract class Controller
{
    use AuthorizesRequests;
    /**
     * Return a success JSON response.
     */
    protected function successResponse(mixed $data = null, string $message = 'Success', int $status = 200): JsonResponse
    {
        $response = ['success' => true, 'message' => $message];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $status);
    }

    /**
     * Return an error JSON response.
     */
    protected function errorResponse(string $message = 'Error', int $status = 400, mixed $errors = null): JsonResponse
    {
        $response = ['success' => false, 'message' => $message];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }

    /**
     * Handle an exception caught in a game action controller method.
     *
     * GameExceptions carry intentional, user-readable game logic messages
     * (e.g. "Not enough energy.", "You are already in jail.") and are returned
     * directly to the client.
     *
     * Any other Throwable is logged server-side and replaced with a generic
     * message so internal details (SQL errors, file paths, stack info) are
     * never exposed to API clients.
     */
    protected function handleGameException(\Throwable $e, int $gameErrorStatus = 422): JsonResponse
    {
        if ($e instanceof GameException) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], $gameErrorStatus);
        }

        Log::error(static::class . ': ' . $e->getMessage(), ['exception' => $e]);

        return response()->json(['success' => false, 'error' => 'An unexpected error occurred. Please try again.'], 500);
    }

    /**
     * Return a paginated JSON response.
     */
    protected function paginatedResponse(mixed $paginator, string $message = 'Success'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
