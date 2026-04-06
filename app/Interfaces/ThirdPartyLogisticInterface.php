<?php

namespace App\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Captain;
interface ThirdPartyLogisticInterface
{
    public function getCaptains(int $userId);
    public function getOrderStatus();
    public function getEmployees(int $companyId, array $filters, int $perPage): LengthAwarePaginator;
    public function getDashboardCounts(array $filters, int $companyId);
    public function changeEmployeeStatus(int $employeeId);
    public function changeVehicleIdStatus(int $vehicleId);
    public function getVehicle(int $vehicleId);
    public function updateVehicle(array $data,int $id, int $userId);
    public function updateExpireData(string $detail, string $date, string $name, int $id);
    public function assignVehicleToCaptain(int $vehicleId,int $captainId, int $userId);
    public function createVehicle(array $request,int $companyId,int $employeedId);
    public function getOwnerData(int $ownerId);
    public function createCaptain($request,array $captainData,int $userId);
    public function getCaptainData(int $captainId);
    public function updateCaptain(Captain $captain, array $validated, array $captainData,int $userId);
    public function findOrderWithRelations(int $id, int $companyId);
    public function getCaptainDetailStats($id, $fromDate = null, $toDate = null);
    public function getCaptainShiftsPaginated(int $captainId, int $perPage = 10);
    public function getCaptainOrders(int $captainId, int $perPage = 25);
    public function companyEarningList($companyId,$perPage,$filters);    
    public function getVehiclesFor3PL($companyId, $type = null, $assigned = true);
    public function getOrderPayment(int $id);
    public function getPaymentCaptain($companyId);
    public function getReconciliationCaptain($companyId);
    public function captainPaidBy(array $filters);
    public function createCaptainCommission(Captain $captain, array $data);
    public function createCaptainCommissionConfirmationPayment(array $data);

    public function getOrderCounts($companyId, $status);

}
