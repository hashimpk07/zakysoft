<?php
namespace App\Services\PublicApi;

use App\AutoAssignPriority;
use App\Captain;
use App\Client;
use App\Interfaces\ListInterface;
use App\Interfaces\StreamLineInterface;
use App\Order;
use App\OrderLog;
use App\OrderStatus;
use App\Services\Client\DashboardService;
use App\Ticket;
use Illuminate\Http\Request;

final class OrderStreamLineService
{
    public function __construct(protected readonly ListInterface $listInterface, protected readonly StreamLineInterface $streamLineInterface)
    {
    }
    public function generateFilters(): array
    {
        $statistics = collect($this->statistics())
            ->map(
                fn($class) => [
                    'class' => $class,
                    'count' => 0,
                ],
            )
            ->toArray();

        $regions = $this->listInterface->getQuadrant(belongsToMe: true)->map(
            fn($q) => [
                'id'   => $q->id,
                'text' => $q->name,
            ],
        );

        $areas = $this->streamLineInterface->getAreas()->map(
            fn($q) => [
                'id'          => $q->id,
                'text'        => $q->name,
                'quadrant_id' => $q->quadrant_id,
            ],
        );

        $employment_types = $this->listInterface->getCaptainEmploymentType();
        $companies        = $this->listInterface->getThirdPartyCompanies()->map(
            fn($q) => [
                'id'   => $q->id,
                'text' => $q->name,
            ],
        );

        $captain_filters = compact('areas', 'regions', 'employment_types', 'companies');

        $clients = $this->streamLineInterface->getClients()->map(
            fn($client) => [
                'id'   => $client->id,
                'text' => $client->user?->name,
            ],
        );

        $shops = $this->streamLineInterface->getBaseClientShops()->map(
            fn($shop) => [
                'id'        => $shop->id,
                'text'      => $shop->name,
                'client_id' => $shop->client_id,
            ],
        );

        $filters = compact('regions', 'areas', 'clients', 'shops');

        return compact('statistics', 'captain_filters', 'filters');
    }

    protected function statistics(): array
    {
        return [
            'new_orders'            => 'sl-status-c1',
            'auto_assign_orders'    => 'sl-status-c2',
            'on_going_orders'       => 'sl-status-c3',
            'client_chat_orders'    => 'sl-status-c4',
            'ticket_orders'         => 'sl-status-c5',
            'pending_orders'        => 'sl-status-c6',
            'client_return_orders'  => 'sl-status-c7',
            'cancel_request_orders' => 'sl-status-c8',
        ];
    }

    public function streamLineOrders(Request $request)
    {
        $shops      = $this->streamLineInterface->getClientShops(request: $request);
        $ticketType = $this->resolveTicketType($request);
        $orders     = $this->streamLineInterface->getStreamLineOrders(request: $request, ticket_type: $ticketType);
        $statistics = $this->streamLineInterface->generateStreamLineStatistics();

        $statuses = $request->get('status', [OrderStatus::NEW_ORDER]);
        $statuses = is_array($statuses) ? $statuses : [$statuses];

        $statuses = $this->listInterface->getOrderStatus(statusIds: $statuses)->map(fn($status) => [['id' => $status->id, 'text' => $status->name]]);

        return [
            'orders'     => [ ...$this->OrderClassAndName(), 'data' => $orders],
            'shops'      => $shops,
            'statistics' => $statistics,
            'filters'    => [
                'statuses' => $statuses,
            ],
        ];
    }

    protected function resolveTicketType(Request $request)
    {
        if ($request->get('has_client_chat')) {
            return Ticket::TYPE_CLIENT;
        }

        return match ($request->get('status')) {
            OrderStatus::TICKET_RAISED => Ticket::TYPE_TICKET,
            OrderStatus::PENDING       => Ticket::TYPE_PENDING,
            default                    => null,
        };
    }

