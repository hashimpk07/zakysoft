<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;

trait JsonResponseTrait
{
    /**
     * Send a success JSON response.
     *
     * @param mixed  $data
     * @param string $message
     * @param int    $code
     * @return \Illuminate\Http\JsonResponse
     */
    protected function successResponse($data = null, ?string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /**
     * Send an error JSON response.
     *
     * @param string $message
     * @param int    $code
     * @param mixed  $errors
     * @return \Illuminate\Http\JsonResponse
     */
    protected function errorResponse(string $message = 'Error', int $code = 400, $errors = null): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
            'errors'  => $errors,
        ], $code);
    }

    /**
     * Send a validation error JSON response.
     *
     * @param \Illuminate\Validation\ValidationException|MessageBag|array $errors
     * @param string|null $message
     * @return \Illuminate\Http\JsonResponse
     */
    protected function validationErrorResponse($errors, string $message = null): JsonResponse
    {
        if ($errors instanceof ValidationException) {
            $errors = $errors->errors();
            $message = $message ?? 'Validation failed';
        } elseif ($errors instanceof MessageBag) {
            $errors = $errors->toArray();
            $message = $message ?? 'Validation failed';
        } elseif (!is_array($errors)) {
            $errors = ['error' => $errors];
        }

        return response()->json([
            'status'  => 'fail',
            'message' => $message ?? 'Invalid input.',
            'errors'  => $errors,
        ], 422);
    }
}
