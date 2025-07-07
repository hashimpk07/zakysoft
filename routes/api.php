<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\InventoryReportController;
use App\Http\Controllers\API\StockMovementController;

use Illuminate\Support\Facades\Hash;
use App\Models\User;

Route::post('/login', function (Request $request) {
    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    return response()->json([
        'token' => $user->createToken('api-token')->plainTextToken
    ]);
});;



Route::middleware('auth:sanctum')->group(function () {
    Route::post('/stock-movements', [StockMovementController::class, 'store']);
});

Route::get('/inventory/report', [InventoryReportController::class, 'index']);