    protected function OrderClassAndName(): array
    {
        $status          = request()->get('status', OrderStatus::NEW_ORDER);
        $has_client_chat = request()->has('has_client_chat') && request()->get('has_client_chat');
        $scheduled       = request()->has('scheduled') && request()->get('scheduled');
        $status          = is_array($status) ? $status[count($status) - 1] : $status;
        $search          = request()->get('search');

        [$title, $class] = match (true) {
            ! empty($search)                                                                                                                                                                                                                       => ['Search Orders', 'sl-status-all'],
            $has_client_chat                                                                                                                                                                                                                      => ['Client Chat', 'sl-status-c4'],
            $scheduled                                                                                                                                                                                                                            => ['Scheduled Orders', 'sl-status-c9'],
            $status == OrderStatus::NEW_ORDER                                                                                                                                                                                                     => ['New Order', 'sl-status-c1'],
            in_array($status, [OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS])                                                                                                                                                         => ['Auto Assign', 'sl-status-c2'],
            in_array($status, [OrderStatus::ACCEPT, OrderStatus::START_RIDE, OrderStatus::REACHED_SHOP, OrderStatus::PICKED, OrderStatus::PICKED_UP, OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION, OrderStatus::WAITING_FOR_ACCEPTING]) => ['On Going Orders', 'sl-status-c3'],
            $status == OrderStatus::TICKET_RAISED                                                                                                                                                                                                 => ['Tickets', 'sl-status-c5'],
            $status == OrderStatus::PENDING                                                                                                                                                                                                       => ['Pending Orders', 'sl-status-c6'],
            $status == OrderStatus::RETURN_TO_CLIENT                                                                                                                                                                                              => ['Return To Client', 'sl-status-c7'],
            $status == OrderStatus::REQUEST_FOR_CANCEL                                                                                                                                                                                            => ['Request For Cancel', 'sl-status-c8'],
            default                                                                                                                                                                                                                               => ['Orders', 'sl-status-all'],
        };

        return [
            'title' => $title,
            'class' => $class,
        ];
    }

