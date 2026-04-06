<?php
namespace App\Listeners;

use App\DispatchRule;
use App\Events\AutoAssignPackageAttempt;
use App\Events\OrderStatusChanged;
use App\Order;
use App\OrderStatus;
use App\Services\OrderStatusLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogAutoAssignPackageSend implements ShouldQueue
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  AutoAssignPackageAttempt  $event
     * @return void
     */
    public function handle(AutoAssignPackageAttempt $event)
    {
        try {
            // Log::channel('auto_assigning')->debug('LogAutoAssignPackageSend job started');

            $package = $event->package;
            $captains = $event->captains;
            $orders = $package->directOrders;

            // Log::channel('auto_assigning')->debug('Auto Assign Package Send', [
            //     'package_id' => $package->id,
            //     'captains_count' => $captains->count(),
            //     'orders_count' => $orders->count(),
            // ]);

            $dispatch_rule = DispatchRule::find($package->dispatch_rule_id);

            // Get allowed KM values from notification preferences
            $allowedValues = $dispatch_rule->notificationPreference()
                ->orderBy('waiting_time', 'asc')
                ->pluck('to_km')
                ->toArray();

            // Get last assign attempt note for this package's orders
            $assignAttempts = Order::whereIn('id', $orders->pluck('id'))
                ->where('status_id', OrderStatus::ASSIGN_ATTEMPTS)
                ->withAssignAttempts()
                ->first();

            $lastNote = $assignAttempts?->last_assign_attempt_note;
            $hasCritical = $lastNote && Str::contains($lastNote, '(CRITICAL)');

            if (!$hasCritical) {
                // Extract KM from note
                $matchedValue = null;
                if ($lastNote && preg_match('/(\d+(?:\.\d+)?)\s*KM/i', $lastNote, $matches)) {
                    $matchedValue = (float) $matches[1];
                }

                // Check if note KM matches allowed values
                $isMatch = $matchedValue !== null && in_array($matchedValue, $allowedValues, true);

                // Log::channel('auto_assigning')->debug('Assign note match check', [
                //     'note' => $lastNote,
                //     'matched_value' => $matchedValue,
                //     'allowed_values' => $allowedValues,
                //     'is_match' => $isMatch,
                // ]);

                // Determine max_radius
                if (!$isMatch) {
                    // If no match, use the **highest value in the array** or critical radius if array empty
                    $max_radius = !empty($allowedValues) ? $allowedValues[0] : $dispatch_rule->critical_radius;
                } else {
                    // If matched, find next higher value (index + 1)
                    $currentIndex = array_search($matchedValue, $allowedValues, true);
                    $max_radius = $currentIndex !== false && isset($allowedValues[$currentIndex + 1])
                        ? $allowedValues[$currentIndex + 1]
                        : $dispatch_rule->critical_radius;
                }

                // Determine if it's critical

                $is_critical = $dispatch_rule->critical_radius == $max_radius;

                Log::channel('auto_assigning')->debug('Max radius calculation', [
                    'max_radius' => $max_radius,
                    'is_critical' => $is_critical,
                    'allowed_values' => $allowedValues,
                    'matched_value' => $matchedValue,
                ]);

            } else {
                $max_radius = $dispatch_rule->critical_radius;
                $is_critical = true;

                 Log::channel('auto_assigning')->debug('critical log applied', [
                    'max_radius' => $max_radius,
                    'is_critical' => $is_critical,
                    
                ]);
            }



            $is_critical_log = $is_critical ? ' (CRITICAL)' : '';

            $note = $captains->isNotEmpty() ? "Order sent to {$max_radius} KM radius{$is_critical_log}" : "Rider not found in {$max_radius} KM radius{$is_critical_log}";

            Log::channel('auto_assigning')->info('BroadcastPackage debug data', [
                'max_radius' => $max_radius,
                'is_critical' => $is_critical,
                'note' => $note,
                'is_critical_log' => $is_critical_log,
            ]);

            Log::channel('auto_assigning')->info('Auto Assign Package Send', [
                'package_id' => $package->id,
                'captains_count' => $captains->count(),
                'orders_count' => $orders->count(),
                'km_with_in' => $max_radius,
                'note' => $note,
            ]);
            foreach ($orders as $key => $order) {
                $order = $order->fresh();

                if (! $order || ! in_array($order->status_id, [OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS, OrderStatus::RESCHEDULED])) {
                    Log::channel('auto_assigning')->debug('Skipping auto assign attempt log for non-assignable order state', [
                        'package_id' => $package->id,
                        'order_id' => $order?->id,
                        'status_id' => $order?->status_id,
                    ]);
                    continue;
                }

                $order->status_id = OrderStatus::ASSIGN_ATTEMPTS;
                $order->save();

                OrderStatusChanged::dispatch($order);
                (new OrderStatusLog)->log(OrderStatus::ASSIGN_ATTEMPTS, null, $order->id, null, $note, null, config('app.system_user'));
            }
        } catch (\Exception $e) {
            Log::channel('auto_assigning')->error('LogAutoAssignPackageSend job failed', [
                'package_id' => $event->package->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
