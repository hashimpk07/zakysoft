<?php

namespace App\Jobs;

use App\Captain;
use App\CaptainWorkingLog;
use App\Exports\QueueExport;
use App\PotentialClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PotentialClientExport extends QueueExport
{
    protected int $chunk = 2000;

    protected string $file_name = 'potential-client-report';

    public $timeout = 100000;

    public function data(): array
    {
        return  $this->getData();
    }

    public function headers(): array
    {
        return [
            'Client Name',
            'Coordinates',
            'Tire Name',	
            'POC Name',
            'POC Position',
            'POC Mobile',
            'POC Land Line',
            'Email',
            'Website',
            'Industry Type',
            'Expected Order Range/Day',
            'Status',
        ];
    }

    public function getData()
    {
        $request = $this->export->filters;
        $q = isset($request['q']) ? $request['q'] : null;
        $fence = isset($request['fence']) ? $request['fence'] : [];
        $industry = isset($request['industry']) ? $request['industry'] : null;
        $order_volume = isset($request['order_volume']) ? $request['order_volume'] : null;
        $batch_id = isset($request['batch_id']) ? $request['batch_id'] : null;
        

        $potential_clients = PotentialClient::query()
            ->withIndustry()
            ->withTier()
            ->when($q, function($query, $term) {
                $query->whereLike(['client_name', 'poc_name', 'poc_position', 'poc_mobile', 'poc_landline'], $term);
            })
            ->when(!empty($fence), function($query) use ($request) {
            $fence = $request->get('fence', []);
            $query->whereExists(function ($query) use ($fence) {

                $fence = is_array($fence) ? $fence : [$fence];

                $query->select(DB::raw(1))
                    ->from('geofences')
                    ->whereRaw("ST_Contains(
                    ST_GeomFromText(geofences.area),
                    ST_GeomFromText(CONCAT('POINT(', REPLACE(potential_clients.location, ',', ' '), ')'))
                    )")
                    ->whereIn('geofences.id', $fence);
            });
            })
            ->when($industry, function($query, $industry) {
                $query->whereHas('industry', function($query) use ($industry) {
                    $query->where('industries.id', $industry);
                });
            })
            ->when($order_volume, function($query, $order_volume) {
                $query->where('order_volume', $order_volume);
            })
            ->when($batch_id, function($query, $batch_id) {
                $query->where('batch_id', $batch_id);
            })
            ->latest()
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();

        return $potential_clients->map(function ($client) {
            return [
                "client_name" => $client->client_name,
                "coordinates" => $client->location,
                "tier" => $client->tier,
                "poc_name" => $client->poc_name,
                "poc_position" => $client->poc_position,
                "poc_mobile" => $client->poc_mobile,
                "poc_land_line" => $client->poc_landline,
                "email" => $client->email,
                "website" => $client->website,
                "industry_type" => $client->industry_type,
                "order_volume" => $client->order_volume,
                "status" => $client->status == 1 ? 'Active' : 'Inactive',
            ];
        })->toArray();
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->startOfMonth()->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();


        $q = isset($request['q']) ? $request['q'] : null;

        $captain = isset($request['captain']) ? $request['captain'] : null;

        $status = isset($request['status']) ? $request['status'] : null;

        $region = isset($request['region']) ? $request['region'] : null;

        $employment_type = isset($request['employment_type']) ? $request['employment_type'] : null;

        $quadrants = isset($request['quadrants']) ? $request['quadrants'] : null;

        $companies = isset($request['companies']) ? $request['companies'] : null;

        return CaptainWorkingLog::query()
            ->with(
                'captain:captains.id,code,iqama_number,captain_employment_type_id,user_id,sponsor_name',
                'captain.partner:id,first_name',
                'captain.user:id,name',
                'captain.employmentType',
                'captain.company:third_party_logistic_companies.name',
                'captain.regions.quadrant:id,name',
            )
            ->select('captain_id')
            ->addSelect([
                DB::raw('SUM(captain_working_logs.seconds_worked) as total_seconds_worked'),
                DB::raw('SUM(orders_received) as total_orders_received'),
                DB::raw('SUM(orders_accepted) as total_orders_accepted'),
                DB::raw('SUM(orders_delivered) as total_orders_delivered'),
                DB::raw('SUM(orders_returned) as total_orders_returned'),
                DB::raw('SUM(orders_cancelled) as total_orders_cancelled'),
                DB::raw('SUM(orders_expired) as total_orders_expired'),
                DB::raw('SUM(orders_reassigned) as total_orders_reassigned'),
                DB::raw('SUM(orders_rejected) as total_orders_rejected'),
                DB::raw('SUM(orders_try_to_accept) as orders_try_to_accept'),
                DB::raw('SUM(orders_expired) as no_of_no_response_requests'),
                DB::raw('COUNT(DISTINCT date) as working_days'),
                DB::raw('SUM(CASE WHEN orders_delivered > 0 THEN 1 ELSE 0 END) as productive_days'),

                DB::raw('(SELECT AVG(TIMESTAMPDIFF(SECOND, order_created_at, reached_shop_at)) 
                FROM order_reports 
                WHERE order_reports.captain_id = captain_working_logs.captain_id 
                AND DATE(order_reports.final_status_at) BETWEEN "' . $fromDate . '" AND "' . $toDate . '"
                     ) as average_arrival_time'),

                DB::raw('(SELECT AVG(TIMESTAMPDIFF(SECOND, order_picked_at, final_status_at)) 
                    FROM order_reports 
                    WHERE order_reports.captain_id = captain_working_logs.captain_id 
                    AND DATE(order_reports.final_status_at) BETWEEN "' . $fromDate . '" AND "' . $toDate . '"
                ) as average_delivery_time'),

                DB::raw('(SELECT AVG(shop_to_delivery_km) 
                FROM order_reports 
                WHERE order_reports.captain_id = captain_working_logs.captain_id 
                AND DATE(order_reports.final_status_at) BETWEEN "' . $fromDate . '" AND "' . $toDate . '"
            ) as average_delivery_distance'),

                DB::raw('(
                    SUM(captain_working_logs.seconds_worked) - 
                    (SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, order_created_at, final_status_at)), 0) 
                        FROM order_reports 
                        WHERE order_reports.captain_id = captain_working_logs.captain_id 
                        AND DATE(order_reports.final_status_at) BETWEEN "' . $fromDate . '" AND "' . $toDate . '"
                    )
                ) AS idle_hours')
            ])

            ->when($q, function ($query, $q) {
                return $query->whereLike([
                    'captain.user.name',
                    'captain.code',
                    'captain.user.email',
                    'captain.iqama_number',
                ], $q);
            })
            ->when($captain, function ($query, $captain) {
                return $query->whereIn('captain_id', $captain);
            })
            ->when($status, function ($query, $status) {
                return $query->whereHas('captain', function ($query) use ($status) {
                    $query->where('status', $status);
                });
            })
            ->when($quadrants, function ($query, $quadrants) {
                return $query->whereHas('captain.regions.quadrant', function ($query) use ($quadrants) {
                    $query->whereIn('quadrants.id', $quadrants);
                });
            })
            ->when($region, function ($query, $region) {
                return $query->whereHas('captain.regions', function ($query) use ($region) {
                    $query->whereIn('regions.id', $region);
                });
            })
            ->when($employment_type, function ($query, $employment_type) {
                return $query->whereHas('captain', function ($query) use ($employment_type) {
                    $query->where('captain_employment_type_id', $employment_type);
                });
            })
            ->whereHas('captain', function ($query) {
                $query->whereIn('status', [Captain::STATUS_ACTIVE, Captain::STATUS_BANNED, Captain::STATUS_INACTIVE]);
            })
            ->when($companies, function ($query, $companies) {
                $query->whereHas('captain.company', function ($query) use ($companies) {
                    $query->whereIn('third_party_logistic_companies.id', $companies);
                });
            })
            ->whereBetween('date', [$fromDate, $toDate])
            ->groupBy('captain_id')
            ->orderBy('captain_id')
            ->count();



    }

}
