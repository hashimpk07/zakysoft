<?php
namespace App\Services\ThirdPartyLogistic;

use App\Captain;
use App\CaptainEmploymentType;
use App\Interfaces\ListInterface;
use App\Interfaces\ThirdPartyLogisticInterface;
use App\ShiftStatus;
use App\Vehicle;
use Illuminate\Support\Facades\DB;

class CaptainService
{
    public function __construct(private readonly ThirdPartyLogisticInterface $interface, private readonly ListInterface $listInterface)
    {
    }

    public function getCaptainsByStatus(int $companyId)
    {
        // Shared filters
        $baseFilters = [
            '3pl_company' => $companyId,
            'active'      => true,
        ];

        // Get online captains
        $onlineCaptains = $this->listInterface->getCaptains([ ...$baseFilters, 'online' => true]);

        // Get offline captains
        $offlineCaptains = $this->listInterface->getCaptains([ ...$baseFilters, 'offline' => true]);

        return [
            'online_captains'  => $onlineCaptains->count(),
            'offline_captains' => $offlineCaptains->count(),
        ];
    }
    public function captainStoreFor3PL($request)
    {
        $userId      = auth()->user()->id;
        $captainData = $this->captainData($request);
        return $this->interface->createCaptain($request, $captainData, $userId);
    }
    private function captainData($request)
    {
        $typeOfVehicle = $request->type_of_vehicle;

        if (! is_array($typeOfVehicle)) {
            $typeOfVehicle = $typeOfVehicle ? [$typeOfVehicle] : [];
        }

        $captainInput = [
            'firstname'                  => $request->firstname,
            'phone_number'               => $request->phone_number,
            'iqama_number'               => $request->iqama_number,
            'iqama_expiry_date'          => $request->iqama_expiry_date,
            'licence_number'             => $request->licence_number,
            'licence_expiry_date'        => $request->licence_expiry_date,
            'nationality_id'             => $request->nationality,
            'region_id'                  => $request->region_id,
            'status'                     => $this->getCaptainStatus($request->status),
            'job_type'                   => Captain::JOB_TYPE_THIRD_PARTY,
            'date_of_joining'            => $request->date_of_joining,
            'captain_employment_type_id' => CaptainEmploymentType::THIRD_PARTY,
            'monthly_salary'             => $request->monthly_salary,
            'daily_rent'                 => $request->daily_rent,
            'rent_valid_from'            => $request->rent_valid_from,
            'type_of_vehicle'            => json_encode($typeOfVehicle),
            'commission_rule_id'         => $request->commission_rule_id,
            'code'                       => Captain::generateCaptainId(),
            'created_by'                 => auth()->id(),
        ];
        return $captainInput;
    }
    public function getCaptainDetailsFor3PL(int $captainId)
    {
        return $this->getCaptain($captainId);
    }
    private function getCaptain($captainId)
    {
        return $this->interface->getCaptainData($captainId);
    }
    public function updateCaptainFor3PL($request, int $captainId)
    {
        $captain = $this->getCaptain($captainId);
        if ($captain->vehicle && $captain->status == Captain::STATUS_ACTIVE && ! $request->has('force_ban')) {
            if ($captain->rental() && $rent = $captain->payableVehicleRent()) {
                //throw new Exception("Are you sure to ban {$captain->firstname}. captain rent is pending to pay amount is " . $rent, 460);
            }
        }

        //     $userId = auth()->user()->id;
        //     $captainData = $this->captainData($request);
        //    // return $this->interface->createCaptain($request,$captainData, $userId);
        //     $this->interface->updateCaptain($request,$captainData, $userId,$captainId);

        $userId      = auth()->user()->id;
        $validated   = $request->validated();
        $captainData = $this->captainData($request);
        return $this->interface->updateCaptain($captain, $validated, $captainData, $userId, $request);

    }

    public function getCaptainDetailStats($id, $fromDate, $toDate)
    {
        return $this->interface->getCaptainDetailStats($id, $fromDate, $toDate);
    }

    public function getCaptainShiftsPaginated($captainId, $perPage)
    {
        return $this->interface->getCaptainShiftsPaginated($captainId, $perPage);
    }

    public function getCaptainOrdersPaginated($captainId, $perPage)
    {
        return $this->interface->getCaptainOrders($captainId, $perPage);
    }

    public function updateVehicleKm(ShiftStatus $shiftStatus, array $data)
    {
        return DB::transaction(function () use ($shiftStatus, $data) {
            $shiftStatus->update($data);

            Vehicle::where('id', $shiftStatus->vehicle_id)
                ->update(['current_km' => $data['end_kilometer']]);

            return $shiftStatus->fresh();
        });
    }

    public function getPaymentCaptainList($companyId)
    {
        return $this->interface->getPaymentCaptain($companyId);
    }

    public function getReconciliationCaptainList($companyId)
    {
        return $this->interface->getReconciliationCaptain($companyId);
    }

    public function captainPaidBy($filter)
    {
        return $this->interface->captainPaidBy($filter);
    }

   private function getCaptainStatus(string $status): string
    {
        return match ($status) {
            'Active'   => Captain::STATUS_ACTIVE,
            'Inactive' => Captain::STATUS_INACTIVE,
            'Leave'    => Captain::STATUS_LEAVE,
            'Banned'   => Captain::STATUS_BANNED,
            'Request'  => Captain::STATUS_REQUEST,
            default    => Captain::STATUS_REQUEST,
        };
    }

}
