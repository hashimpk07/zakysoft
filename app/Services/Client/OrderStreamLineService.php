<?php
namespace App\Services\Client;

use App\Interfaces\ClientStreamlineInterface;
use App\Order;
use App\OrderStatus;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class OrderStreamLineService
{

    public function __construct(protected readonly ClientStreamlineInterface $streamlineInterface)
    {}

    public function listOrders(Request $request, User $user)
    {
        $shopIds       = $this->streamlineInterface->getPermissibleShopIdsForUser($user);
        $hasClientChat = $request->status === 'client_chat_orders';

        $filters = [
            'status'          => $hasClientChat ? [] : $this->getStatusIdsByFilterKey($request->get('status', 'new_orders')),
            'search'          => $request->get('search'),
            'has_client_chat' => $hasClientChat,
        ];

        return $this->streamlineInterface->getOrders(filters: $filters, shopIds: $shopIds); 
    }

    public function listCaptains(Request $request, User $user): array
    {
        $shopIds = $this->streamlineInterface->getPermissibleShopIdsForUser($user);

        $filters = [
            'region'          => $request->get('region'),
            'area'            => $request->get('area'),
            'employment_type' => $request->get('employment_type'),
            'company'         => $request->get('company'),
            'captain'         => $request->get('captain'),
            'state'           => $request->get('show'),
            'search'          => $request->get('search'),
            'from_date'       => $request->get('from_date'),
            'to_date'         => $request->get('to_date'),
        ];

        $order = $request->get('order')
            ? Order::with('shop')->find($request->get('order'))
            : null;

        $totalCurrentOrders   = 0;
        $totalDeliveredOrders = 0;

        $captains = $this->streamlineInterface
            ->getCaptains($filters, $shopIds, $order)
            ->map(function ($captain) use (&$totalCurrentOrders, &$totalDeliveredOrders, $order) {

                $captain->append('online_state');

                $currentOrders = collect($captain->currentOrder ?? [])
                    ->map(fn($o) => [
                        'id'              => $o->id,
                        'client_order_id' => $o->client_order_id,
                        'client'          => $o->client->user->name ?? 'N/A',
                        'shop'            => $o->shop->name,
                    ])
                    ->values();

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

                $totalCurrentOrders   += $captain->current_order_count;
                $totalDeliveredOrders += $captain->delivered_orders_count;

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
                        'current_shift_started_at' => Carbon::parse($captain->currentShift?->shift_start)->diffForHumans(),
                        'eta'                      => $this->resolveEta($captain, $order),
                        'geometry'   => $geometry,
                        'vehicle_type' =>  $captain->vehicle_type_id == 4 ? 'bike' : 'van'
                   
                ];
            })->values();

            return compact('captains');
       
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

    public function getMapFilters(User $user): array
    {
        $shopIds = $this->streamlineInterface->getPermissibleShopIdsForUser(user: $user);
        return [
            [
                'count' => $this->streamlineInterface->countByStatus(
                    $this->getStatusIdsByFilterKey('new_orders'),
                    $shopIds
                ),
                'label' => 'New Orders',
                'key'   => 'new_orders',
            ],
            [
                'count' => $this->streamlineInterface->countByStatus(
                    $this->getStatusIdsByFilterKey('on_going_orders'),
                    $shopIds
                ),
                'label' => 'On Going Orders',
                'key'   => 'on_going_orders',
            ],
            [
                'count' => $this->streamlineInterface->countByStatus(
                    $this->getStatusIdsByFilterKey('pending_orders'),
                    $shopIds
                ),
                'label' => 'Pending Orders',
                'key'   => 'pending_orders',
            ],
            [
                'count' => $this->streamlineInterface->countByStatus(
                    $this->getStatusIdsByFilterKey('client_return_orders'),
                    $shopIds
                ),
                'label' => 'Client Return Orders',
                'key'   => 'client_return_orders',
            ],
            [
                'count' => $this->streamlineInterface->countByStatus(
                    $this->getStatusIdsByFilterKey('cancel_request_orders'),
                    $shopIds
                ),
                'label' => 'Cancel Request Orders',
                'key'   => 'cancel_request_orders',
            ],
            [
                'count' => $this->streamlineInterface->countWithClientChat($shopIds),
                'label' => 'Client Chat Orders',
                'key'   => 'client_chat_orders',
            ],
        ];
    }

    private function getStatusIdsByFilterKey(string $key)
    {
        return match ($key) {
            'new_orders'            => [ ...OrderStatus::NOT_ASSIGNED_ORDER],
            'on_going_orders'       => [
                 ...OrderStatus::ON_GOING_ORDER,
                OrderStatus::REROUTED,
                OrderStatus::TICKET_RAISED,
            ],
            'pending_orders'        => [OrderStatus::PENDING],
            'client_return_orders'  => [OrderStatus::RETURN_TO_CLIENT],
            'cancel_request_orders' => [OrderStatus::REQUEST_FOR_CANCEL],
            default                 => [ ...OrderStatus::NOT_ASSIGNED_ORDER],
        };
    }
}
