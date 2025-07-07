<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\StockMovement;
use Illuminate\Database\Seeder;

class StockMovementSeeder extends Seeder
{
    public function run()
    {
        $products = Product::pluck('id');
        $warehouses = Warehouse::pluck('id');

        $stockLevels = [];

        for ($i = 0; $i < 10000; $i++) {
            $productId = $products->random();
            $warehouseId = $warehouses->random();
            $key = $productId . '-' . $warehouseId;

            if (!isset($stockLevels[$key])) {
                $stockLevels[$key] = 0;
            }

            $type = ['in', 'out'][rand(0, 1)];
            $quantity = rand(1, 50);

            if ($type === 'out') {
                if ($stockLevels[$key] < $quantity) {
                    $type = 'in';
                } else {
                    $stockLevels[$key] -= $quantity;
                }
            }

            if ($type === 'in') {
                $stockLevels[$key] += $quantity;
            }

            StockMovement::create([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'type' => $type,
                'movement_date' => now()->subDays(rand(0, 60)),
            ]);
        }
    }
}