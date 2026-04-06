<?php

namespace App\Services\Client;

use App\Repositories\Client\ClientDashboardDropdownRepository;  
use App\Filter\OrderFilter;

class ClientDashboardDropdownService
{
    protected $orders;

    public function __construct(ClientDashboardDropdownRepository $orders)
    {
        $this->orders = $orders;
    }

    public function getClientsDropdown()
    {
        return $this->orders->getClients();
    }

    public function getShopsDropdown()
    {
        return $this->orders->getShops();
    }

    public function getZonesDropdown()
    {
        return $this->orders->getZones();
    }

    public function getTimeSlotsDropdown()
    {
        return $this->orders->getTimeSlots();
    }
}
