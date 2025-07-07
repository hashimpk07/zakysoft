<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\StockMovement;

class InventoryReportController extends Controller
{
    public function index(Request $request)
    {
        try{
            $productId = $request->query('product_id');
            $warehouseId = $request->query('warehouse_id');
    
            $cacheKey = "inventory_report_" . md5($productId . '_' . $warehouseId);

            return Cache::remember($cacheKey, 60, function () use ($productId, $warehouseId) {
                $query = StockMovement::query();

                if ($productId) $query->where('product_id', $productId);
                if ($warehouseId) $query->where('warehouse_id', $warehouseId);

                return $query->selectRaw('product_id, warehouse_id, SUM(CASE WHEN type = "in" THEN quantity ELSE -quantity END) as stock')
                ->groupBy('product_id', 'warehouse_id')
                ->get()
                 ->toArray();
            });
        }catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status'=>'error']);
        }
    }

}
