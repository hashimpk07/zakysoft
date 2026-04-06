<?php

namespace App\Repositories\General\ClientReports;

use App\Interfaces\General\ClientReports\ClientOrderInterface;
use App\ClientShop;
use App\Order;

class ClientOrderRepository implements ClientOrderInterface
{
    
    public function getClientShopOrders($query,$perPage)
    {
        return (new ClientShop)
            ->scopeBelongsToMe($query)
            ->paginate($perPage);
    }
}