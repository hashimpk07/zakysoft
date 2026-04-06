<?php
namespace App\Adapters;

use App\Order;

interface DeliveryCharge {
    public function deliveryCharge(Order $order);
    public function charge($rule_id, $attribute);
}