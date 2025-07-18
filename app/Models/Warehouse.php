<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory;
    public function stockMovements()
{
    return $this->hasMany(StockMovement::class);
}
}
