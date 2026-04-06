<?php
namespace App\Services\PublicApi;

use App\GeneralExport;
use App\Jobs\Captain3plExportJob;
use App\Jobs\ClientHighLevelReportExport;
use App\Jobs\ClientOrderExportJob;
use App\Jobs\CompanyEarningExportJob;
use App\Jobs\CaptainCommissionDetailsReportExportJob;
use App\Jobs\ThirdPartyCaptainPerformanceExportJob;
use App\Jobs\ThirdPartyCaptainWorkingDaysExportJob;
use App\Jobs\NotificationReportExportJob;
use App\jobs\ZoneReportExportJob;
use App\Jobs\CaptainShiftRuleReportExportJob;
use App\Jobs\ClientReportExportJob;
use App\Jobs\ShopDetailsExportJob;
use App\Jobs\PotentialClientExport;
use App\Jobs\CaptainVehicleFeetExportJob;
use App\Jobs\CaptainTransactionExportJob;
use App\Jobs\ZoneDetailedReportExport;
use App\Jobs\ZoneLevelReportExport;
use App\Jobs\AreaLevelReportExport;
use App\Jobs\CaptainConsolidatedCommissionExportJob;
use App\Jobs\CaptainDeliveryReportExportJob;
use App\Jobs\CaptainDeliveryReportV2ExportJob;
use App\Jobs\CaptainKpiPerformanceExportJob;
use App\Jobs\CaptainLowPerformanceExportJob;
use App\Jobs\CaptainShiftReportExportJob;
use App\Jobs\CommissionReportExport;
use App\Jobs\CommissionReportV2Export;
use App\Jobs\RegionBasedReportExport;
use App\Jobs\GeographicalLevelReportExport;
use App\Jobs\VenderLevelReportExport;
use App\Jobs\DriverLevelReportExport;
use App\Jobs\HighLevelReportExport;
use App\Jobs\OrderTimeLineReportExport;
use App\Jobs\CaptainWorkingDaysExportJob;
use App\Jobs\CaptainPerformanceV2ExportJob;
use App\Jobs\CommissionPaymentExportJob;
use App\Jobs\CommissionSalaryPaymentExportJob;
use App\Jobs\ThirdPartyCaptainCommissionDetailReportExport;   
use App\Jobs\ThirdPartyCaptainCommissionReportExport;
use App\Jobs\SpecificCaptainCommissionReportExportJob;
use App\Jobs\ThirdPartyCommissionReportExport;
use App\Jobs\SpecialThirdPartyCommissionReportExport;
use App\Jobs\OrderPerformanceSummaryExport;
use App\Jobs\ClientLevelReportExport;
use App\Jobs\ClientSalesReport;
use App\Jobs\CancellationReportExportJob;
use App\User;
use InvalidArgumentException;


