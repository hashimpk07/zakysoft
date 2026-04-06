<?php

namespace App\Repositories\Reports\CaptainReports;

use App\Captain;
use App\CaptainOrderPayment;
use App\Interfaces\Reports\CaptainReports\CaptainDeliveryInterface;
use App\Order;
use App\OrderStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class CaptainDeliveryInterfaceRepository implements CaptainDeliveryInterface
{
    public function getCaptainDeliveryReport(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return Captain::query()
            ->select(['captains.id', 'captains.code', 'captains.iqama_number', 'captains.date_of_joining', 'captains.status', 'captains.captain_employment_type_id', 'captains.nationality_id', 'captains.given_custodyamount', 'captains.user_id', 'captains.region_id'])
            ->with(['user:id,name', 'employmentType:id,name', 'nationality:id,name', 'regions:id,name,quadrant_id', 'regions.quadrant:id,name', 'orderPayableBalance:captain_id,balance'])
            ->withCount([
                'orders as attended_orders' => function ($query) {
                    $query->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED]);
                },
            ])
            ->withSum(
                [
                    'captainOrderPayments as total_received_amount_from_leajlak' => function ($query) {
                        $query->where('type', CaptainOrderPayment::RECEIVING_TYPE);
                    },
                ],
                'transferring_amount',
            )
            ->withSum(
                [
                    'captainOrderPayments as total_payed_amount_from_leajlak' => function ($query) {
                        $query->where('type', CaptainOrderPayment::PAYING_TYPE);
                    },
                ],
                'transferring_amount',
            )
            ->withSum(
                [
                    'orders as total_bill_amount' => function ($query) {
                        $query->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])->where('orders.delivery_payment_mode', 'Auto');
                    },
                ],
                'amount',
            )
            ->withSum('shopPayments as store_payments', 'amount')
            ->withSum(
                [
                    'OrderPayment as cod' => function ($query) {
                        $query->select(
                            DB::raw('
                    sum(
                        CASE
                            WHEN order_payments.payment_mode = "By Cash" THEN order_payments.given_amount
                            WHEN order_payments.payment_mode = "Both" THEN order_payments.cash
                            ELSE 0
                        END
                    )'),
                        );
                    },
                ],
                'cash',
            )
            ->withSum(
                [
                    'OrderPayment as credited_to_leajlak' => function ($query) {
                        $query->select(
                            DB::raw('
                    sum(
                        CASE
                            WHEN order_payments.payment_mode = "By POS" THEN order_payments.given_amount
                            WHEN order_payments.payment_mode = "Both" THEN order_payments.pos_amount
                            ELSE 0
                        END
                    )'),
                        );
                    },
                ],
                'pos_amount',
            )
            ->when($filters['employee_id'] ?? null, fn($q, $v) => $q->whereLike('code', $v))
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->where('captains.id', $v))
            ->when($filters['name'] ?? null, fn($q, $v) => $q->whereLike(['user.name'], $v))
            ->when($filters['iqama'] ?? null, fn($q, $v) => $q->where('captains.iqama_number', 'LIKE', $v . '%'))
            ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['area'] ?? null, fn($q, $v) => $q->whereHas('regions', fn($q) => $q->where('regions.id', $v)))
            ->when($filters['job_type'] ?? null, fn($q, $v) => $q->where('captains.captain_employment_type_id', $v))
            ->when($filters['nationality'] ?? null, fn($q, $v) => $q->where('captains.nationality_id', $v))
            ->when($filters['on_duty_from'] ?? null, fn($q, $v) => $q->where('captains.date_of_joining', '>=', now()->parse($v)->format('Y-m-d')))
            ->when($filters['work_status'] ?? null, fn($q, $v) => $q->where('captains.status', $v))
            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                match ($status) {
                    'Receivable' => $query->where('balance', '>', 0),
                    'Payable' => $query->where('balance', '<', 0),
                    'Tally' => $query->where('balance', '=', 0),
                    default => null,
                };
            })
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getOrderStatistics(array $filters): object
    {
        return Order::query()
            ->leftJoin(DB::raw('(SELECT MAX(id) AS max_id, order_id FROM shop_payments GROUP BY order_id) AS sp_max'), function ($join) {
                $join->on('sp_max.order_id', '=', 'orders.id');
            })
            ->leftJoin('shop_payments AS sp', 'sp.id', '=', 'sp_max.max_id')
            ->leftJoin('captains', 'orders.captain_id', '=', 'captains.id')
            ->leftJoin(DB::raw('(SELECT MAX(id) AS max_id, order_id FROM order_payments GROUP BY order_id) AS op_max'), function ($join) {
                $join->on('op_max.order_id', '=', 'orders.id');
            })
            ->leftJoin('order_payments AS op', 'op.id', '=', 'op_max.max_id')
            ->leftJoin(DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_order_payments GROUP BY captain_id) as max_order_payments'), 'captains.id', '=', 'max_order_payments.captain_id')
            ->leftJoin('captain_order_payments', 'max_order_payments.max_id', '=', 'captain_order_payments.id')
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->when($filters['employee_id'] ?? null, fn($q, $v) => $q->where('captains.code', 'LIKE', '%' . $v . '%'))
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captains.id', $v)))
            ->when($filters['name'] ?? null, fn($q, $v) => $q->whereLike(['captain.user.name'], $v))
            ->when($filters['iqama'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('iqama_number', 'LIKE', $v . '%')))
            ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('captain.regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['area'] ?? null, fn($q, $v) => $q->whereHas('captain.regions', fn($q) => $q->where('regions.id', $v)))
            ->when($filters['job_type'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captain_employment_type_id', $v)))
            ->when($filters['nationality'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('nationality_id', $v)))
            ->when($filters['on_duty_from'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('date_of_joining', '>=', now()->parse($v)->format('Y-m-d'))))
            ->when($filters['work_status'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('status', $v)))
            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                match ($status) {
                    'Receivable' => $query->where('balance', '>', 0),
                    'Payable' => $query->where('balance', '<', 0),
                    'Tally' => $query->where('balance', '=', 0),
                    default => null,
                };
            })
            ->selectRaw('COUNT(*) AS attended_orders')
            ->selectRaw('SUM(CASE WHEN orders.delivery_payment_mode = "Auto" THEN orders.amount ELSE 0 END) AS total_bill_amount')
            ->selectRaw('SUM(sp.amount) AS total_store_payment')
            ->selectRaw('SUM(CASE WHEN op.payment_mode = "By Cash" THEN op.given_amount WHEN op.payment_mode = "Both" THEN op.cash ELSE 0 END) AS total_cash_on_delivery')
            ->selectRaw('SUM(CASE WHEN op.payment_mode = "By POS" THEN op.given_amount WHEN op.payment_mode = "Both" THEN op.pos_amount ELSE 0 END) AS total_payment_done_by_leajlak_span')
            ->first();
    }

    public function getCaptainBalanceStatistics(array $filters): object
    {
        return Captain::query()
            ->leftJoin('captain_order_payments', function ($join) {
                $join->on('captain_order_payments.captain_id', '=', 'captains.id')->whereRaw('captain_order_payments.id = (SELECT MAX(id) FROM captain_order_payments WHERE captain_id = captains.id)');
            })
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->where('captains.id', $v))
            ->when($filters['employee_id'] ?? null, fn($q, $v) => $q->whereLike('code', $v))
            ->when($filters['name'] ?? null, fn($q, $v) => $q->whereLike(['user.name'], $v))
            ->when($filters['iqama'] ?? null, fn($q, $v) => $q->where('captains.iqama_number', 'LIKE', $v . '%'))
            ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['area'] ?? null, fn($q, $v) => $q->whereHas('regions', fn($q) => $q->where('regions.id', $v)))
            ->when($filters['job_type'] ?? null, fn($q, $v) => $q->where('captains.captain_employment_type_id', $v))
            ->when($filters['nationality'] ?? null, fn($q, $v) => $q->where('captains.nationality_id', $v))
            ->when($filters['on_duty_from'] ?? null, fn($q, $v) => $q->where('captains.date_of_joining', '>=', now()->parse($v)->format('Y-m-d')))
            ->when($filters['work_status'] ?? null, fn($q, $v) => $q->where('captains.status', $v))
            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                match ($status) {
                    'Receivable' => $query->where('balance', '>', 0),
                    'Payable' => $query->where('balance', '<', 0),
                    'Tally' => $query->where('balance', '=', 0),
                    default => null,
                };
            })
            ->selectRaw('SUM(CASE WHEN captain_order_payments.balance > 0 THEN captain_order_payments.balance ELSE 0 END) AS total_receivable')
            ->selectRaw('SUM(CASE WHEN captain_order_payments.balance < 0 THEN ABS(captain_order_payments.balance) ELSE 0 END) AS total_payable')
            ->selectRaw('SUM(captains.given_custodyamount) AS total_given_custody_amount')
            ->first();
    }

    public function getOrderDeliveryStatistics(int $captainId, array $filters, array $dateRange): object
    {
        [$from, $to] = $dateRange;

        return Order::query()
            ->select(DB::raw('COUNT(*) as attended_orders'), DB::raw('SUM(CASE WHEN orders.delivery_payment_mode = "Auto" THEN orders.amount ELSE 0 END) as total_bill_amount'), DB::raw('SUM(shop_payments.amount) as total_store_payment'), DB::raw('SUM(CASE WHEN order_payments.payment_mode = "By Cash" THEN order_payments.given_amount WHEN order_payments.payment_mode = "Both" THEN order_payments.cash ELSE 0 END) as total_cash_on_delivery'), DB::raw('SUM(CASE WHEN order_payments.payment_mode = "By POS" THEN order_payments.given_amount WHEN order_payments.payment_mode = "Both" THEN order_payments.pos_amount ELSE 0 END) as total_payment_done_by_leajlak_span'))
            ->leftJoin('shop_payments', function ($join) {
                $join->whereRaw('shop_payments.order_id = orders.id')->whereRaw('shop_payments.id IN (SELECT MAX(sp.id) FROM shop_payments AS sp WHERE sp.order_id = orders.id)');
            })
            ->leftJoin('order_payments', function ($join) {
                $join->whereRaw('order_payments.order_id = orders.id')->whereRaw('order_payments.id IN (SELECT MAX(op.id) FROM order_payments AS op WHERE op.order_id = orders.id)');
            })
            ->where('orders.captain_id', $captainId)
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->whereBetween('orders.delivery_date', [$from, $to])
            ->when($filters['region'] ?? null, fn($q, $v) => $q->where(fn($q) => $q->where('region_id', $v)->orWhereHas('shop.region', fn($q) => $q->where('id', $v))))
            ->when($filters['q'] ?? null, fn($q, $v) => $q->where('orders.client_order_id', 'LIKE', $v . '%'))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('orders.status_id', $v))
            ->when($filters['client'] ?? null, fn($q, $v) => $q->where('orders.client_id', $v))
            ->when($filters['shop'] ?? null, fn($q, $v) => $q->where('orders.shopname', $v))
            ->first();
    }

    public function getDeliveredOrdersByCaptain(int $captainId, array $filters, array $dateRange, int $perPage = 20): LengthAwarePaginator
    {
        [$from, $to] = $dateRange;

        return Order::query()
            ->with(['captain.user', 'client.user', 'shop', 'progress', 'payment', 'shopPayment', 'captainPayment.updatedBy', 'captainPayment.attachments'])
            ->where('captain_id', $captainId)
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->whereBetween('orders.delivery_date', [$from, $to])
            ->orderBy('delivery_date', 'desc')
            ->when($filters['region'] ?? null, fn($q, $v) => $q->where(fn($q) => $q->where('region_id', $v)->orWhereHas('shop.region', fn($q) => $q->where('id', $v))))
            ->when($filters['q'] ?? null, fn($q, $v) => $q->where('orders.client_order_id', 'LIKE', $v . '%'))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('orders.status_id', $v))
            ->when($filters['client'] ?? null, fn($q, $v) => $q->where('orders.client_id', $v))
            ->when($filters['shop'] ?? null, fn($q, $v) => $q->where('orders.shopname', $v))
            ->paginate($perPage ?? 20)
            ->withQueryString();
    }

    public function getLatestBalance(int $captainId): ?object
    {
        return CaptainOrderPayment::where('captain_id', $captainId)->latest()->first();
    }

    public function getPreviousEditableBalance(int $captainId): ?object
    {
        $hasMultiple = CaptainOrderPayment::where('captain_id', $captainId)->whereNotNull('updated_by')->count() > 1;

        if (!$hasMultiple) {
            return null;
        }

        return CaptainOrderPayment::where('captain_id', $captainId)->whereNotNull('updated_by')->latest()->first();
    }

    public function getCaptainStatistics(int $captainId, array $filters, array $dateRange): object
    {
        [$from, $to] = $dateRange;

        return CaptainOrderPayment::query()
            ->where('captain_id', $captainId)
            ->selectRaw('SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) AS total_receivable')
            ->selectRaw('SUM(CASE WHEN balance < 0 THEN ABS(balance) ELSE 0 END) AS total_payable')
            ->selectRaw('(SELECT given_custodyamount FROM captains WHERE id = ?) AS total_given_custody_amount', [$captainId])
            ->whereBetween('created_at', [$from, $to])
            ->first();
    }

    public function getLatestCaptainOrderPaymentByCaptain(int $captainId): ?CaptainOrderPayment
    {
        return CaptainOrderPayment::where('captain_id', $captainId)->latest()->first();
    }

    public function saveCaptainOrderPayment(CaptainOrderPayment $payment): bool
    {
        return $payment->save();
    }

    public function createCaptainOrderPaymentAttachments(CaptainOrderPayment $payment, array $attachments): void
    {
        $payment->attachments()->createMany($attachments);
    }
}
