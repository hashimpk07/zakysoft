<?php

use Carbon\Carbon;

if (!function_exists('set_flash_message')) {
    function set_flash_message($type, $message)
    {
        if ($type == 'success') {
            $icon = 'fa fa-check';
        } elseif ($type == 'warning') {
            $icon = 'fa fa-times';
        } else {
            $icon = 'fa fa-exclamation-triangle';
        }
        \Session::flash('alert_type', $type);
        \Session::flash('alert_icon', $icon);
        \Session::flash('alert_info', $message);
    }
}

if (!function_exists('formatdate')) {
    function formatdate($date)
    {
        if (empty($date)) {
            return null;
        }
        
        // Try m/d/Y format (often used in web forms)
        $d = DateTime::createFromFormat('m/d/Y', $date);
        if ($d && $d->format('m/d/Y') === $date) {
            return $d->format('Y-m-d');
        }

        // Try Y-m-d format (often used in APIs)
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if ($d && $d->format('Y-m-d') === $date) {
            return $date;
        }

        return null;
    }
}

if (!function_exists('secondsToTime')) {
    function secondsToTime($seconds)
    {
        if (!is_numeric($seconds)) {
            return '00:00:00';
        }
        $hours = floor($seconds / 3600);
        $mins = floor(($seconds / 60) % 60);
        $secs = floor($seconds % 60);

        return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
    }
}

if (!function_exists('polygonToCoordinates')) {
    function polygonToCoordinates($polygon)
    {
        if (empty($polygon) || !Illuminate\Support\Str::of($polygon)->startsWith('POLYGON')) {
            return [];
        }

        return Illuminate\Support\Str::of($polygon)
            ->trim()
            ->replace('POLYGON ((', '')
            ->replace('POLYGON((', '')
            ->replace('POLYGON( (', '')
            ->replace(') )', '')
            ->replace(' ))', '')
            ->replace('))', '')
            ->explode(',')
            ->map(function ($point) {
                return Illuminate\Support\Str::of($point)->trim()->explode(' ')->reverse()->values();
            })
            ->toArray();
    }
}

if (!function_exists('formattedDate')) {
    function formattedDate($date, $compareDate = null)
    {
        if (!$date) {
            return null;
        }

        if (!$date instanceof \Carbon\Carbon) {
            $date = \Carbon\Carbon::parse($date);
        }

        if ($compareDate && !$compareDate instanceof \Carbon\Carbon) {
            $compareDate = \Carbon\Carbon::parse($compareDate);
        }

        return $date->isSameDay($compareDate) ? $date->format('h:i:s A') : $date->format('d-m-Y h:i:s A');
    }
}

if (!function_exists('getSystemTimeRange')) {
    /**
     * Parse a date range safely and apply dashboard time rules.
     *
     * @param  string|null  $fromDate
     * @param  string|null  $toDate
     * @return array
     */
    function getSystemTimeRange(?string $fromDate, ?string $toDate): array
    {
        // Use today's date if parsing fails or date is empty
        $safeParse = function ($date) {
            try {
                return Carbon::parse($date);
            } catch (\Exception $e) {
                return Carbon::today();
            }
        };

        $start = $safeParse($fromDate)->setTime(6, 0, 0);

        // add day and set ending time
        $end = $safeParse($toDate)->addDay()->setTime(5, 59, 59);

        return [$start, $end];
    }
}

/**
 * Format a numeric value into Saudi Riyal (SAR) currency format.
 *
 * Safely accepts int, float, or numeric string values.
 * If a non-numeric value is provided, it defaults to 0.
 *
 * Examples:
 *  - moneyFormat(1500)      → "SAR 1,500.00"
 *  - moneyFormat("2500.5")  → "SAR 2,500.50"
 *  - moneyFormat("abc")     → "SAR 0.00"
 *
 * @param float|int|string $amount  The amount to be formatted
 * @return string                   Formatted SAR amount
 */
function moneyFormat(float|int|string $amount): string
{
    // Ensure the value is numeric; fallback to 0 for invalid input
    $amount = is_numeric($amount) ? (float) $amount : 0;

    // Format with 2 decimal places and thousand separators
    return 'SAR ' . number_format($amount, 2, '.', ',');
}

