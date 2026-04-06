<?php

namespace App\Services\Mobile;

use App\Captain;
use App\Http\Requests\Mobile\UpdateCurrentVersionRequest;
use App\Interfaces\Mobile\GeneralInterface as MobileGeneralInterface;
use App\Order;
use App\OrderStatus;
use App\Request;
use Illuminate\Database\Eloquent\Model;

final class GeneralService
{
    public function __construct(private readonly MobileGeneralInterface $generalInterface)
    {
    }

    public function updateAppVersion(UpdateCurrentVersionRequest $request, int $captainId)
    {
        $payload = ['current_using_app_version' => $request->input('data.current_app_version')];

        if ($request->filled('data.device')) {
            $payload['device'] = $request->input('data.device');
        }

        $captain = Captain::findOrFail($captainId);

        return $this->generalInterface->updateCaptain($captain, $payload);
    }

    public function getVersion($platform = 'android')
    {
        $companyInformation = $this->generalInterface->getCompanyInformation();

        return in_array(strtolower($platform), ['ios']) ? $companyInformation->app_version_ios : $companyInformation->app_version;
    }

    public function getAppInitData(string $platform, string $currentVersion): array
    {
        $companyInformation = $this->generalInterface->getCompanyInformation();
        $isIos = in_array(strtolower($platform), ['ios']);

        $latestVersion = $isIos ? $companyInformation->app_version_ios : $companyInformation->app_version;
        $minSupportedVersion = $isIos ? $companyInformation->min_supported_version_ios : $companyInformation->min_supported_version;

        $updateMessage = '';

        if ($minSupportedVersion && version_compare($currentVersion, $minSupportedVersion, '<')) {
            $updateMessage = __('app/version.force_update', [], 'en'); // Or dynamic message
        } elseif ($latestVersion && version_compare($currentVersion, $latestVersion, '<')) {
            $updateMessage = __('app/version.optional_update', [], 'en');
        }

        return [
            'latestVersion' => $latestVersion,
            'minSupportedVersion' => $minSupportedVersion,
            'updateMessage' => $updateMessage,
        ];
    }

    public function findOrderById(int $id): Model|Order|null
    {
        return $this->generalInterface->findOrderById($id);
    }

    public function findOrderStatusById(int $id): Model|OrderStatus|null
    {
        return $this->generalInterface->findOrderStatusById($id);
    }

    public function getOrderPendingReasonById(int $id): ?string
    {
        return $this->generalInterface->getOrderPendingReasonById($id);
    }

    public function checkAnyShippedOrders(array $orderIds, int $captainId)
    {
        return $this->generalInterface->checkAnyShippedOrders(orderIds: $orderIds, captainId: $captainId);
    }

    public function createCaptainLocationLog(array $data)
    {
        return $this->generalInterface->createCaptainLocationLog($data);
    }

    public function findCaptainById(int $id)
    {
        return $this->generalInterface->findCaptainById($id);
    }

    public function getRecentCaptainLocationLog(int $captain_id)
    {
        return $this->generalInterface->getRecentCaptainLocationLog($captain_id);
    }

    public function checkOrderProofOfPickup(array $orderIds, int $captainId)
    {
        return $this->generalInterface->checkOrderProofOfPickup(orderIds: $orderIds, captainId: $captainId);
    }

    public function checkProofOfPickupEnabled(array $orderIds){
        return $this->generalInterface->checkProofOfPickupEnabled($orderIds);
    }
}
