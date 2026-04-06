<?php

namespace App\Services\General\Orders;

use App\Imports\CompletedOrderImport;
use App\Services\General\DTO\ImportResultDTO;

final class CompletedOrderImportService{
     public function import(mixed $file): ImportResultDTO
    {
        $import = new CompletedOrderImport();
        $import->import($file);

        if ($import->failures()->isNotEmpty()) {
            return ImportResultDTO::failed(
                $this->writeErrorFile($import->failures())
            );
        }

        return ImportResultDTO::success();
    }

    private function writeErrorFile($failures): string
    {
        $errorFile = 'storage/import/orders/error/error-' . time() . '.csv';
        $fp        = fopen(public_path($errorFile), 'wb');

        fputcsv($fp, [
            'Order ID', 'Client Order Id', 'Client Name', 'Shop Name',
            'Customer Name', 'Mobile Number', 'Delivery Location',
            'Payment Type', 'Bill Amount', 'Captain', 'Order Created Date & Time',
            'Order Accepted', 'Start Ride', 'Reached shop', 'Order Picked',
            'Shipped', 'Reached Destination', 'Final Status Date & Time',
            'Order Status', 'Error',
        ]);

        $failures
            ->groupBy(fn($failure) => $failure->row())
            ->each(function ($errors) use ($fp) {
                $values = $this->sanitizeValues($errors[0]->values());
                $errorMessages = $errors
                    ->map(fn($row) => $row->errors()[0])
                    ->join(', ');

                fputcsv($fp, [...$values, $errorMessages]);
            });

        fclose($fp);

        return $errorFile;
    }

    private function sanitizeValues(array $values): array
    {
        $removeKeys = ['captain_id', 'client_id', 'shop_id', 'client_order_id_checked', 'error'];

        return array_diff_key($values, array_flip($removeKeys));
    }
}