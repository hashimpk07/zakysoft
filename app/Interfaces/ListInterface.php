<?php
namespace App\Interfaces;

use App\User;
use Closure;

interface ListInterface
{
    public function getCaptains(array $filters = []);
    public function getCountries(bool $hasCaptain = true);
    public function getShiftRules();
    public function getQuadrant(bool $belongsToMe = false);
    public function getCaptainEmploymentType();
    public function getCommissionRule();
    public function getThirdPartyCompanies(bool $active = true);
    public function getOperationEmployees();
    public function getRoles();
    public function getAssetCategory();
    public function getDispatchRules();
    public function getClientSources();
    public function getTimeSlots();
    public function getClients(bool $isActive = true, bool $withName = false);
    public function getClientShops(array $filters = []);
    public function getOrderStatus(?array $statusIds = null);
    public function getAreas();
    public function getZones();
    public function getMainRoles();
    public function getSubRoles();
    public function getVehicles(bool $assigned = false, bool $all = false);
    public function getOrderCancellationReasons($active = true);
    public function getVehicleTypes();
    public function getOrderReportsCaptains(int $clientId);
    public function checkUserHasPendingExport(User $user, string $exportType): bool;
    public function getAutoAssignPriority();
    public function getOwner();
    public function getAsset(?Closure $closure = null);
    public function getPaymentType();
    public function getBankList();
    public function getDesignation();
    public function getMainZone();
    public function getTire();
    public function getCity();

    public function getClientTimeSlots();
    public function getCriticalAssignPriority();
    public function getDeliveryType();
    public function getShiftRule();
    public function getOrderPendingReason();
    public function getPriorityTags();
    public function getSalesLeadCreators();

    public function getLeadStatuses();
    public function getPlatForms();
}
