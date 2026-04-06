<?php

namespace App\Services;

class ReportFieldsService
{
    protected static $reportFields = [
        'high_level_report' => [
            ['key' => 'order_id', 'label' => 'Order ID'],
            ['key' => 'client_order_id', 'label' => 'Client Order ID'],
            ['key' => 'order_type', 'label' => 'Order Type'],
            ['key' => 'order_payment_mode', 'label' => 'Order Payment Type'],
            ['key' => 'cod_amount', 'label' => 'COD Amount'],
            ['key' => 'client_name', 'label' => 'Client Name'],
            ['key' => 'shop_name', 'label' => 'Shop Name'],
            ['key' => 'zone_name', 'label' => 'Shop Zone'],
            ['key' => 'region_name', 'label' => 'Shop Area'],
            ['key' => 'quadrant_name', 'label' => 'Shop Region'],
            ['key' => 'captain_name', 'label' => 'Captain'],
            ['key' => 'iqama_no', 'label' => 'Iqama No'],
            ['key' => 'captain_rule_name', 'label' => 'Captain Assigned Rule'],
            ['key' => 'captain_employment_type_name', 'label' => 'Captain Employment Type'],
            ['key' => 'assigned_by', 'label' => 'Assigned By'],
            ['key' => 'order_status_name', 'label' => 'Order Status'],
            ['key' => 'cancellation_reason', 'label' => 'Cancellation Reason'],
            ['key' => 'cancelled_by', 'label' => 'Cancelled By'],
            ['key' => 'created_at', 'label' => 'Date'],
            ['key' => 'created_at_time', 'label' => 'New Order (Created At)'],
            ['key' => 'order_accepted_at', 'label' => 'Order Accepted'],
            ['key' => 'order_accepted_at_time', 'label' => 'Order Accepted Time'],
            ['key' => 'start_ride_at', 'label' => 'Start Ride'],
            ['key' => 'start_ride_at_time', 'label' => 'Start Ride Time'],
            ['key' => 'reached_shop_at', 'label' => 'Reached Shop'],
            ['key' => 'reached_shop_at_time', 'label' => 'Reached Shop Time'],
            ['key' => 'order_picked_at', 'label' => 'Order Picked'],
            ['key' => 'order_picked_at_time', 'label' => 'Order Picked Time'],
            ['key' => 'shipped_at', 'label' => 'Shipped'],
            ['key' => 'shipped_at_time', 'label' => 'Shipped Time'],
            ['key' => 'reached_dest_at', 'label' => 'Reached Destination'],
            ['key' => 'reached_dest_at_time', 'label' => 'Reached Destination Time'],
            ['key' => 'business_day', 'label' => 'Business Day'],
            ['key' => 'final_status_at', 'label' => 'Final Status'],
            ['key' => 'final_status_at_time', 'label' => 'Final Status Time'],
            ['key' => 'acceptance_time', 'label' => 'Acceptance Time'],
            ['key' => 'arrival_time','label'=> "Arrival Time"],
            ['key' => 'reached_time_taken', 'label' => 'Reached Time'],
            ['key' => 'picked_time_taken', 'label' => 'Picked Time'],
            ['key' => 'delivered_time_taken', 'label' => 'Pickup to Delivery Time'],
            ['key' => 'total_time_taken', 'label' => 'Process Time In Minutes'],
            ['key' => 'distance', 'label' => 'Distance B/W'],
            ['key' => 'is_relocated', 'label' => 'Is Relocated'],
            ['key' => 'location', 'label' => 'Default Location'],
            ['key' => 'relocation_history', 'label' => 'Relocation History'],
            ['key' => 'delivery_cordinates', 'label' => 'Delivery Coordinates'],
            ['key' => 'auto_assign_attempts', 'label' => 'Auto Assign Attempts'],
        ],
        'high_level_client_report'=> [
            ['key' => 'order_id', 'label' => 'Order ID'],
            ['key' => 'client_order_id', 'label' => 'Client Order ID'],
            ['key' => 'order_type', 'label' => 'Order Type'],
            // ['key' => 'order_payment_mode', 'label' => 'Order Payment Type'],
            ['key' => 'cod_amount', 'label' => 'COD Amount'],
            ['key' => 'client_name', 'label' => 'Client Name'],
            ['key' => 'shop_name', 'label' => 'Shop Name'],
            ['key' => 'zone_name', 'label' => 'Shop Zone'],
            ['key' => 'region_name', 'label' => 'Shop Area'],
            ['key' => 'quadrant_name', 'label' => 'Shop Region'],
            ['key' => 'captain_name', 'label' => 'Captain'],
            // ['key' => 'iqama_no', 'label' => 'Iqama No'],
            // ['key' => 'captain_rule_name', 'label' => 'Captain Assigned Rule'],
            // ['key' => 'captain_employment_type_name', 'label' => 'Captain Employment Type'],
            ['key' => 'assigned_by', 'label' => 'Assigned By'],
            ['key' => 'order_status_name', 'label' => 'Order Status'],
            ['key' => 'cancellation_reason', 'label' => 'Cancellation Reason'],
            ['key' => 'cancelled_by', 'label' => 'Cancelled By'],
            ['key' => 'created_at', 'label' => 'Date'],
            ['key' => 'created_at_time', 'label' => 'New Order (Created At)'],
            ['key' => 'order_accepted_at', 'label' => 'Order Accepted'],
            ['key' => 'order_accepted_at_time', 'label' => 'Order Accepted Time'],
            ['key' => 'start_ride_at', 'label' => 'Start Ride'],
            ['key' => 'start_ride_at_time', 'label' => 'Start Ride Time'],
            ['key' => 'reached_shop_at', 'label' => 'Reached Shop'],
            ['key' => 'reached_shop_at_time', 'label' => 'Reached Shop Time'],
            ['key' => 'order_picked_at', 'label' => 'Order Picked'],
            ['key' => 'order_picked_at_time', 'label' => 'Order Picked Time'],
            ['key' => 'shipped_at', 'label' => 'Shipped'],
            ['key' => 'shipped_at_time', 'label' => 'Shipped Time'],
            ['key' => 'reached_dest_at', 'label' => 'Reached Destination'],
            ['key' => 'reached_dest_at_time', 'label' => 'Reached Destination Time'],
            ['key' => 'business_day', 'label' => 'Business Day'],
            ['key' => 'final_status_at', 'label' => 'Final Status'],
            ['key' => 'final_status_at_time', 'label' => 'Final Status Time'],
            ['key' => 'acceptance_time', 'label' => 'Acceptance Time'],
            ['key' => 'arrival_time','label'=> "Arrival Time"],
            ['key' => 'reached_time_taken', 'label' => 'Reached Time'],
            ['key' => 'picked_time_taken', 'label' => 'Picked Time'],
            ['key' => 'delivered_time_taken', 'label' => 'Pickup to Delivery Time'],
            ['key' => 'total_time_taken', 'label' => 'Process Time In Minutes'],
            ['key' => 'distance', 'label' => 'Distance B/W'],
            // ['key' => 'auto_assign_attempts', 'label' => 'Auto Assign Attempts'],
        ]
    ];

    public static function getFields(string $reportType): array 
    {
        return self::$reportFields[$reportType] ?? [];
    }

    public static function addReportFields(string $reportType, array $fields): void
    {
        self::$reportFields[$reportType] = $fields;
    }
}