    public function getCaptains(Request $request)
    {
        $order = $this->streamLineInterface->findOrder(orderId: $request->get('order', false), closure: function ($q) {
            $q->with('shop.dispatchRuleForExpress')
                ->withCount('assignAttempts');
        });

        $captain_assignable = $this->streamLineInterface->isCaptainAssignable(order: $order);

        $total_current_orders   = 0;
        $total_delivered_orders = 0;

        $captains = $this->streamLineInterface->getCaptains(request: $request, order: $order)->map(function ($captain, int $key) use (&$total_current_orders, &$total_delivered_orders, $order) {
            $captain->append('online_state');
            $current_order = [];
            foreach ($captain->currentOrder ?? [] as $key => $order_current) {
                $eta = null;
                if ($order && $order_current->id == $order->id) {
                    $eta = $captain->eta;
                }
                $order_current->setAttribute('eta', $eta);
                $current_order[] = [
                    'id'              => $order_current->id,
                    'client_order_id' => $order_current->client_order_id,
                    'client'          => $order_current->client->user->name ?? 'N/A',
                    'shop'            => $order_current->shop->name,
                    'location'        => $order_current->location,
                    'status'          => $order_current->progress->name,
                    'eta'             => isset($order_current->eta) ? secondsToTime($order_current->eta) : (in_array($order_current->status_id, [OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION]) ? null : 'N/A'),
                ];
            }
            $icon = $captain->vehicle_type_id == 4 ? 'bike' : 'van';

            if (in_array('Busy', $captain->online_state)) {
                $icon = $icon . '-busy';
            }

            if (in_array('Offline', $captain->online_state)) {
                $icon = $icon . '-offline';
            }

            if (in_array('Idle', $captain->online_state) && ! in_array('Busy', $captain->online_state)) {
                $icon = $icon . '-idle';
            }
            $geometry = null;
            if ($captain->location) {
                $geometry = [
                    'type'        => 'Point',
                    'coordinates' => [(float) $captain->location->longitude ?? 0, (float) $captain->location->latitude ?? 0],
                ];
            }

            $total_current_orders   += $captain->current_order_count;
            $total_delivered_orders += $captain->delivered_orders_count;

            return [
                'type'       => 'Feature',
                'properties' => [
                    'id'                       => $captain->id,
                    'name'                     => $captain->name,
                    'priority'                 => AutoAssignPriority::getPriorityText($captain->auto_assign_priority_id),
                    'phone_number'             => $captain->phone_number,
                    'vehicle_type_id'          => $captain->vehicle_type_id,
                    'current_order_count'      => $captain->current_order_count,
                    'delivered_orders_count'   => $captain->delivered_orders_count,
                    'profile_pic_path'         => $captain->profile_pic_path,
                    'online_state'             => $captain->online_state,
                    'location'                 => $captain->location,
                    'current_order'            => $current_order,
                    'regions'                  => $captain->regions->pluck('name'),
                    'distance'                 => $captain->distance ?? null,
                    'employment_type'          => $captain->employmentType ? $captain->employmentType->name : 'N/A',
                    'third_party_company'      => $captain->company->name ?? '',
                    'icon'                     => $icon,
                    'current_shift_started_at' => $captain->currentShift ? $captain->currentShift->shift_start : null,
                    'total_seconds_worked'     => $captain->activeTodaySeconds(),
                    'eta'                      => $captain->eta ? secondsToTime($captain->eta) : ($order && in_array($order->status_id, [OrderStatus::ACCEPT, OrderStatus::START_RIDE, OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION]) ? null : -1),
                ],
                'geometry'   => $geometry,
            ];
        });

        $statistics = $this->generateCaptainStatistics(request: $request);

        return [
            'captains'           => [
                'type'     => 'FeatureCollection',
                'features' => $captains,
            ],
            'statistic'          => [ ...$statistics, 'total_completed_orders' => $total_delivered_orders, 'total_active_orders' => $total_current_orders],
            'captain_assignable' => $captain_assignable,
        ];
    }

    private function generateCaptainStatistics(Request $request)
    {
        $baseCaptainQuery = $this->streamLineInterface->getCaptainBaseQuery(request: $request);
        $statistics       = [
            'all'     => (clone $baseCaptainQuery)->where(fn($q) => $q->onlineFree()->orWhereHas('currentOrder'))->count(),

            'free'    => (clone $baseCaptainQuery)->onlineFree()->count(),

            'busy'    => (clone $baseCaptainQuery)->whereHas('currentOrder')->count(),

            'offline' => (clone $baseCaptainQuery)->offline()->count(),
        ];

        return $statistics;
    }

    public function findOrderWithDetails($orderId)
    {
        $order = $this->streamLineInterface->findOrderWithDetails($orderId);

        if (! $order) {
            return [];
        }

        $client = DashboardService::getClientFromUser(user: auth()->user());
        $logs   = $this->transformLogs(order: $order, client: $client);
        $notes  = $this->transformNotes(order: $order);

        return compact('order', 'logs', 'notes');
    }

