<?php

namespace App\Graph;

use App\ClientShop;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClientMonthlyOperationalBranches implements Graph
{
    public function data()
    {

        // Define colors for the chart
        $colors = [
            '#36a2eb',
            '#ff6384',
            '#ff9f40',
            '#ffcd56',
            '#14b8a6',
            '#6b7280',
            '#1f6f70',
            '#b5d1af',
            '#5f4c60',
            '#97a7c4',
            '#F5921B',
            '#e1a692',
            '#54bebe',
            '#dedad2',
            '#badbdb',
            '#e8daff',
            '#ff8389',
            '#3ddbd9',
            '#20B2AA',
            '#ADD8E6',
            '#90EE90',
            '#FF6347',
        ];
        $labels = [];
        $activeBranches = [];
        $totalBranches = [];

        // Define date range
        $endDate = Carbon::parse(request('to_date', now()->format('Y-m-d')))->endOfMonth();
        $startDate = $endDate->copy()->subMonths(5)->startOfMonth();

        $client = request()->get('client');

        // Get count of newly active stores in the first month (August)
        $firstMonth = $startDate->format('Y-m');

        $newlyActiveShops = ClientShop::whereRaw('DATE_FORMAT(client_shops.created_at, "%Y-%m") = ?', [$firstMonth])
            ->IsActive()
            ->whereHas('orders')
            ->when($client, fn($query) => $query->where('client_shops.client_id', $client))
            ->count();

        // Get total active branches month by month
        $query = ClientShop::select([
            DB::raw('DATE_FORMAT(client_shops.created_at, "%Y-%m") as month'),
            DB::raw('COUNT(client_shops.id) as branches'),
        ])
            ->IsActive()
            ->whereHas('orders')
            ->whereBetween('client_shops.created_at', [$startDate, $endDate])
            ->when($client, fn($query) => $query->where('client_shops.client_id', $client))
            ->groupBy('month')
            ->orderBy('month')
            ->get()->keyBy('month');
        ;

        $currentTotal = 0;
        $monthPointer = $startDate->copy();

        while ($monthPointer->lte($endDate)) {
            $monthKey = $monthPointer->format('Y-m');
            $labels[] = $monthPointer->format('F Y');

            if (isset($query[$monthKey])) {
                $newlyAdded = $query[$monthKey]->branches;
                $currentTotal += $newlyAdded;
            } else {
                // Carry forward the last known total
                $newlyAdded = 0;
            }

            $activeBranches[] = $newlyAdded;
            $totalBranches[] = $currentTotal;

            $monthPointer->addMonth();
        }

        // Keep original logic unchanged
        foreach ($totalBranches as $index => $value) {
            if ($index > 0 && isset($activeBranches[$index])) {
                $totalBranches[$index] -= $activeBranches[$index];
            }
        }

        if (!empty($totalBranches)) {
            $totalBranches[0] -= $newlyActiveShops;
        }

        return [
            'colors' => $colors,
            'labels' => $labels,
            'new_added_branches' => $activeBranches,
            'total_branches' => $totalBranches,
        ];
    }
}
