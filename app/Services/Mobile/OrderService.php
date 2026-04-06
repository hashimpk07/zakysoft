<?php
namespace App\Services\Mobile;

use App\Captain;
use App\Events\OrderStatusChanged;
use App\Filter\OrderFilter;
use App\Interfaces\Mobile\OrderInterface as MobileOrderInterface;
use App\Order;
use App\OrderStatus;
use App\Package;
use Carbon\Carbon;
use Facades\App\Services\OrderStatusLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrderService
{
    public function __construct(protected readonly MobileOrderInterface $orderInterface, protected readonly GeneralService $generalService) {}

    public function getDashboardData(Request $request): array
    {
        [$fromDate, $toDate] = getSystemTimeRange(fromDate: $request->get('from_date', now()->format('Y-m-d')), toDate: $request->get('to_date', now()->format('Y-m-d')));

        $statistsics = $this->orderInterface->getOrderStatistics($fromDate, $toDate);

        $captainsCount = $this->orderInterface->getCaptainsCount();

        return [...$statistsics, ...$captainsCount];
    }

    public function getOrders(OrderFilter $request, int $perPage): LengthAwarePaginator
    {
        return $this->orderInterface->getOrders($request, $perPage);
    }

    public function getAcceptableOrders(Request $request, Captain $captain)
    {
        $orders = $this->orderInterface->getAcceptableOrders(captainId: $captain->id);

        $package = $this->orderInterface->getPackageForAcceptableOrders(request: $request, captainId: $captain->id);

        if ($package) {
            $rejectReasons = $this->orderInterface->getPackageRejectReasons();

            $assignable_package = [
                'package_id' => $package->id,
                'orders_count' => $package->orders->count(),
                'rejection_reasons' => $rejectReasons,
                'remaining_time' => $this->calculatePackageRemainingTime($package),
                'title' => __('app/notifications.package.title', [], $captain->user->language),
                // 'shop_name' => $package->shop->name,
            ];

            return $assignable_package;
        }

        return null;
    }

    private function calculatePackageRemainingTime(Package $package)
    {
        // Get the delivery request for this captain

        $deliveryRequest = $package->deliveryRequests()->whereNull('declined_at')->latest('sended_at')->first();

        if (!$deliveryRequest || !$deliveryRequest->sended_at) {
            return 0;
        }

        $sendedAt = Carbon::parse($deliveryRequest->sended_at);
        $diffInSeconds = now()->diffInSeconds($sendedAt->addSeconds(20), false);
        return max(0, $diffInSeconds);
    }

    public function getEarnings(Captain $captain, ?string $fromDate, ?string $toDate): array
    {
        [$from, $to] = $this->resolveBusinessDates($fromDate, $toDate);

        $statistics = $this->orderInterface->getEarningStatistics(captain: $captain, from: $from, to: $to);

        $balance = $captain->lastCommission->balance ?? 0;
        $formattedBalance = number_format($balance, 2);

        $data = [
            // "attended_orders" => (int) $statistics->attended_orders ?? 0,
            // "total_earnings" => (double) $statistics->total_commission ?? 0,
            // "total_earnings_payed" => (double) $statistics->total_payed_commission ?? 0,
            // "receivable_earnings" => (double) ($captain->lastCommission ? $captain->lastCommission->balance : 0)

            // "attended_orders" => (int) $statistics->attended_orders ?? 0,
            // "total_earnings" => 'SAR ' . number_format((float) ($statistics->total_commission ?? 0), 2),
            // "total_earnings_payed" => 'SAR ' . number_format((float) ($statistics->total_payed_commission ?? 0), 2),
            // "receivable_earnings" => 'SAR ' . number_format((float) ($captain->lastCommission ? $captain->lastCommission->balance : 0), 2)

            'attended_orders' => (int) ($statistics->attended_orders ?? 0),
            'total_earnings' => moneyFormat(amount: 0),
            'total_earnings_payed' => moneyFormat(amount: 0),
            'receivable_earnings' => moneyFormat(amount: 0),
        ];

        if ($captain->earningCommission()) {
            $data['commission_per_order'] = moneyFormat(amount: 0);
        }

        return $data;
    }

    private function resolveBusinessDates(?string $fromDate, ?string $toDate): array
    {
        $now = now();
        $businessDayStart = $now->copy()->setTime(6, 0, 0);

        if ($now->lt($businessDayStart)) {
            $from = $now->copy()->subDay()->setTime(6, 0, 0);
            $to = $now->copy()->setTime(5, 59, 59);
        } else {
            $from = $now->copy()->setTime(6, 0, 0);
            $to = $now->copy()->addDay()->setTime(5, 59, 59);
        }

        if ($fromDate) {
            $from = now()->parse($fromDate)->setTime(6, 0, 0);
        }

        if ($toDate) {
            $to = now()->parse($toDate)->addDay()->setTime(5, 59, 59);
        }

        return [$from, $to];
    }

    public function getEarningsList(Captain $captain, ?string $fromDate, ?string $toDate, int $perPage = 30)
    {
        [$from, $to] = $this->resolveBusinessDates($fromDate, $toDate);

        $statistics = $this->orderInterface->getEarningStatisticsList(captain: $captain, from: $from, to: $to, perPage: $perPage);

        $data = $statistics->getCollection()->transform(function ($order) {
            return [
                'created_at' => $order->created_at->format('d-m-Y h:i:s a'),
                'client' => $order->client->user->name,
                'shop' => $order->shop->name,
                'client_order_id' => $order->client_order_id,
                'delivered_at' => now()->parse($order->delivery_date)->format('d-m-Y h:i:s a'),
                'commission_earned' => moneyFormat(amount: $order->captainCommission ? $order->captainCommission->commission : 0),
                // "commission_earned" => (double) ($order->captainCommission ? $order->captainCommission->commission : 0)
            ];
        });

        return $data;
    }

    public function getCommissionTransactionList(Captain $captain, int $perPage)
    {
        $transactions = $this->orderInterface->getCommissionTransactionList(captain: $captain, perPage: $perPage);

        return $transactions->getCollection()->transform(function ($transaction) {
            return [
                'created_at' => $transaction->settled_at->format('d-m-Y h:i:s a'),
                'received_from' => $transaction->settledBy->name,
                'settled_amount' => (string) $transaction->amount_paid,
                'paid_by' => $transaction->paymentMode->name,
                'reference_no' => $transaction->reference_no,
                'invoice_url' => route('order.commission.transaction.receipt', $transaction),
                'docs' => $transaction->commission
                    ? $transaction->commission->attachments->map(function ($attachment) {
                        return [
                            'path' => asset($attachment->path),
                        ];
                    })
                    : [],
            ];
        });
    }

    public function updateOrder(Order $order, array $data)
    {
        return $this->orderInterface->updateOrder($order, $data);
    }

    public function acceptOrder($packageId, $captain)
    {
        $package = $this->orderInterface->findPackageId(packageId: $packageId);

        $captainId = $captain->id;

        if (!is_null($package->captain_id)) {
            $this->orderInterface->updateLatestPackageDeliveryRequest(packageId: $packageId, captainId: $captainId);

            throw ValidationException::withMessages([
                'order' => 'Nice Try, The order is already accepted by someone else, better luck next time',
            ]);
        }

        DB::transaction(function () use ($package, $captain) {
            $orders = $this->orderInterface->updateOrderStatus(package: $package, captain: $captain);

            $this->orderInterface->updatePackage($package, [
                'captain_id' => $captain->id,
                'captain_accepted_at' => now(),
                'expected_delivery_completion_at' => null,
            ]);

            foreach ($orders as $key => $order) {
                OrderStatusChanged::dispatch($order);
            }
        });
    }

    public function declineOrder($packageId, $captainId, $rejectionReasonId)
    {
        $package_requests = $this->orderInterface->getPackageDeliveryRequest(packageId: $packageId, captainId: $captainId);

        if ($package_requests->isEmpty()) {
            throw ValidationException::withMessages([
                'package' => 'Package not found',
            ]);
        }

        $package_orders = $this->orderInterface->getDirectOrders(packageId: $packageId);
        $rejectReason = $this->orderInterface->getRejectionReasonText(reasonId: $rejectionReasonId);

        foreach ($package_orders->directOrders as $order) {
            OrderStatusLog::log(OrderStatus::CAPTAIN_ORDER_REJECTED, $captainId, $order->id, null, $rejectReason, null, null);

            if ($order->captain_id !== $captainId) {
                Log::warning('Captain not assigned to this order', [
                    'order_id' => $order->id,
                    'captain_id' => $captainId,
                ]);
                continue;
            }

            if (!in_array($order->status_id, OrderStatus::UNABLE_TO_CHANGE_NEW_ORDER)) {
                $this->orderInterface->updateOrder($order, [
                    'status_id' => OrderStatus::NEW_ORDER,
                    'captain_id' => null,
                ]);

                OrderStatusLog::log(OrderStatus::NEW_ORDER, $captainId, $order->id, null, null, null, null);
            }
        }

        return $this->orderInterface->markOrderDeclined(packageIds: $package_requests->pluck('id')->toArray(), reasonId: $rejectionReasonId);
    }

    public function getDashboardOrdersList(int $captainId, ?int $status, string $language, string $theme)
    {
        $orders = $this->orderInterface->getCaptainOrders(captainId: $captainId, status: $status);

        if ($orders->isEmpty()) {
            throw new ModelNotFoundException('Orders not found.');
        }

        return ProcessOrderService::processOrdersListForDashboard(orders: $orders, language: $language, themeMode: $theme);
    }

    public function changeOrderStatus(array $orderIds, int $captainId, Request $request, OrderStatus $orderStatus)
    {
        if (isset($request->get('data')['lat']) && isset($request->get('data')['long'])) {
            $this->generalService->createCaptainLocationLog([
                'captain_id' => $captainId,
                'zone_id' => 0,
                'latitude' => $request->get('data')['lat'],
                'longitude' => $request->get('data')['long'],
                'last_updated_at' => now(),
            ]);
        }

        $captain = $this->generalService->findCaptainById(id: $captainId);

       return ChangeOrderStatusService::changeBulkStatus(orderIds: $orderIds, status: $orderStatus, captain: $captain, request: $request);
    }
}
