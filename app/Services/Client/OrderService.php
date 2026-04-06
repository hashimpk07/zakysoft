<?php
namespace App\Services\Client;

use App\Client;
use App\Events\OrderStatusChanged;
use App\Interfaces\ClientInterface;
use App\Jobs\ClientOrderExportJob;
use App\Order;
use App\OrderLog;
use App\OrderStatus;
use App\User;
use Carbon\Carbon;
use Facades\App\Services\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class OrderService
{
    public function __construct(private readonly ClientInterface $interface)
    {
    }

    public function getClientOrdersData($user, $request, $page = 20)
    {
        $client = $this->getClientFromUser($user);
        return $this->interface->getOrders($client->id, $request, $page);
    }

    public function getClientOrderById($user, $orderId)
    {
        $shopIds = self::getClientShopIdsFromUser($user);
        $order = $this->interface->getOrderById($shopIds, $orderId);
        $order->load(['notes.user.employeeClient.user']);
        $order->logs = $this->transformLogs($order);
        $notes = $order->notes->map(function ($note) {
            return [
                'note' => $note->note,
                'created_by' =>
                    optional(
                        $note->user
                            ?->employeeClient
                                ?->first()
                                ?->user
                    )->name ?? '4u Logistices',
                'created_at' => $note->created_at?->format('Y-m-d h:i:s A'),
            ];
        });

        unset($order->notes);
        $order->notes = $notes;

        return $order;
    }

    public static function getClientShopIdsFromUser(User $user): array
    {
        return $user->clientShops()->pluck('id')->toArray();
    }

    private function getClientFromUser(User $user)
    {
        return DashboardService::getClientFromUser($user);
    }

    public function getClientTransactions($user, int $perPage = 20, ?string $search = null): LengthAwarePaginator
    {
        $client = $this->getClientFromUser($user);

        $query = $client->transactions()->latest();

        // Apply search if provided
        if ($search) {
            $query->where(function ($q) use ($search) {
                // Search by transaction status
                $q->where('status', 'like', "%{$search}%")
                    // Search by payment mode
                    ->orWhere('payment_mode', 'like', "%{$search}%")
                    // Search by payable or receivable amounts
                    ->orWhere('payable', 'like', "%{$search}%")
                    ->orWhere('receivable_amount', 'like', "%{$search}%")
                    // Search by user name (related model)
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        return $query->paginate($perPage);
    }

    public function getOrderReport(User $user, Request $request): array
    {
        $fromTime = $request->get('order_time_from') ?? '06:00:00';
        if (strlen($fromTime) === 5) {
            // if format is HH:MM
            $fromTime .= ':00';
        }

        $fromDate = $request->has('from_date') ? Carbon::parse($request->get('from_date') . ' ' . $fromTime) : now()->subDays(6)->setTimeFromTimeString('06:00:00');

        // TO DATE
        $toTime = $request->get('order_time_to') ?? '05:59:59';
        if (strlen($toTime) === 5) {
            // if format is HH:MM
            $toTime .= ':59';
        }

        $toDate = $request->has('to_date') ? Carbon::parse($request->get('to_date') . ' ' . $toTime)->addDay() : now()->addDay()->setTimeFromTimeString('05:59:59');

        $filters = [
            'client_order_id' => $request->client_order_id,
            'client' => $request->client,
            'captain' => $request->captain,
            'third_party_company' => $request->companies,
            'assigned_by' => $request->assigned_by,
            'status_id' => $request->status_id,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];

        $perPage = (int) $request->get('per_page', 20);

        $reports = $this->interface->getClientReports(filters: $filters, perPage: $perPage);

        return compact('reports', 'filters');
    }

    /**
     * Get clients for the authenticated user
     */
    public function getClients($user)
    {
        if ($user->employeeClient->isNotEmpty()) {
            return Client::where('user_id', $user->employeeClient->first()->user_id)->get();
        }

        return Client::all();
    }

    /**
     * Get shops for the authenticated user
     */
     public function getClientShops($user)
    {
        // Get all shops via the user's clientShops relationship
        $shops = collect($user->clientShops())->filter();

        if (! $shops instanceof \Illuminate\Database\Eloquent\Collection) {
            $shops = new \Illuminate\Database\Eloquent\Collection($shops);
        }

        return $shops
            ->load(['deliveryTypes', 'timeSlots'])
            ->filter(fn($shop) => $shop && $shop->deliveryTypes->isNotEmpty())
            ->map(function ($shop) {
                return [
                    'id' => $shop->id,
                    'name' => $shop->name,
                    'client_id' => $shop->client_id,
                ];
            })
            ->values();
    }

    public function orderReturnAccept(User $user, int $orderId)
    {
        $order = $this->interface->findBelongsToUser($user, $orderId);

        if (!$order) {
            return [
                'status' => false,
                'message' => 'Order not found or not accessible',
            ];
        }

        if ($order->status_id !== OrderStatus::RETURN_TO_CLIENT) {
            return [
                'status' => false,
                'message' => 'Order is not in return to client status',
            ];
        }

        $this->interface->updateStatus(
            $order,
            OrderStatus::CLIENT_RETURN_ACCEPTED
        );

        OrderStatusChanged::dispatch($order);

        OrderStatusLog::log(OrderStatus::CLIENT_RETURN_ACCEPTED, null, $order->id, null, null, null, $user->id);

        return [
            'status' => true,
            'message' => 'Client return accepted',
        ];

    }

    public function orderReturnDecline(User $user, int $orderId, ?string $declineReason): array
    {
        $order = $this->interface->findBelongsToUser($user, $orderId);

        if (!$order) {
            return [
                'status' => false,
                'message' => 'Order not found or not accessible',
            ];
        }

        if ($order->status_id !== OrderStatus::RETURN_TO_CLIENT) {
            return [
                'status' => false,
                'message' => 'Order is not in return to client status',
            ];
        }

        $this->interface->updateStatus(
            $order,
            OrderStatus::CLIENT_RETURN_DECLINE
        );
        OrderStatusLog::log(OrderStatus::CLIENT_RETURN_DECLINE, null, $order->id, null, $declineReason, null, $user->id);
        OrderStatusChanged::dispatch($order);

        return [
            'status' => true,
            'message' => 'Order return to client declined',
        ];
    }
    public function getUserShopsAndClients(User $user): array
    {
        $result = $this->interface->getClientsAndShops($user);
        if ($result['shops']->isEmpty()) {
            return [
                'status' => false,
                'message' => 'You have no shop assigned to you. Please contact your supervisor.',
                'data' => [],
            ];
        }

        return [
            'status' => true,
            'message' => 'Data fetched successfully',
            'data' => $result,
        ];

    }

    private function transformLogs(Order $order): array
    {
        $logs = [];
        $repeatedLogs = [];

        foreach ($order->logsExecpt as $key => $log) {

            // Skip statuses
            if (
                in_array($log->status_id, [
                    OrderStatus::ORDER_PACKAGE,
                    OrderStatus::ASSIGN_ATTEMPTS,
                ])
            ) {
                continue;
            }

            //  Detect repeated consecutive status
            if (
                isset($order->logsExecpt[$key + 1]) &&
                $log->status_id === $order->logsExecpt[$key + 1]->status_id
            ) {
                $repeatedLogs[] = $log;
                continue;
            }

            //  Count repeats
            $repeatCount = count($repeatedLogs);

            $logs[] = [
                'status_id' => $log->status_id,
                'status' => $log->progress->name ?? '',
                'status_class' => $log->progress->status_class ?? '',

                'cancelled_by' => $log->canceled_by
                    ? (OrderLog::CANCELED_BY[$log->canceled_by] ?? '')
                    : '',

                'repeat_count' => $repeatCount,

                'note' => $log->note(),
                'created_by' => $log->createdBy->name ?? '',
                'created_at' => $log->created_at
                    ? $log->created_at->format('Y-m-d h:i A')
                    : '',
            ];

            // Reset repeated logs (EXACT like Blade)
            $repeatedLogs = [];
        }

        return $logs;
    }

    public function clientOrderExport(array $filters, User $user)
    {
        $exportFilters = [
            'email' => $filters['email'],
            'from_date' => $filters['from_date'] ?? null,
            'to_date' => $filters['to_date'] ?? null,
            'shopname' => $filters['shopname'] ?? null,
            'status' => $filters['status'] ?? null,
            'q' => $filters['q'] ?? null,
        ];
        $exportFilters = array_filter($exportFilters, fn($value, $key) => !is_null($value) || $key === 'email', ARRAY_FILTER_USE_BOTH);
        $export = $this->interface->exportClientOrderCreate([
            'export_type' => 'client order report',
            'status' => 'pending',
            'email_id' => $filters['email'],
            'filters' => json_encode($exportFilters),
            'created_by' => $user->id,
        ]);

        dispatch(
            new ClientOrderExportJob(
                $export,
                'client-order-export',
                1,
                $filters,
                $user
            )
        );
        return [
            'status' => (bool) $export,
            'message' => $export
                ? 'Order export successfully' : 'Order export failed',
        ];

    }
}
