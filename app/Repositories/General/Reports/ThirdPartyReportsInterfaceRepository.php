<?php

namespace App\Repositories\General\Reports;

use App\Interfaces\General\Reports\ThirdPartyReportsInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Captain;
use App\OrderStatus;
use App\Order;
use App\ThirdPartyLogisticCompany;
use App\ThirdPartyCommission;
use App\ThirdPartyCommissionPayment;


class ThirdPartyReportsInterfaceRepository implements ThirdPartyReportsInterface
{
    public function getAllCaptainCommissionReport(array $filters, int $perPage)
    {
       return Captain::query()
            ->select([
                'captains.id',
                'captains.code',
                'captains.iqama_number',
                'captains.status',
                'captains.date_of_joining',
                'captains.user_id', 
                'captains.nationality_id',
            ])

            ->with([
                'user:id,name',
                'nationality:id,name',
                'regions:id,name,quadrant_id',
                'regions.quadrant:id,name',
                'captainThirdParty.thirdPartCompany:id,name'
            ])

            ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) as max_commissions'),
                'captains.id',
                '=',
                'max_commissions.captain_id'
            )

            ->leftJoin('captain_commissions', 'max_commissions.max_id', '=', 'captain_commissions.id')

            ->has('captainThirdParty')

            ->withCount([
                'orders as attended_orders' => function($q){
                    $q->has('captainCommission')
                      ->whereIn('orders.status_id', [
                          OrderStatus::DELIVERED,
                          OrderStatus::CLIENT_RETURN_ACCEPTED
                      ]);
                }
            ])

            ->withAvg(['commissions as avg_commission'], 'commission')
            ->withSum(['commissions as total_commission'], 'commission')
            ->withSum(['commissions as paid_commission'], 'settled_amount')

            ->has('orders.captainCommission')

            ->when($filters['employee_id'] ?? null, fn($q,$v)=> $q->where('captains.code','like',"$v%"))

            ->when($filters['third_party_company_id'] ?? null, function($q,$v){
                $q->whereHas('captainThirdParty', function($q) use ($v){
                    $q->where('third_party_logistic_company_id',$v);
                });
            })

            ->when($filters['captain'] ?? null,
                fn($q,$v)=>$q->where('captains.id',$v))

            ->when($filters['name'] ?? null,
                fn($q,$v)=>$q->whereHas('user',fn($q)=>$q->where('name','like',"$v%")))

            ->when($filters['iqama'] ?? null,
                fn($q,$v)=>$q->where('captains.iqama_number','like',"$v%"))

            ->when($filters['region'] ?? null, function ($query, $region) {
                $query->whereHas('regions.quadrant', function ($query) use ($region) {
                    $query->where('quadrants.id', $region);
                });
            })

            ->when($filters['area'] ?? null, function ($query, $area) {
                $query->whereHas('regions', function ($query) use ($area) {
                    $query->where('regions.id', $area);
                });
            })

            ->when($filters['job_type'] ?? null,
                fn($q,$v)=>$q->where('captains.captain_employment_type_id',$v))

            ->when($filters['nationality'] ?? null,
                fn($q,$v)=>$q->where('captains.nationality_id',$v))

            ->when($filters['on_duty_from'] ?? null,
                fn($q,$v)=>$q->where('captains.date_of_joining','>=',now()->parse($v)->format('Y-m-d')))

            ->when($filters['work_status'] ?? null,
                fn($q,$v)=>$q->where('captains.status',$v))

            ->when($filters['payment_status'] ?? null, function($query, $payment_status) {
                if($payment_status == 'Payable') {
                    $query->where('captain_commissions.balance', '>', 0);
                }
    
                if($payment_status == 'Tally') {
                    $query->where('captain_commissions.balance', '=', 0);
                }
            })

            ->paginate($perPage);
    }

    public function getAllCaptainCommissionCountSummary(array $filters)
    {
        return Order::query()
            ->selectRaw('
                COUNT(*) as attended_orders,
                AVG(captain_commissions.commission) as total_avg_commission,
                SUM(captain_commissions.commission) as total_commission,
                SUM(captain_commissions.settled_amount) as total_payed_amount,
                SUM(cm.balance) as balance
            ')
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->leftJoin('captains', 'captains.id', '=', 'orders.captain_id')
            ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) as max_commissions'),
                'captains.id',
                '=',
                'max_commissions.captain_id'
            )
            ->leftJoin('captain_commissions as cm', 'max_commissions.max_id', '=', 'cm.id')

            ->has('captainCommission')
            ->has('captain.captainThirdParty')

            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED
            ])

            ->when($filters['captain'] ?? null,
                fn($q,$v)=>$q->where('orders.captain_id',$v))

            ->when($filters['employee_id'] ?? null, function($q, $v){
                $q->whereHas('captain', fn($q) => $q->where('code', 'LIKE', $v . '%'));
            })

            ->when($filters['third_party_company_id'] ?? null, function($q, $v){
                $q->whereHas('captain.captainThirdParty', fn($q) => $q->where('third_party_logistic_company_id', $v));
            })

            ->when($filters['name'] ?? null, function($q, $v){
                $q->whereHas('captain.user', fn($q) => $q->where('name', 'LIKE', $v . '%'));
            })

            ->when($filters['iqama'] ?? null, function($q, $v){
                $q->whereHas('captain', fn($q) => $q->where('iqama_number', 'LIKE', $v . '%'));
            })

            ->when($filters['region'] ?? null, function($query, $region) {
                $query->whereHas('captain.regions.quadrant', function($query) use ($region) {
                    $query->where('quadrants.id', $region);
                });
            })

            ->when($filters['area'] ?? null, function($query, $area) {
                $query->whereHas('captain.regions', function($query) use ($area) {
                    $query->where('regions.id', $area);
                });
            })

            ->when($filters['job_type'] ?? null, function($query, $job_type) {
                $query->whereHas('captain', function($query) use ($job_type) {
                    $query->where('captain_employment_type_id', $job_type);
                });
            })

            ->when($filters['nationality'] ?? null, function($query, $nationality) {
                $query->whereHas('captain', function($query) use ($nationality) {
                    $query->where('nationality_id', $nationality);
                });
            })

            ->when($filters['on_duty_from'] ?? null, function($query, $on_duty_from) {
                $query->whereHas('captain', function($query) use ($on_duty_from) {
                    $query->where('date_of_joining','>=', now()->parse($on_duty_from)->format('Y-m-d'));
                });
            })

            ->when($filters['work_status'] ?? null, function($query, $work_status) {
                $query->whereHas('captain', function($query) use ($work_status) {
                    $query->where('status','=', $work_status);
                });
            })

            ->when($filters['payment_status'] ?? null, function($query, $payment_status) {
                if($payment_status == 'Payable') {
                    $query->where('cm.balance', '>', 0);
                }
    
                if($payment_status == 'Tally') {
                    $query->where('cm.balance', '=', 0);
                }
            })

            ->first();
    }

    public function getAllCaptainCommissionTotalBalance(array $filters)
    {
        return Captain::query()
            ->has('captainThirdParty')
            ->has('orders.captainCommission')
            ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) as max_commissions'),
                'captains.id',
                '=',
                'max_commissions.captain_id'
            )
            ->leftJoin('captain_commissions', 'max_commissions.max_id', '=', 'captain_commissions.id')
            ->selectRaw('SUM(IFNULL(captain_commissions.balance, 0)) as total_payable_commission')
            
            ->when($filters['employee_id'] ?? null, function($query, $emp_id) {
                $query->whereLike('code', $emp_id);
            })
            
            ->when($filters['captain'] ?? null, function($query, $captain_id) {
                $query->where('captains.id', $captain_id);
            })

            ->when($filters['name'] ?? null, function($query, $name) {
                $query->whereLike(['user.name'], $name);
            })

            ->when($filters['iqama'] ?? null, function($query, $iqama) {
                $query->where('captains.iqama_number', 'LIKE', $iqama . "%");
            })

            ->when($filters['region'] ?? null, function($query, $region) {
                $query->whereHas('regions.quadrant', function($query) use ($region) {
                    $query->where('quadrants.id', $region);
                });
            })

            ->when($filters['area'] ?? null, function($query, $area) {
                $query->whereHas('regions', function($query) use ($area) {
                    $query->where('regions.id', $area);
                });
            })

            ->when($filters['job_type'] ?? null, function($query, $job_type) {
                $query->where('captains.captain_employment_type_id', $job_type);
            })

            ->when($filters['nationality'] ?? null, function($query, $nationality) {
                $query->where('captains.nationality_id', $nationality);
            })

            ->when($filters['on_duty_from'] ?? null, function($query, $on_duty_from) {
                $query->where('captains.date_of_joining','>=', now()->parse($on_duty_from)->format('Y-m-d'));
            })

            ->when($filters['work_status'] ?? null, function($query, $work_status) {
                $query->where('captains.status','=', $work_status);
            })

            ->when($filters['payment_status'] ?? null, function($query, $payment_status) {
                if($payment_status == 'Payable') {
                    $query->where('captain_commissions.balance', '>', 0);
                }
    
                if($payment_status == 'Tally') {
                    $query->where('captain_commissions.balance', '=', 0);
                }
            })
            ->first();
    }

    public function getSpecificCaptainCommissionStatistics(int $captainId, array $filters)
    {
        return Order::query()
            ->selectRaw('
                count(*) as attended_orders,
                avg(captain_commissions.commission) as avg_commission,
                sum(captain_commissions.commission) as total_commission,
                sum(captain_commissions.settled_amount) as paid_commission
            ')
            ->leftJoin('captain_commissions','captain_commissions.order_id','orders.id')
            ->where('orders.captain_id',$captainId)
            ->whereIn('orders.status_id',[
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED
            ])
            ->has('captainCommission')
            ->when($filters['from_date'] ?? null,
                fn($q,$v)=>$q->where('orders.delivery_date','>=',now()->parse($v)->format('Y-m-d'). ' 00:00:00')
            )
            ->when($filters['to_date'] ?? null,
                fn($q,$v)=>$q->where('orders.delivery_date','<=',now()->parse($v)->format('Y-m-d'). ' 23:59:59')
            )
            ->when($filters['region'] ?? null, function($query, $region) {
                $query->where(function($query) use ($region) {
                    $query->where('region_id', $region);
                    $query->orWhereHas('shop.region', function($query) use ($region) {
                        $query->where('id', $region);
                    });
                });
            })
            ->when($filters['q'] ?? null, function($query, $q) {
                $query->where('orders.client_order_id', 'LIKE', $q .'%');
            })
            ->when($filters['status'] ?? null, function($query, $status) {
                $query->where('orders.status_id', $status);
            })
            ->when($filters['client'] ?? null, function($query, $client) {
                $query->where('orders.client_id', $client);
            })
            ->when($filters['shop'] ?? null, function($query, $shop) {
                $query->where('orders.shopname', $shop);
            })
            ->first();
    }

    public function getSpecificCaptainCommissionOrders(int $captainId, array $filters, int $perPage)
    {
        return Order::query()

            ->select([
                'orders.id',
                'orders.delivery_date',
                'orders.client_order_id',
                'orders.client_id',
                'orders.shopname',
                'orders.status_id',
                'orders.captain_id',
                'orders.shop_to_delivery_km'
            ])

            ->with([
                'captain.user:id,name',
                'client.user:id,name',
                'shop:id,name',
                'progress:id,name',
                'captainCommission:id,order_id,commission,settled_amount,basic_delivery_earnings,additional_km_earning,updated_at,settled_by'
            ])

            ->leftJoin('captain_commissions','captain_commissions.order_id','orders.id')

            ->where('orders.captain_id',$captainId)

            ->whereIn('orders.status_id',[
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED
            ])

            ->has('captainCommission')

            ->orderByDesc('captain_commissions.id')

            ->when($filters['from_date'] ?? null,
                fn($q,$v)=>$q->where('orders.delivery_date','>=',now()->parse($v)->format('Y-m-d'). ' 00:00:00'))

            ->when($filters['to_date'] ?? null,
                fn($q,$v)=>$q->where('orders.delivery_date','<=',now()->parse($v)->format('Y-m-d'). ' 23:59:59'))

            ->when($filters['region'] ?? null, function($query, $region) {
                $query->where(function($query) use ($region) {
                    $query->where('region_id', $region);
                    $query->orWhereHas('shop.region', function($query) use ($region) {
                        $query->where('id', $region);
                    });
                });
            })
            ->when($filters['q'] ?? null, function($query, $q) {
                $query->where('orders.client_order_id', 'LIKE', $q .'%');
            })
            ->when($filters['status'] ?? null, function($query, $status) {
                $query->where('orders.status_id', $status);
            })
            ->when($filters['client'] ?? null, function($query, $client) {
                $query->where('orders.client_id', $client);
            })
            ->when($filters['shop'] ?? null, function($query, $shop) {
                $query->where('orders.shopname', $shop);
            })

            ->paginate($perPage);
    }

    public function getThirdPartyCommissionReport(array $filters, int $perPage)
    {
        $commissionsSummary = DB::raw('(SELECT third_party_company_id,
                SUM(total_earned_commission) AS total_earnings,
                COUNT(*) AS attended_orders,
                SUM(settled_amount) AS paid_commission
            FROM third_party_commissions
            GROUP BY third_party_company_id) AS commissions_summary');

        return ThirdPartyLogisticCompany::query()
            ->select(
                'third_party_logistic_companies.id',
                'third_party_logistic_companies.name',
                'third_party_logistic_companies.cr_number',
                'third_party_logistic_companies.status',
                'commissions_summary.total_earnings',
                'commissions_summary.attended_orders',
                'commissions_summary.paid_commission',
                'third_party_commissions.balance as commission_balance'
            )
            ->with('regions:id,name')
            ->leftJoin('captains_third_party_logistic','third_party_logistic_companies.id','=','captains_third_party_logistic.third_party_logistic_company_id')
            ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, third_party_company_id FROM third_party_commissions GROUP BY third_party_company_id) as max_commissions'),
                'third_party_logistic_companies.id','=','max_commissions.third_party_company_id'
            )
            ->leftJoin('third_party_commissions','max_commissions.max_id','=','third_party_commissions.id')
            ->leftJoin($commissionsSummary,'third_party_logistic_companies.id','=','commissions_summary.third_party_company_id')

            ->when($filters['company_id'] ?? null,
                fn($q,$id)=>$q->where('third_party_logistic_companies.id',$id))

            ->when($filters['cr_number'] ?? null,
                fn($q,$cr)=>$q->where('third_party_logistic_companies.cr_number',$cr))

            ->when($filters['region'] ?? null,function($q,$region){
                $q->whereHas('regions',fn($r)=>$r->where('quadrants.id',$region));
            })

            ->when($filters['payment_status'] ?? null,function($q,$status){

                if($status==='Payable'){
                    $q->where('third_party_commissions.balance','>',0);
                }

                if($status==='Tally'){
                    $q->where('third_party_commissions.balance','=',0);
                }

            })
            ->groupBy('third_party_logistic_companies.id')
            ->paginate($perPage);

    }

    public function getThirdPartyCommissionCount($filters)
    {
        return ThirdPartyCommission::select(
            DB::raw('COUNT(third_party_commissions.order_id) as attended_orders'),
            DB::raw('AVG(third_party_commissions.total_earned_commission) as total_avg_commission'),
            DB::raw('SUM(third_party_commissions.total_earned_commission) as total_commission'),
            DB::raw('SUM(third_party_commissions.settled_amount) as total_payed_amount'),
        )
        ->leftJoin(
            DB::raw('(SELECT MAX(id) AS max_id, third_party_company_id FROM third_party_commissions GROUP BY third_party_company_id) as max_commissions'),
            'third_party_commissions.id',
            '=',
            'max_commissions.third_party_company_id'
        )
        ->leftJoin('third_party_commissions as tpc', 'max_commissions.max_id', '=', 'tpc.id')
        ->when($filters['company_id'] ?? null, fn($q,$v)=>$q->where('third_party_commissions.third_party_company_id',$v))
        ->when($filters['cr_number'] ?? null, fn($q,$v)=>$q->where('third_party_commissions.cr_number',$v))
        ->when($filters['payment_status'] ?? null, function ($query, $status) {
            if ($status === 'Payable') {
                $query->where('third_party_commissions.balance', '>', 0);
            }

            if ($status === 'Tally') {
                $query->where('third_party_commissions.balance', '=', 0);
            }

        })->first();
    }

    public function getThirdPartyCommissionBalance($filters)
    {
        return ThirdPartyLogisticCompany::query()
            ->has('lastCommission')
            ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, third_party_company_id FROM third_party_commissions GROUP BY third_party_company_id) as max_commissions'),
                'third_party_logistic_companies.id',
                '=',
                'max_commissions.third_party_company_id'
            )
            ->leftJoin('third_party_commissions', 'max_commissions.max_id', '=', 'third_party_commissions.id')
            ->selectRaw('SUM(IFNULL(third_party_commissions.balance, 0)) as total_payable_commission')
            ->when($filters['company_id'] ?? null, fn($q,$v)=>$q->where('third_party_commissions.third_party_company_id',$v))
            ->first();
    }

    public function getSpecificThirdPartyCompanyCount($companyId, $filters)
    {

        return Order::query()
            ->selectRaw('
                COUNT(*) as attended_orders,
                AVG(third_party_commissions.total_earned_commission) as avg_commission,
                SUM(third_party_commissions.total_earned_commission) as total_commission,
                SUM(third_party_commissions.settled_amount) as paid_commission
            ')
            ->addSelect([
                'payable_commission' => ThirdPartyCommission::select('balance')
                    ->where('third_party_company_id', $companyId)
                    ->latest('id')
                    ->limit(1)
            ])
            ->leftJoin('third_party_commissions', 'third_party_commissions.order_id', '=', 'orders.id')
            ->where('third_party_commissions.third_party_company_id', $companyId)
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED
            ])
            ->when($filters['from_date'] ?? null, function ($query, $from_date) {
                $query->where('orders.delivery_date', '>=', now()->parse($from_date)->format('Y-m-d') . ' 00:00:00');
            })
            ->when($filters['to_date'] ?? null, function ($query, $to_date) {
                $query->where('orders.delivery_date', '<=', now()->parse($to_date)->format('Y-m-d') . ' 23:59:59');
            })
            ->when($filters['q'] ?? null, function ($query, $q) {
                $query->where('orders.client_order_id', 'LIKE', $q . '%');
            })
            ->when($filters['status'] ?? null, function ($query, $status) {
                $query->where('orders.status_id', $status);
            })
            ->when($filters['client'] ?? null, function ($query, $client) {
                $query->where('orders.client_id', $client);
            })
            ->when($filters['shop'] ?? null, function ($query, $shop) {
                $query->where('orders.shopname', $shop);
            })
            ->first();
    }

    public function getSpecificThirdPartyCompanyOrders($companyId, $filters, $perPage)
    {
        return Order::select(
            'orders.id',
            'orders.client_id',
            'orders.captain_id',
            'orders.status_id',
            'orders.delivery_date',
            'orders.client_order_id',
            'orders.shopname',
            'orders.shop_to_delivery_km',

            'third_party_commissions.additional_km',
            'third_party_commissions.additional_km_earning',
            'third_party_commissions.basic_delivery_earnings',
            'third_party_commissions.total_earned_commission',
            'third_party_commissions.balance',
            'third_party_commissions.settled_amount'
        )
        ->with(['captain.user','client.user','shop','progress','thirdPartyCommission.commissionPaymentLatest','thirdPartyCommission.attachments','orderStatus',])
        ->leftJoin('third_party_commissions','third_party_commissions.order_id','=','orders.id')
        ->where('third_party_commissions.third_party_company_id',$companyId)
        ->orderByDesc('third_party_commissions.id')
        ->whereIn('orders.status_id', [
            OrderStatus::DELIVERED,
            OrderStatus::CLIENT_RETURN_ACCEPTED,
            OrderStatus::CANCEL_REQUEST_ACCEPTED
        ])
        ->when($filters['from_date'] ?? null, function ($query, $from_date) {
            $query->where('orders.delivery_date', '>=', now()->parse($from_date)->format('Y-m-d') . ' 00:00:00');
        })
        ->when($filters['to_date'] ?? null, function ($query, $to_date) {
            $query->where('orders.delivery_date', '<=', now()->parse($to_date)->format('Y-m-d') . ' 23:59:59');
        })
        ->when($filters['q'] ?? null, function ($query, $q) {
            $query->where('orders.client_order_id', 'LIKE', $q . '%');
        })
        ->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('orders.status_id', $status);
        })
        ->when($filters['client'] ?? null, function ($query, $client) {
            $query->where('orders.client_id', $client);
        })
        ->when($filters['captain_id'] ?? null, function ($query, $captain_id) {
            $query->where('orders.captain_id', $captain_id);
        })
        ->paginate($perPage);
    }

    public function getLatestThirdPartyCompanyCommission($companyId)
    {
        return ThirdPartyCommission::where(
            'third_party_company_id',
            $companyId
        )->latest('id')->first();
    }

    public function updateThirdPartyCompanyCommission($commission)
    {
        $commission->save();
        return $commission;
    }

    public function createThirdPartyCompanyCommissionPayment(array $data)
    {
        return ThirdPartyCommissionPayment::create($data);
    }

    public function createThirdPartyCompanyCommissionAttachments($commission, array $attachments)
    {
        return $commission->attachments()->createMany($attachments);
    }

}