<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ClientShopImportErrorExport implements FromCollection, WithHeadings
{
    private $errors = [];
    public function __construct($errors)
    {
        // $this->errors = $errors;
          $this->errors = $errors->map(function ($row) {
            return [
                'brand_name' => $row['brand_name'] ?? null,
                'shop_name' => $row['shop_name'] ?? null,
                'shop_location_coordinates' => $row['shop_location_coordinates'] ?? null,
                'shop_address' => $row['shop_address'] ?? null,
                'shop_zone' => $row['shop_zone'] ?? null,
                'shop_admin' => $row['shop_admin'] ?? null,
                'shop_admin_mail' => $row['shop_admin_mail'] ?? null,
                'shop_admin_mobile' => $row['shop_admin_mobile'] ?? null,
                'express_delivery_time_in_minutes' => $row['express_delivery_time_in_minutes'] ?? null,
                'verify_captain_reached_shop' => $row['verify_captain_reached_shop'] ?? null,
                'verify_captain_reached_location' => $row['verify_captain_reached_location'] ?? null,
                'password_for_branch' => $row['password_for_branch'] ?? null,
                'auto_assign_rule' => $row['auto_assign_rule'] ?? null,
                'select_delivery_price_rule' => $row['select_delivery_price_rule'] ?? null,
                'reference_id' => $row['reference_id'] ?? null,
                'leajlak_shop_id' => $row['leajlak_shop_id'] ?? null,
                'errors' => $row['errors'] ?? null,
            ];
        });
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return $this->errors;
    }

    public function headings(): array
    {
        return [
            'Brand Name',
            'Shop Name',
            'Shop Location Coordinates',
            'Shop Address',
            'Shop Zone',
            'Shop Admin',
            'Shop Admin Mail',
            'Shop Admin Mobile',
            'Express Delivery Time In Minutes',
            'Verify Captain Reached Shop',
            'Verify Captain Reached Location',
            'Password For Branch',
            'Auto Assign Rule',
            'Select Delivery Price Rule',
            'Reference Id',
            'Leajlak Shop ID',
            'Errors'
        ];
    }
}

