<?php

namespace App\Services;

use App\Cache\StreamlineCaptain;
use App\Cache\StreamlineOrder;
use App\Captain;
use App\Order;
use Illuminate\Support\Facades\Log;

class StreamlineCacheService
{
    public function updateOrder(Order $order)
    {
        try {
            if ($order->finished()) {
                (new StreamlineOrder())->delete($order->id);
                return;
            }

            $cache = StreamlineOrder::fromOrder($order);
            $cache->update($order->id, $cache->toArray());

        } catch (\Exception $e) {
            Log::error("Failed to update StreamlineOrder cache for order {$order->id}: " . $e->getMessage());
        }
    }

    public function updateCaptain(Captain $captain)
    {
        try {
            if (!$captain->isOnline()) {
                $this->removeCaptain($captain);
                return;
            }

            $cache = StreamlineCaptain::fromCaptain($captain);
            $cache->update($captain->id, $cache->toArray());
        } catch (\Exception $e) {
            Log::error("Failed to update StreamlineCaptain cache for captain {$captain->id}: " . $e->getMessage());
        }
    }

    public function removeCaptain(Captain $captain)
    {
        (new StreamlineCaptain())->delete($captain->id);
    }
}
