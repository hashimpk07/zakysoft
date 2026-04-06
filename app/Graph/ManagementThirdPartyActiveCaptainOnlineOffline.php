<?php
namespace App\Graph;

use App\Captain;

class ManagementThirdPartyActiveCaptainOnlineOffline implements Graph
{
    public function data()
    {
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

        $quadrant = request()->get('quadrant');
        $company = request()->get('company');

        $onlineCountQuery = Captain::online()
            ->excludeQuadrants()
            ->belongsToMe()
            ->active()
            ->when($company, function ($query, $company) {
                $query->whereHas('captainThirdParty', function ($query) use ($company) {
                    $query->where('third_party_logistic_company_id', '=', $company)->excludeCompanies();
                });
            })
            ->whereHas('regions.quadrant', function ($query) use ($quadrant) {
                if ($quadrant) {
                    $query->where('id', $quadrant); 
                }
            });

        $offlineCountQuery = Captain::offline()
            ->excludeQuadrants()
            ->belongsToMe()
            ->active()
            ->when($company, function ($query, $company) {
                $query->whereHas('captainThirdParty', function ($query) use ($company) {
                    $query->where('third_party_logistic_company_id', '=', $company)->excludeCompanies();
                });
            })
            ->whereHas('regions.quadrant', function ($query) use ($quadrant) {
                if ($quadrant) {
                    $query->where('id', $quadrant); 
                }
            });

        $onlineCount  = $onlineCountQuery->count();
        $offlineCount = $offlineCountQuery->count();

        return [
            'online'  => $onlineCount,
            'offline' => $offlineCount,
            'colors'  => $colors,
        ];

    }
}
