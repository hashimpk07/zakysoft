<?php
namespace App\Jobs;

use App\Client;
use App\Exports\QueueExport;
use App\OrderReport;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClientsStatisticsExportJob extends QueueExport
{
    protected int $chunk = 1000;

    protected string $file_name = 'client statistics';

    /**
     * Execute the job.
     */

    public function data(): array
    {
        $data          = [];
        $clients_datas = $this->getReport();

        foreach ($clients_datas as $client_data) {
            if ($client_data->total_orders > 0) {
                $successRate = number_format(($client_data->delivered / $client_data->total_orders) * 100, 2) . '%';
            } else {
                $successRate = 'N/A';
            }

            $data[] = [
                $client_data->user->name,
                $client_data->total_orders,
                $client_data->new_orders,
                $client_data->on_going_orders,
                $client_data->unsuccessful_deliveries,
                $client_data->average_consumed_time ?? 'N/A',
                $client_data->delivered,
                $successRate,
            ];
        }

        return $data;
    }
    public function getReport()
    {
        $request = $this->export->filters;

         $fromDate = isset($request['from_date']) ? $request['from_date'] : now()->format('Y-m-d');
        $toDate = isset($request['to_date']) ? $request['to_date'] : now()->format('Y-m-d');

        //This is for filtering based on business day concept (6 AM to 5:59:59 AM next day)
        $fromDateTime = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate = $toDateTime->format('Y-m-d H:i:s');

        $clientId  = isset($request['client_id']) ? $request['client_id'] : '';

        return Client::query()
            ->excludeQuadrants()
            ->select('id', 'user_id')
            ->with('user:id,name')
            ->when($clientId, function ($query) use ($clientId) {
                $query->where('id', $clientId);
            })
            ->whereHas('order', function ($query) use ($startDate, $endDate) {
                $query->withinDateRange($startDate, $endDate, 'delivery_date');
            })
            ->addSelect(['average_consumed_time' => OrderReport::query()
                    ->select(DB::raw('
                DATE_FORMAT(
                    SEC_TO_TIME(AVG(TIMESTAMPDIFF(SECOND,
                        order_created_at,
                        IFNULL(final_status_at, NOW())
                    ))), "%H:%i:%s") as processing_time
                ')
                    )
                    ->whereIn('status_id', OrderStatus::FINISHED)
                    ->whereColumn('order_reports.client_id', 'clients.id')
                    ->withinDateRange($startDate, $endDate)
                    ->limit(1),
            ])
            ->withCount([
                'orderReports as total_orders'            => function ($query) use ($startDate, $endDate) {
                    $query->excludeQuadrants()->withinDateRange($startDate, $endDate);
                },
                'orderReports as new_orders'              => function ($query) use ($startDate, $endDate) {
                    $query->excludeQuadrants()->whereIn('status_id', [OrderStatus::NEW_ORDER, OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS])
                        ->withinDateRange($startDate, $endDate);
                },
                'orderReports as on_going_orders'         => function ($query) use ($startDate, $endDate) {
                    $query->excludeQuadrants()->whereIn('status_id', [OrderStatus::ACCEPT, OrderStatus::START_RIDE, OrderStatus::REACHED_SHOP, OrderStatus::PICKED, OrderStatus::PICKED_UP, OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION, OrderStatus::INCOMPLETE, OrderStatus::PENDING, OrderStatus::REFUSE, OrderStatus::TICKET_RAISED, OrderStatus::REROUTED, OrderStatus::CLIENT_RETURN_DECLINE])
                        ->withinDateRange($startDate, $endDate, );
                },
                'orderReports as delivered'               => function ($query) use ($startDate, $endDate) {
                    $query->excludeQuadrants()->where('status_id', OrderStatus::DELIVERED)
                        ->withinDateRange($startDate, $endDate);
                },
                'orderReports as unsuccessful_deliveries' => function ($query) use ($startDate, $endDate) {
                    $query->excludeQuadrants()->whereIn('status_id', [OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED])
                        ->withinDateRange($startDate, $endDate, );
                },
            ])
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();
    }

    public function headers(): array
    {
        return [
            'Client Name',
            "Total Orders",
            "New Orders",
            "On-going Orders",
            "Un successful Deliveries",
            'Average Processing Time',
            "Delivered",
            "Success Rate",
        ];
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $fromDate = isset($request['from_date']) ? $request['from_date'] : now()->format('Y-m-d');
        $toDate = isset($request['to_date']) ? $request['to_date'] : now()->format('Y-m-d');

        //This is for filtering based on business day concept (6 AM to 5:59:59 AM next day)
        $fromDateTime = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate = $toDateTime->format('Y-m-d H:i:s');
        
        $clientId  = isset($request['client_id']) ? $request['client_id'] : '';

        return Client::query()
            ->excludeQuadrants()
            ->select('id', 'user_id')
            ->with('user:id,name')
            ->when($clientId, function ($query) use ($clientId) {
                $query->where('id', $clientId);
            })
            ->whereHas('order', function ($query) use ($startDate, $endDate) {
                $query->withinDateRange($startDate, $endDate, 'delivery_date');
            })
            ->withCount([
                'orderReports as total_orders'            => function ($query) use ($startDate, $endDate) {
                    $query->excludeQuadrants()->withinDateRange($startDate, $endDate);
                },
                'orderReports as new_orders'              => function ($query) use ($startDate, $endDate) {
                    $query->excludeQuadrants()->whereIn('status_id', [OrderStatus::NEW_ORDER, OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS])
                        ->withinDateRange($startDate, $endDate);
                },
                'orderReports as on_going_orders'         => function ($query) use ($startDate, $endDate) {
                    $query->excludeQuadrants()->whereIn('status_id', [OrderStatus::ACCEPT, OrderStatus::START_RIDE, OrderStatus::REACHED_SHOP, OrderStatus::PICKED, OrderStatus::PICKED_UP, OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION, OrderStatus::INCOMPLETE, OrderStatus::PENDING, OrderStatus::REFUSE, OrderStatus::TICKET_RAISED, OrderStatus::REROUTED, OrderStatus::CLIENT_RETURN_DECLINE])
                        ->withinDateRange($startDate, $endDate, );
                },
                'orderReports as delivered'               => function ($query) use ($startDate, $endDate) {
                    $query->excludeQuadrants()->where('status_id', OrderStatus::DELIVERED)
                        ->withinDateRange($startDate, $endDate);
                },
                'orderReports as unsuccessful_deliveries' => function ($query) use ($startDate, $endDate) {
                    $query->excludeQuadrants()->whereIn('status_id', [OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED])
                        ->withinDateRange($startDate, $endDate, );
                },
            ])
            ->count();
    }

}