    private function transformLogs(Order $order, ?Client $client): array
    {
        $logs            = [];
        $previousLog     = $order->logsExecpt->first();
        $newOrderLog     = $previousLog;
        $keyTimePrevious = null;
        $repeatedLogs    = [];

        foreach ($order->logsExecpt as $index => $log) {

            if (
                $client &&
                in_array($log->status_id, [
                    OrderStatus::ORDER_PACKAGE,
                    OrderStatus::ASSIGN_ATTEMPTS,
                ])
            ) {
                continue;
            }

            if ($index && $log->status_id !== $order->logsExecpt[$index - 1]->status_id) {
                $previousLog = $order->logsExecpt[$index - 1];
            }

            if (
                isset($order->logsExecpt[$index + 1]) &&
                $log->status_id === $order->logsExecpt[$index + 1]->status_id
            ) {
                $repeatedLogs[] = $log;
                continue;
            }

            if (
                ! $keyTimePrevious &&
                in_array($log->status_id, [
                    OrderStatus::NEW_ORDER,
                    OrderStatus::START_RIDE,
                    OrderStatus::REACHED_SHOP,
                    OrderStatus::PICKED,
                ])
            ) {
                $keyTimePrevious = $log;
            }

            $keyTime = null;

            if (
                $keyTimePrevious &&
                in_array($log->status_id, [
                    OrderStatus::ACCEPT,
                    OrderStatus::REACHED_SHOP,
                    OrderStatus::PICKED,
                    OrderStatus::DELIVERED,
                ])
            ) {
                $keyTime         = $log->created_at->diffInSeconds($keyTimePrevious->created_at);
                $keyTimePrevious = in_array(
                    $log->status_id,
                    [OrderStatus::REACHED_SHOP, OrderStatus::PICKED]
                ) ? $log : null;
            }

            $logs[] = [
                'id'               => $log->id,
                'status'           => $log->progress->name,
                'class'            => $log->progress->status_class,
                'cancelled_by'     => OrderLog::CANCELED_BY[$log->canceled_by] ?? '',
                'note'             => $log->note(),
                'created_by'       => $log->createdBy?->name ?? 'N/A',
                'created_date'     => $log->created_at?->format('Y-m-d'),
                'created_time'     => $log->created_at?->format('h:i:s A'),
                'time_bw_statuses' => secondsToTime(
                    $previousLog->created_at->diffInSeconds($log->created_at)
                ),
                'processing_time'  => secondsToTime(
                    $newOrderLog->created_at->diffInSeconds($log->created_at)
                ),
                'key_time'         => $keyTime ? secondsToTime($keyTime) : '',
                'repeated_logs'    => $repeatedLogs,
            ];

            $repeatedLogs = [];
        }

        return $logs;
    }

    private function transformNotes(Order $order): array
    {
        $notes = [];

        // Order-level note
        if (! empty($order->note)) {
            $notes[] = [
                'note'       => $order->note,
                'created_by' => '',
                'created_at' => '',
            ];
        }

        // Notes relation
        foreach ($order->notes as $note) {
            $employeeClient = $note->user?->employeeClient->first();

            $createdBy = match (true) {
                $note->user && $employeeClient =>
                $note->user->name . ' (' . $employeeClient->user?->name . ')',

                $note->user                    =>
                $note->user->name,

                default                        =>
                '(4u Logistics)',
            };

            $notes[] = [
                'note'       => $note->note,
                'created_by' => $createdBy,
                'created_at' => $note->created_at?->format('Y-m-d h:i:s A'),
            ];
        }

        return $notes;
    }

    public function getCaptainTasks(Captain $captain)
    {
        $stats = $this->streamLineInterface->getTodayCaptainStats(captainId: $captain->id);

        $captain->load([
            'user:id,name',
            'currentOrder',
        ]);

        return [
            'captain' => [
                'id'             => $captain->id,
                'code'           => $captain->code,
                'name'           => $captain->user->name,
                'current_orders' => $this->transformCurrentOrders($captain),
                'task_history'   => $stats,
            ],
        ];

    }

    private function transformCurrentOrders(Captain $captain): array
{
    return $captain->currentOrder->map(function ($order) {
        return [
            'id' => $order->id,
            'client_order_id' => $order->client_order_id,
            'shop' => $order->shop->name,
            'client' => $order->client->user->name,
            'status' => [
                'status' => $order->progress->name,
                'class' => $order->progress->status_class,
            ],
            'created_at' => $order->created_at->format('Y-m-d H:i:s'),
            'shop_to_delivery_km' => $order->shop_to_delivery_km,
            'location' => $order->location,
        ];
    })->values()->toArray();
}


}
