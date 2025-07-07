<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\StockMovement;
use App\Http\Requests\StoreStockMovementRequest;
use App\Events\StockMovementRecorded;
use Illuminate\Validation\ValidationException;

class StockMovementController extends Controller
{
    public function store(StoreStockMovementRequest $request)
    {
        try {
            $data = $request->validated();
            if ($data['type'] === 'out') {
                $currentStock = StockMovement::where('product_id', $data['product_id'])
                    ->where('warehouse_id', $data['warehouse_id'])
                    ->selectRaw('SUM(CASE WHEN type = "in" THEN quantity ELSE -quantity END) as stock')
                    ->value('stock');

                if ($currentStock < $data['quantity']) {
                    throw ValidationException::withMessages([
                        'quantity' => ['Not enough stock. Current available stock is ' . ($currentStock ?? 0)],
                    ]);
                }
            }

            DB::transaction(function () use ($data) {
                $movement = StockMovement::create($data);
                event(new StockMovementRecorded($movement));
            });

            return response()->json([
                'status' => 'success',
                'status_code' => 200,
                'message' => 'Stock movement recorded successfully.'
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'status_code' => 422,
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'status_code' => 500,
                'message' => 'Something went wrong.',
                'error_detail' => $e->getMessage()
            ], 500);
        }
    }
}