class ExportService
{
    private const REPORT_JOB_MAP = [
        'client_order_report' => 'dispatchClientOrder',
        'client_high_level_report' => 'dispatchClientHighLevelReportExport',
        '3pl_captain_report' => 'dispatch3PLCaptainExport',
        'company_earning_report' => 'dispatchCompanyEarningExport',
        '3pl_captain_commission_details_report' => 'dispatchCaptainCommissionDetailsReportExport',
        '3pl_captain_performance_report' => 'dispatchCaptainPerformanceExport',
        '3pl_captain_working_days_report' => 'dispatchCaptainWorkingDaysExport',
        'notification_list_report' => 'dispatchNotificationReportExport',
        'zone_report' => 'dispatchZoneReportExport',
        'captain_shift_report' => 'dispatchCaptainShiftRuleReportExportJob',
        'client_report' => 'dispatchClientReportExportJob',
        'client_shop_report' => 'dispatchClientShopDetailsExportJob',
        'potential_client_report' => 'dispatchPotentialClientExport',
        'captain_vehicle_feel_report' => 'dispatchCaptainVehicleFeetExportJob',
        'captain_transaction_report' => 'dispatchCaptainTransactionExportJob',
        'zone_detailed_report' => 'dispatchZoneDetailedReportExportJob',
        'zone_based_report' => 'dispatchZoneLevelReportExportJob',
        'area_based_report' => 'dispatchAreaLevelReportExportJob',
        'region_based_report' => 'dispatchRegionBasedReportExportJob',
        'geographical_level_report' => 'dispatchGeographicalLevelReportExport',
        'vendor_level_report' => 'dispatchVenderLevelReportExportJob',
        'driver_level_report' => 'dispatchDriverLevelReportExportJob',
        'high_level_report' => 'dispatchHighLevelReportExportJob',
        'captain_delivery_report' => 'dispatchCaptainDeliveryReportExportJob',
        'captain_delivery_report_v2' => 'dispatchCaptainDeliveryReportExportJobV2',
        'captain_commission_report' => 'dispatchCaptainCommissionReportExportJob',
        'captain_commission_report_v2' => 'dispatchCaptainCommissionReportExportJobV2',
        'captain_kpi_report' => 'dispatchCaptainKPIReportExportJob',
        'captain_consolidated_commission_report' => 'dispatchCaptainConsolidatedReportExportJob',
        'captain_low_performance_report' => 'dispatchCaptainLowPerformanceReportExportJob',
        'shift_captain_reports' => 'dispatchCaptainShiftReportExportJob',
        'driver_level_report'  => 'dispatchDriverLevelReportExportJob',
        'high_level_report'  =>  'dispatchHighLevelReportExportJob',
        'order_performance_report'  =>  'dispatchOrderTimeLineReportExportJob',
        'captain_working_days_report'  =>  'dispatchCaptainWorkingDaysExportJob',
        'captain_performance_report'  =>  'dispatchCaptainPerformanceExportJob',
        'captain_salary_payments' => 'dispatchCaptainSalaryPaymentExportJob',
        'captain_commission_payments' => 'dispatchCommissionPaymentExportJob',
        'third-party-captain-commission-detail-report' => 'dispatchThirdPartyCaptainCommissionDetailReportExport',
        'third-party-captain-commission-report' => 'dispatchThirdPartyCaptainCommissionReportExport',
        'individual-captain-commission-report' => 'dispatchSpecificCaptainCommissionReportExportJob',
        '3pl-general-commission_report' => 'dispatchThirdPartyCommissionReportExportJob',
        '3pl-general-individual-company_commission_report' => 'dispatchSpecialThirdPartyCommissionReportExportJob',
        'branch-performance-summary' => 'dispatchOrderPerformanceSummaryExportJob',
        'client-level-summary' => 'dispatchClientLevelReportExportJob',
        'client-sales-report' => 'dispatchClientSalesReportExportJob',
        'client-cancellation-report' => 'dispatchCancellationReportExportJob',
    ];

    public function excelExport(array $filters, User $user): array
    {
        $reportType = $filters['reportType'] ?? null;

        if (!isset(self::REPORT_JOB_MAP[$reportType])) {
            throw new InvalidArgumentException('Invalid report type');
        }

        $reportTypesRequiringFields = ['high_level_report'];

        if (in_array($reportType, $reportTypesRequiringFields)) {
            $filters['selected_fields'] = $filters['selected_fields'] ?? [];
            if (empty($filters['selected_fields'])) {
                throw new InvalidArgumentException('selected_fields is required for this report type.');
            }
        }

        if ($reportType === 'client_shop_report') {
            if (empty($filters['client_id'])) {
                throw new InvalidArgumentException('client_id is required for client shop report.');
            }
        }

        if ($reportType === 'individual-captain-commission-report') {
            if (!isset($filters['captain_id']) || empty($filters['captain_id'])) {
                throw new InvalidArgumentException('captain_id is required for individual captain commission report.');
            }
        }

        if ($reportType === '3pl-general-individual-company_commission_report') {
            if (!isset($filters['third_party_company_id']) || empty($filters['third_party_company_id'])) {
                throw new InvalidArgumentException('third_party_company_id is required for individual third-party company commission report.');
            }
        }
       

        $export = GeneralExport::create([
            'export_type' => $this->humanReadableType($reportType),
            'status' => 'pending',
            'email_id' => $filters['email'],
            'filters' => $filters,
            'created_by' => $user->id,
        ]);

        $dispatcher = self::REPORT_JOB_MAP[$reportType];
        $this->$dispatcher($export, $filters, $user);

        return [
            'status' => true,
            'message' => 'Export request submitted successfully',
            'export_id' => $export->id,
        ];
    }

