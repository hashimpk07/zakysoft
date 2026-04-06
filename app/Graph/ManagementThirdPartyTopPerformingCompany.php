<?php
namespace App\Graph;

use App\ThirdPartyLogisticCompany;

class ManagementThirdPartyTopPerformingCompany implements Graph
{
    public function data()
    {
        $startDate = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : now()->startOfDay();
        $endDate   = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();

        $client  = request()->get('client');
        $company = request()->get('company');

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
        $values = [];

        $data = ThirdPartyLogisticCompany::with("captains")
            ->when($company, function ($query) use ($company) {
                $query->where('third_party_logistic_companies.id', $company);
            })
            ->excludeCompanies()
            ->limit(10)
            ->get()
            ->map(function ($company) {
                return [
                    'label' => $company->name, // Assuming 'name' is the company name field
                    'value' => $company->captains->count(),
                ];
            })
            ->filter(function ($company) {
                return $company['value'] > 0; // Exclude companies with 0 captains
            })
            ->sortByDesc('value'); // Sort by the 'value' (captains count) in descending order

        $labels = $data->pluck('label')->toArray();
        $values = $data->pluck('value')->toArray();

        return [
            'colors' => $colors,
            'labels' => $labels,
            'values' => $values,
        ];
    }
}
