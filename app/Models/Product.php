<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
