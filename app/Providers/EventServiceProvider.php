<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        \App\Events\NewClient::class => [
            \App\Listeners\SendNewClientNotification::class,
            // \App\Listeners\NotifyOdooOfNewClient::class,
        ],
        \App\Events\NewClientShop::class => [
            \App\Listeners\SendNewClientShopNotification::class,
            // \App\Listeners\NotifyOdooOfNewBranch::class,
        ],
        \App\Events\NewOrder::class => [
            \App\Listeners\CalculateCaptainStoreDistance::class,
            \App\Listeners\UpdateOrderCache::class,
            \App\Listeners\FindAdjacentOrders::class,
            \App\Listeners\FindDistanceStoreOrder::class,
            \App\Listeners\Madar\SendNewOrderToMadar::class,
        ],
        \App\Events\OrderAddressChanged::class => [
            \App\Listeners\FindDistanceStoreOrder::class,
        ],
        \App\Events\OrderStatusChanged::class => [
            \App\Listeners\OrderStatusChangePush::class,
            \App\Listeners\UpdateClientDeliveryCharge::class,
            \App\Listeners\UpdateOrderCache::class,
            \App\Listeners\SendVerificationNotificationCaptain::class,
            \App\Listeners\Madar\SendOrderStatusToMadar::class,
        ],
        \App\Events\SallaNewOrder::class => [
            \App\Listeners\ProcessSallaNewOrder::class,
        ],
        \App\Events\CaptainRegistrationRequest::class => [
            \App\Listeners\SendCaptainRegistrationRequestNotification::class,
        ],
        \App\Events\CaptainLocationUpdated::class => [
        ],
        \App\Events\NewTicket::class => [
            \App\Listeners\SendTicketAutoResponse::class,
        ],
        \App\Events\NewTicketMessage::class => [
            \App\Listeners\SendTicketMessageNotification::class,
        ],
        \App\Events\AutoAssignPackageAttempt::class => [
            \App\Listeners\LogAutoAssignPackageSend::class,
        ],
        \App\Events\AutoAssignPackageBound::class => [
            \App\Listeners\LogAutoAssignPackageBound::class,
        ],
        \App\Events\OrderDeliveryFinish::class => [
            \App\Listeners\UpdateCaptainOrderBalance::class,
            \App\Listeners\UpdateCaptainOrderCommission::class,
            \App\Listeners\CloseTicket::class,
            \App\Listeners\UpdateCaptainReport::class,
            \App\Listeners\UpdateOrderReport::class,
            // \App\Listeners\NotifyOdooOfNewCreateOrder::class,
            \App\Listeners\UpdateCaptainPriority::class,
        ],
        \App\Events\OrderReDispatching::class => [
            \App\Listeners\ReCalculateCaptainReport::class,
            \App\Listeners\ReCalculateCaptainOrderCommission::class,
            \App\Listeners\ReCalculateThirdPartyOrderCommission::class,
            \App\Listeners\ReCalculateDeliveryCharge::class,
            \App\Listeners\DeleteOrderReport::class,
        ],
        \App\Events\CaptainLocationChanged::class => [
            \App\Listeners\PushCaptainLocation::class,
        ],
        \App\Events\CaptainOrderAssigned::class => [
            \App\Listeners\FindDistanceCaptainStore::class,
        ],
        \App\Events\CaptainCommissionPaymentCreated::class => [
            \App\Listeners\CaptainReportCommissionPayed::class,
        ],
        \App\Events\CaptainCommissionPaymentUpdated::class => [
            \App\Listeners\CaptainReportCommissionPaymentUpdated::class,
        ],
        \App\Events\CaptainShiftStarted::class => [
        ],
        \App\Events\CaptainShiftClosed::class => [
            \App\Listeners\LogCaptainWorkingTimeListener::class,
        ],
        // \App\Events\ClientBrandCreated::class => [
        //     \App\Listeners\NotifyOdooOfNewClientBrand::class,
        // ],
        // \App\Events\ClientBrandUpdated::class => [
        //     \App\Listeners\NotifyOdooOfUpdateClientBrand::class,
        // ],
        // \App\Events\UpdateClientShop::class => [
        //     \App\Listeners\NotifyOdooOfUpdateClientShop::class,
        // ],
        // \App\Events\UpdateClient::class => [
        //     \App\Listeners\NotifyOdooOfUpdateClient::class,
        // ],
        // \App\Events\ShiftStarted::class => [
        //     \App\Listeners\CreateAuditLogFromEvent::class,
        // ],
        // \App\Events\BreakStarted::class => [
        //     \App\Listeners\CreateAuditLogFromEvent::class,
        // ],
        // \App\Events\BreakEnded::class => [
        //     \App\Listeners\CreateAuditLogFromEvent::class,
        // ],
        // \App\Events\ShiftEnded::class => [
        //     \App\Listeners\CreateAuditLogFromEvent::class,
        // ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        //
    }
}
