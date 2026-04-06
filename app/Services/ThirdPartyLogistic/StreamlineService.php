<?php
namespace App\Services\ThirdPartyLogistic;

use App\Captain;
use App\Interfaces\ThirdPartyStreamlineInterface;
use App\Order;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StreamlineService
{
    public function __construct(private readonly ThirdPartyStreamlineInterface $interface)
    {}

    public function getOrders(Request $request)
    {
        $status = $this->getStatusIdsByFilterKey($request->get('status', 'on_going_orders'));
        return $this->interface->getOrders(request: $request, status: $status);   
    }

    public function getCaptains(Request $request)
    {
        $orderId = $request->get('order', false);

        $order = $this->interface->findOrderById($orderId);

        $captains = $this->interface->getCaptains(request: $request, order: $order)->map(function ($captain, int $key) use (&$total_current_orders, &$total_delivered_orders, $order) {
            $captain->append('online_state');

            $currentOrders = collect($captain->currentOrder ?? [])
                ->map(fn($o) => [
                    'id'              => $o->id,
                    'client_order_id' => $o->client_order_id,
                    'client'          => $o->client->user->name ?? 'N/A',
                    'shop'            => $o->shop->name,
                ])
                ->values();

            // Check if the request order id exists in current orders
            $orderExists = $currentOrders->contains(fn($order) => $order['id'] === $order);

            $icon = $this->resolveIcon($captain);

            $geometry = $captain->location
                ? [
                'type'        => 'Point',
                'coordinates' => [
                    (float) $captain->location->longitude,
                    (float) $captain->location->latitude,
                ],
            ]
                : null;

            return [
                'id'                       => $captain->id,
                'name'                     => $captain->name,
                'phone_number'             => $captain->phone_number,
                'vehicle_type_id'          => $captain->vehicle_type_id,
                'current_order_count'      => $captain->current_order_count,
                'delivered_orders_count'   => $captain->delivered_orders_count,
                'profile_pic_path'         => $captain->profile_pic_path,
                'online_state'             => $captain->online_state[0] ?? 'Offline',
                'current_order'            => $currentOrders,
                'regions'                  => $captain->regions->pluck('name'),
                'employment_type'          => $captain->employmentType?->name ?? 'N/A',
                'third_party_company'      => $captain->company->name ?? '',
                'icon'                     => $icon,
                'current_shift_started_at' => $captain->currentShift?->shift_start,
                'eta'                      => $this->resolveEta($captain, $order),
                'geometry'                 => $geometry,
                'vehicle_type'             => $captain->vehicle_type_id == 4 ? 'bike' : 'van',
                'assigned'                 => $orderExists,
                'nationality'              => $captain->nationality?->name
            ];
        })->values();

        return compact('captains');
    }

    public function generateCaptainDetails(Captain $captain)
    {
        $stats = $this->interface->getCaptainStats($captain->id);

        $captain->load([
            'user',
            'currentOrder.shop',
            'currentOrder.client.user',
            'currentOrder.progress',
        ]);

        $captain = [
            'id'             => $captain->id,
            'code'           => $captain->code,
            'name'           => $captain->user->name,
            'current_orders' => $captain->currentOrder->map(function ($order) {
                return [
                    'id'                  => $order->id,
                    'client_order_id'     => $order->client_order_id,
                    'shop'                => $order->shop->name,
                    'client'              => $order->client->user->name,
                    'status'              => $order->progress->name,
                    'created_at'          => $order->created_at->format('Y-m-d H:i:s'),
                    'shop_to_delivery_km' => $order->shop_to_delivery_km,
                    'location'            => $order->location,
                ];
            }),
            'task_history'   => $stats,
        ];

        return compact('captain');
    }
    public function getMapFilters($company_id): array
    {
        return [
            [
                'count' => $this->interface->orderCountByStatus(statuses: $this->getStatusIdsByFilterKey('on_going_orders'), company_id: $company_id),
                'label' => 'On Going Orders',
                'key'   => 'on_going_orders',
            ],
            [
                'count' => $this->interface->orderCountByStatus(statuses: $this->getStatusIdsByFilterKey('pending_orders'), company_id: $company_id),
                'label' => 'Pending Orders',
                'key'   => 'pending_orders',
            ],
            [
                'count' => $this->interface->orderCountByStatus(statuses: $this->getStatusIdsByFilterKey('client_return_orders'), company_id: $company_id),
                'label' => 'Client Return Orders',
                'key'   => 'client_return_orders',
            ],
            [
                'class' => 'sl-status-c8',
                'count' => $this->interface->orderCountByStatus(statuses: $this->getStatusIdsByFilterKey('cancel_request_orders'), company_id: $company_id),
                'label' => 'Cancel Request Orders',
                'key'   => 'cancel_request_orders',
            ],
        ];
    }

    private function getStatusIdsByFilterKey(string $key)
    {
        return match ($key) {
            'on_going_orders'       => [ ...OrderStatus::ON_GOING_ORDER, OrderStatus::REROUTED, OrderStatus::TICKET_RAISED],
            'pending_orders'        => [OrderStatus::PENDING],
            'client_return_orders'  => [OrderStatus::RETURN_TO_CLIENT],
            'cancel_request_orders' => [OrderStatus::REQUEST_FOR_CANCEL],
            default                 => [ ...OrderStatus::NOT_ASSIGNED_ORDER],
        };
    }

    private function resolveIcon($captain): string
    {
        $icon = $captain->vehicle_type_id == 4 ? 'bike' : 'van';

        return match (true) {
            in_array('Busy', $captain->online_state) => "{$icon}-busy",
            in_array('Offline', $captain->online_state) => "{$icon}-offline",
            in_array('Idle', $captain->online_state) => "{$icon}-idle",
            default => $icon,
        };
    }

    private function resolveEta($captain, ?Order $order)
    {
        if ($captain->eta) {
            return secondsToTime($captain->eta);
        }

        if ($order && in_array($order->status_id, [
            OrderStatus::ACCEPT,
            OrderStatus::START_RIDE,
            OrderStatus::SHIPPED,
            OrderStatus::REACHED_DESTINATION,
        ])) {
            return null;
        }

        return -1;
    }
}
