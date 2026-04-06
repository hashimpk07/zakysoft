<?php
namespace App\Providers;

use App\Interfaces\General\DashboardInterface;
use App\Interfaces\General\DashboardManagementInterface;
use App\Interfaces\General\OperationalPerformanceInterface;
use App\Interfaces\General\OrderInterface as AdminOrderInterface;
use App\Interfaces\General\PackageInterface;
use App\Interfaces\ClientInterface;
use App\Interfaces\ClientReportInterface;
use App\Interfaces\ClientStreamlineInterface;
use App\Interfaces\General\AssetInterface;
use App\Interfaces\General\CaptainInterface;
use App\Interfaces\General\GeoSettingInterface;
use App\Interfaces\General\LogsExpireInterface;
use App\Interfaces\General\SystemSettingInterface;
use App\Interfaces\ListInterface;
use App\Interfaces\Mobile\GeneralInterface;
use App\Interfaces\Mobile\OrderInterface as MobileOrderInterface;
use App\Interfaces\Mobile\ReminderInterface;
use App\Interfaces\Mobile\ShiftInterface as MobileShiftInterface;
use App\Interfaces\Mobile\TicketInterface as MobileTicketInterface;
use App\Interfaces\Mobile\VehicleRentalInterface;
use App\Interfaces\OperationCallInterface;
use App\Interfaces\OrderInterface;
use App\Interfaces\Reports\CaptainReports\MakePaymentInterface;
use App\Interfaces\Reports\CaptainReports\PaymentInterface;
use App\Interfaces\ShiftInterface;
use App\Interfaces\StreamLineInterface;
use App\Interfaces\ThirdPartyLogisticInterface;
use App\Interfaces\ThirdPartyLogisticReportInterface;
use App\Interfaces\ThirdPartyStreamlineInterface;
use App\Interfaces\TicketInterface;
use App\Repositories\General\DashboardInterfaceRepository;
use App\Repositories\General\DashboardManagementInterfaceRepository;
use App\Repositories\General\OperationalPerformanceInterfaceRepository;
use App\Repositories\General\OrderInterfaceRepository as AdminOrderInterfaceRepository;
use App\Repositories\General\PackageInterfaceRepository;
use App\Repositories\ClientInterfaceRepository;
use App\Repositories\ClientReportInterfaceRepoisotory;
use App\Repositories\Client\ClientStreamlineInterfaceRepository;
use App\Repositories\ListRepository;
use App\Repositories\Mobile\GeneralInterfaceRepository;
use App\Repositories\Mobile\OrderInterfaceRepository as MobileOrderInterfaceRepository;
use App\Repositories\Mobile\ReminderInterfaceRepository;
use App\Repositories\Mobile\ShiftInterfaceRepository as MobileShiftInterfaceRepository;
use App\Repositories\Mobile\TicketInterfaceRepository as MobileTicketInterfaceRepository;
use App\Repositories\Mobile\VehicleRentalInterfaceRepository;
use App\Repositories\OperationCallRepository;
use App\Repositories\OrderInterfaceRepository;
use App\Repositories\Reports\CaptainReports\CaptainPerformanceReportRepository;
use App\Repositories\Reports\CaptainReports\PaymentRepository;
use App\Repositories\ShiftInterfaceRepository;
use App\Repositories\StreamLineInterfaceRepository;
use App\Repositories\ThirdPartyLogisticInterfaceRepository;
use App\Repositories\ThirdPartyLogisticReportInterfaceRepository;
use App\Repositories\ThirdPartyStreamlineInterfaceRepository;
use App\Repositories\TicketInterfaceRepository;
use Illuminate\Support\ServiceProvider;
use App\Repositories\General\AssetInterfaceRepository;
use App\Interfaces\General\GeneralInterface as AdminGeneralInterface;
use App\Repositories\General\GeneralInterfaceRepository as AdminGeneralInterfaceRepository;
use App\Repositories\General\LogsExpireInterfaceRepository;
use App\Repositories\General\GeoSettingInterfaceRepository;
use App\Repositories\General\SystemSettingInterfaceRepository;
use App\Interfaces\General\DeliveryRuleInterface;
use App\Interfaces\General\DesignationInterface;
use App\Interfaces\General\EmployeeInterface;
use App\Interfaces\General\SalesLeadInterface;
use App\Repositories\General\DeliveryRuleInterfaceRepository;
use App\Interfaces\General\SalesManagementInterface;
use App\Interfaces\General\SendableInterface;
use App\Interfaces\General\TeamManagementInterface;
use App\Interfaces\General\VehicleInterface;
use App\Repositories\General\CaptainInterfaceRepository;
use App\Repositories\General\DesignationInterfaceRepository;
use App\Repositories\General\EmployeeInterfaceRepository;
use App\Repositories\General\SalesLeadInterfaceRepository;
use App\Repositories\General\SalesManagementInterfaceRepository;
use App\Repositories\General\SendableInterfaceRepository;
use App\Repositories\General\TeamManagementInterfaceRepository;
use App\Repositories\General\VehicleInterfaceRepository;
use App\Interfaces\General\SalesOperationInterface;
use App\Repositories\General\SalesOperationInterfaceRepository;
use App\Interfaces\General\CaptainTransactionsReportsInterface;
use App\Interfaces\General\RentedVehicleInterface;
use App\Repositories\General\CaptainTransactionsReportsInterfaceRepository;
use App\Interfaces\General\VehicleFleetReportsInterface;
use App\Repositories\General\VehicleFleetReportsInterfaceRepository;
use App\Interfaces\General\Reports\ZoneInterface;
use App\Repositories\General\Reports\ZoneInterfaceRepository;
use App\Interfaces\General\Reports\AreaBasedInterface;
use App\Repositories\General\Reports\AreaBasedInterfaceRepository;
use App\Interfaces\General\Reports\RegionBasedInterface;
use App\Interfaces\Reports\CaptainReports\CaptainCommissionInterface;
use App\Interfaces\Reports\CaptainReports\CaptainDeliveryInterface;
use App\Interfaces\Reports\CaptainReports\CaptainReportDeliveryInterface;
use App\Repositories\General\Reports\RegionBasedInterfaceRepository;
use App\Interfaces\General\Reports\GeographicalInterface;
use App\Repositories\General\Reports\GeographicalInterfaceRepository;
use App\Interfaces\General\Reports\VendorLevelInterface;
use App\Repositories\General\Reports\VendorLevelInterfaceRepository;
use App\Repositories\Reports\CaptainReports\CaptainCommissionInterfaceRepository;
use App\Repositories\Reports\CaptainReports\CaptainDeliveryInterfaceRepository;
use App\Repositories\Reports\CaptainReports\CaptainReportDeliveryInterfaceRepository;
use App\Interfaces\General\Reports\DriverLevelInterface;
use App\Repositories\General\Reports\DriverLevelInterfaceRepository;
use App\Interfaces\General\Reports\HighLevelReportInterface;
use App\Interfaces\Reports\CaptainReports\CaptainPerformanceReportInterface;
use App\Repositories\General\Reports\HighLevelReportInterfaceRepository;
use App\Repositories\Reports\CaptainReports\MakePaymentRepository;
use App\Interfaces\General\Reports\OrderTimeLineReportInterface;
use App\Repositories\General\Reports\OrderTimeLineReportInterfaceRepository;
use App\Interfaces\General\Reports\CaptainKPIReportInterface;
use App\Repositories\General\RentedVehicleRepository;
use App\Repositories\General\Reports\CaptainKPIReportInterfaceRepository;
use App\Interfaces\General\Reports\ThirdPartyReportsInterface;
use App\Interfaces\Reports\ClientReports\SalesReportInterface;
use App\Repositories\General\Reports\ThirdPartyReportsInterfaceRepository;
use App\Repositories\Reports\ClientReports\SalesReportRepository;
use App\Interfaces\General\ClientReports\BranchPerformanceInterface;
use App\Repositories\General\ClientReports\BranchPerformanceRepository;
use App\Interfaces\General\ClientReports\ClientTransactionsInterface;
use App\Repositories\General\ClientReports\ClientTransactionsRepository;
use App\Interfaces\General\ClientReports\ClientOrderInterface;
use App\Repositories\General\ClientReports\ClientOrderRepository;
use App\Interfaces\General\ClientReports\ClientLevelInterface;
use App\Repositories\General\ClientReports\ClientLevelRepository;
use App\Interfaces\General\ClientReports\ClientSaleInterface;
use App\Repositories\General\ClientReports\ClientSaleRepository;
use App\Interfaces\General\ClientReports\ClientOrderCancellationInterface;
use App\Repositories\General\ClientReports\ClientOrderCancellationRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(ShiftInterface::class, ShiftInterfaceRepository::class);
        $this->app->bind(OperationCallInterface::class, OperationCallRepository::class);
        $this->app->bind(ListInterface::class, ListRepository::class);
        $this->app->bind(ClientInterface::class, ClientInterfaceRepository::class);
        $this->app->bind(ThirdPartyLogisticInterface::class, ThirdPartyLogisticInterfaceRepository::class);
        $this->app->bind(OrderInterface::class, OrderInterfaceRepository::class);
        $this->app->bind(TicketInterface::class, TicketInterfaceRepository::class);
        $this->app->bind(ThirdPartyLogisticReportInterface::class, ThirdPartyLogisticReportInterfaceRepository::class);
        $this->app->bind(ClientReportInterface::class, ClientReportInterfaceRepoisotory::class);
        $this->app->bind(StreamLineInterface::class, StreamLineInterfaceRepository::class);
        $this->app->bind(ClientStreamlineInterface::class, ClientStreamlineInterfaceRepository::class);
        $this->app->bind(ThirdPartyStreamlineInterface::class, ThirdPartyStreamlineInterfaceRepository::class);
        $this->app->bind(AssetInterface::class, AssetInterfaceRepository::class);
        $this->app->bind(AdminGeneralInterface::class, AdminGeneralInterfaceRepository::class);
        $this->app->bind(LogsExpireInterface::class, LogsExpireInterfaceRepository::class);
        $this->app->bind(GeoSettingInterface::class, GeoSettingInterfaceRepository::class);
        $this->app->bind(SystemSettingInterface::class, SystemSettingInterfaceRepository::class);
        $this->app->bind(DeliveryRuleInterface::class, DeliveryRuleInterfaceRepository::class);
        $this->app->bind(SalesManagementInterface::class, SalesManagementInterfaceRepository::class);
        $this->app->bind(SalesOperationInterface::class, SalesOperationInterfaceRepository::class);
        $this->app->bind(CaptainTransactionsReportsInterface::class, CaptainTransactionsReportsInterfaceRepository::class);
        $this->app->bind(VehicleFleetReportsInterface::class, VehicleFleetReportsInterfaceRepository::class);

        $this->bindMobileRepositories();
        $this->bindGeneralRepositories();
        $this->bindReportRepositories();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    protected function bindMobileRepositories()
    {
        $this->app->bind(MobileOrderInterface::class, MobileOrderInterfaceRepository::class);
        $this->app->bind(GeneralInterface::class, GeneralInterfaceRepository::class);
        $this->app->bind(VehicleRentalInterface::class, VehicleRentalInterfaceRepository::class);
        $this->app->bind(MobileTicketInterface::class, MobileTicketInterfaceRepository::class);
        $this->app->bind(MobileShiftInterface::class, MobileShiftInterfaceRepository::class);
        $this->app->bind(ReminderInterface::class, ReminderInterfaceRepository::class);
    }

    protected function bindGeneralRepositories()
    {
        $this->app->bind(DashboardInterface::class, DashboardInterfaceRepository::class);
        $this->app->bind(DashboardManagementInterface::class, DashboardManagementInterfaceRepository::class);
        $this->app->bind(OperationalPerformanceInterface::class, OperationalPerformanceInterfaceRepository::class);
        $this->app->bind(AdminOrderInterface::class, AdminOrderInterfaceRepository::class);
        $this->app->bind(PackageInterface::class, PackageInterfaceRepository::class);
        $this->app->bind(SalesLeadInterface::class, SalesLeadInterfaceRepository::class);
        $this->app->bind(TeamManagementInterface::class, TeamManagementInterfaceRepository::class);
        $this->app->bind(DesignationInterface::class, DesignationInterfaceRepository::class);
        $this->app->bind(CaptainInterface::class, CaptainInterfaceRepository::class);
        $this->app->bind(EmployeeInterface::class, EmployeeInterfaceRepository::class);
        $this->app->bind(SendableInterface::class, SendableInterfaceRepository::class);
        $this->app->bind(VehicleInterface::class, VehicleInterfaceRepository::class);
        $this->app->bind(RentedVehicleInterface::class, RentedVehicleRepository::class);
    }

    protected function bindReportRepositories(){
        $this->app->bind(CaptainDeliveryInterface::class, CaptainDeliveryInterfaceRepository::class);
        $this->app->bind(CaptainReportDeliveryInterface::class, CaptainReportDeliveryInterfaceRepository::class);
        $this->app->bind(CaptainCommissionInterface::class, CaptainCommissionInterfaceRepository::class);
        $this->app->bind(ZoneInterface::class, ZoneInterfaceRepository::class);
        $this->app->bind(AreaBasedInterface::class, AreaBasedInterfaceRepository::class);
        $this->app->bind(RegionBasedInterface::class, RegionBasedInterfaceRepository::class);
        $this->app->bind(GeographicalInterface::class, GeographicalInterfaceRepository::class);
        $this->app->bind(VendorLevelInterface::class, VendorLevelInterfaceRepository::class);
        $this->app->bind(DriverLevelInterface::class, DriverLevelInterfaceRepository::class);
        $this->app->bind(HighLevelReportInterface::class, HighLevelReportInterfaceRepository::class);
        $this->app->bind(PaymentInterface::class, PaymentRepository::class);
        $this->app->bind(MakePaymentInterface::class, MakePaymentRepository::class);
        $this->app->bind(CaptainPerformanceReportInterface::class, CaptainPerformanceReportRepository::class);
        $this->app->bind(OrderTimeLineReportInterface::class, OrderTimeLineReportInterfaceRepository::class);
        $this->app->bind(CaptainKPIReportInterface::class, CaptainKPIReportInterfaceRepository::class);
        $this->app->bind(ThirdPartyReportsInterface::class, ThirdPartyReportsInterfaceRepository::class);
        $this->app->bind(SalesReportInterface::class, SalesReportRepository::class);
        $this->app->bind(BranchPerformanceInterface::class, BranchPerformanceRepository::class);
        $this->app->bind(ClientTransactionsInterface::class, ClientTransactionsRepository::class);
        $this->app->bind(ClientOrderInterface::class, ClientOrderRepository::class);
        $this->app->bind(ClientLevelInterface::class, ClientLevelRepository::class);
        $this->app->bind(ClientSaleInterface::class, ClientSaleRepository::class);
        $this->app->bind(ClientOrderCancellationInterface::class, ClientOrderCancellationRepository::class);
    }
    
}