    private function dispatchClientOrder(
        GeneralExport $export,
        array $filters,
        User $user
    ): void {
        dispatch(new ClientOrderExportJob(
            $export,
            'client-order-export',
            1,
            $filters,
            $user
        ));
    }

    private function dispatchClientHighLevelReportExport(
        GeneralExport $export,
    ): void {
        dispatch(new ClientHighLevelReportExport($export));
    }

    private function dispatchCompanyEarningExport(
        GeneralExport $export,
        array $filters,
    ): void {

        dispatch(new CompanyEarningExportJob($export));
    }

    private function humanReadableType(string $type): string
    {
        return match ($type) {
            'client_order_report' => 'Client Order Report',
            'high_level_report' => 'High Level Reports',
            '3pl_captain_report' => '3pl Captain Report',
            'company_earning_report' => 'Company Earning Report',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    private function dispatch3PLCaptainExport($export)
    {
        dispatch(new Captain3plExportJob($export));
    }

    private function dispatchCaptainCommissionDetailsReportExport($export, array $filters, )
    {
        dispatch(new CaptainCommissionDetailsReportExportJob($export));
    }

    private function dispatchCaptainPerformanceExport($export)
    {
        dispatch(new ThirdPartyCaptainPerformanceExportJob($export));
    }

    private function dispatchCaptainWorkingDaysExport($export)
    {
        dispatch(new ThirdPartyCaptainWorkingDaysExportJob($export));
    }

    private function dispatchNotificationReportExport($export)
    {
        dispatch(new NotificationReportExportJob($export));
    }

    private function dispatchZoneReportExport($export)
    {
        dispatch(new ZoneReportExportJob($export));
    }

    private function dispatchCaptainShiftRuleReportExportJob(
        GeneralExport $export,
        array $filters,
        User $user
    ): void {
        $jobRequestData = $filters;
        $jobRequestData['shift_rule_id'] = $filters['shift_rule_id'] ?? null;

        dispatch(new CaptainShiftRuleReportExportJob($export, 'captain-shift-report', 1, $jobRequestData));
    }

    private function dispatchClientReportExportJob($export)
    {
        dispatch(new ClientReportExportJob($export));
    }

    private function dispatchClientShopDetailsExportJob($export)
    {
        dispatch(new ShopDetailsExportJob($export));
    }

    private function dispatchPotentialClientExport($export)
    {
        dispatch(new PotentialClientExport($export));
    }

    public function dispatchCaptainVehicleFeetExportJob($export)
    {
        dispatch(new CaptainVehicleFeetExportJob($export));
    }

    public function dispatchCaptainTransactionExportJob($export)
    {
        dispatch(new CaptainTransactionExportJob($export));
    }

    public function dispatchZoneDetailedReportExportJob($export)
    {
        dispatch(new ZoneDetailedReportExport($export));
    }

    public function dispatchZoneLevelReportExportJob($export)
    {
        dispatch(new ZoneLevelReportExport($export));
    }

    public function dispatchAreaLevelReportExportJob($export)
    {
        dispatch(new AreaLevelReportExport($export));
    }

    public function dispatchRegionBasedReportExportJob($export)
    {
        dispatch(new RegionBasedReportExport($export));
    }

    public function dispatchGeographicalLevelReportExport($export)
    {
        dispatch(new GeographicalLevelReportExport($export));
    }

    public function dispatchVenderLevelReportExportJob($export)
    {
        dispatch(new VenderLevelReportExport($export));
    }

    public function dispatchDriverLevelReportExportJob($export)
    {
        dispatch(new DriverLevelReportExport($export));
    }

    public function dispatchHighLevelReportExportJob($export)
    {
        dispatch(new HighLevelReportExport($export));
    }

    public function dispatchCaptainDeliveryReportExportJob($export)
    {
        dispatch(new CaptainDeliveryReportExportJob($export, 'delivery-report', 1, $export->filters ?? []));
    }

    public function dispatchCaptainDeliveryReportExportJobV2($export)
    {
        dispatch(new CaptainDeliveryReportV2ExportJob($export, 'delivery-report', 1, $export->filters ?? []));
    }

    public function dispatchCaptainCommissionReportExportJob($export)
    {
        dispatch(new CommissionReportExport($export, 'commission-report', 1, $export->filters ?? []));
    }

    public function dispatchCaptainCommissionReportExportJobV2($export)
    {
        dispatch(new CommissionReportV2Export($export, 'commission-report-v2', 1, $export->filters ?? []));
    }

    public function dispatchCaptainKPIReportExportJob($export)
    {
        dispatch(new CaptainKpiPerformanceExportJob($export));
    }

    public function dispatchCaptainConsolidatedReportExportJob($export)
    {
        dispatch(new CaptainConsolidatedCommissionExportJob($export));
    }

    public function dispatchCaptainLowPerformanceReportExportJob($export)
    {
        dispatch(new CaptainLowPerformanceExportJob($export));
    }

    public function dispatchCaptainShiftReportExportJob($export)
    {
        dispatch(new CaptainShiftReportExportJob($export));
    }
    public function dispatchOrderTimeLineReportExportJob($export){
        dispatch(new OrderTimeLineReportExport($export));
    }

    public function dispatchCaptainWorkingDaysExportJob($export){
        dispatch(new CaptainWorkingDaysExportJob($export));
    }

    public function dispatchCaptainPerformanceExportJob($export){
        dispatch(new CaptainPerformanceV2ExportJob($export));
    }
    public function dispatchCaptainSalaryPaymentExportJob($export){
        dispatch(new CommissionSalaryPaymentExportJob($export));
    }

    public function dispatchCommissionPaymentExportJob($export){
        dispatch(new CommissionPaymentExportJob($export));
    }

    public function dispatchThirdPartyCaptainCommissionDetailReportExport($export){
        dispatch(new ThirdPartyCaptainCommissionDetailReportExport($export,'third-party-captain-commission-detail-report', 1, $export->filters ?? []));
    }   

    public function dispatchThirdPartyCaptainCommissionReportExport($export){
        dispatch(new ThirdPartyCaptainCommissionReportExport($export,'third-party-captain-commission-report', 1, $export->filters ?? []));
        
    }

    public function dispatchSpecificCaptainCommissionReportExportJob($export){
        dispatch(new SpecificCaptainCommissionReportExportJob($export)) ;  
    }

    public function dispatchThirdPartyCommissionReportExportJob($export){
        dispatch(new ThirdPartyCommissionReportExport($export,'3pl-commission-report', 1, $export->filters ?? []));
    }

    public function dispatchSpecialThirdPartyCommissionReportExportJob($export){
        dispatch(new SpecialThirdPartyCommissionReportExport($export,'3pl_general-individual-company_commission_report', 1, $export->filters ?? []));
    }

    public function dispatchOrderPerformanceSummaryExportJob($export){
        dispatch(new OrderPerformanceSummaryExport($export));
    }

    public function dispatchClientLevelReportExportJob($export){
        dispatch(new ClientLevelReportExport($export));
    }

    public function dispatchClientSalesReportExportJob($export){
        dispatch(new ClientSalesReport($export,'client-sales-report', 1, $export->filters ?? []    ));
    } 
    
    public function dispatchCancellationReportExportJob($export){
        dispatch(new CancellationReportExportJob($export,'cancellation-report', 1, $export->filters ?? []    ));
    }

}
