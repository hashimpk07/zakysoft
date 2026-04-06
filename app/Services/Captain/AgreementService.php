<?php
namespace App\Services\Captain;

use App\asset;
use App\Captain;
use App\Country;
use App\User;
use App\Vehicle;

class AgreementService
{
    public function generateAgreement($validated)
    {

        $captain = new Captain([
            'code'                => Captain::generateCaptainId(),
            'iqama_number'        => $validated['iqama_number'],
            'iqama_expiry_date'   => $validated['iqama_expiry_date'],
            'licence_number'      => $validated['licence_number'],
            'licence_expiry_date' => $validated['licence_expiry_date'],
            'phone_number'        => $validated['phone_number'],
            'nationality'         => Country::find($validated['nationality'])->name,
            "given_custodyamount" => $validated['given_custodyamount'] ?? 0,
        ]);

        $user = new User([
            'name'  => $validated['firstname'],
            'email' => $validated['email'],
        ]);

       
        $vehicleId = $validated['vehicle'] ?? null;
        $assetIds  = $validated['asset'] ?? [];

        $vehicle = Vehicle::with('vehicleType')->find($vehicleId);

        $assets = Asset::with('category')
            ->whereIn('id', $assetIds)
            ->get();

        $captain->setRelation('user', $user);
        $captain->setRelation('vehicle', $vehicle);
        $captain->setRelation('asset', $assets);

        $pdf = \PDF::loadView('captains.print.agreement', compact('captain'))
                    ->setOptions([
                        'margin-top'=> 0, 
                        'margin-left'=> 0, 
                        'margin-right'=> 0, 
                        'margin-bottom'=> 0
                    ]);


        return base64_encode($pdf->output());

    }

    public function updateAgreement(array $validated, Captain $captain)
    {
        $code    = $captain->code;
        $email   = $captain->user->email;
        $captain = new Captain([
            'code'                => $code,
            'iqama_number'        => $validated['iqama_number'],
            'iqama_expiry_date'   => $validated['iqama_expiry_date'],
            'licence_number'      => $validated['licence_number'],
            'licence_expiry_date' => $validated['licence_expiry_date'],
            'phone_number'        => $validated['phone_number'],
            'nationality'         => Country::find($validated['nationality'])->name,
            "given_custodyamount" => $validated['given_custodyamount'] ?? 0,
        ]);

        $user = new User([
            'name'  => $validated['firstname'],
            'email' => $email,
        ]);

        $vehicleId = $validated['vehicle'] ?? null;
        $assetIds  = $validated['asset'] ?? [];

        $vehicle = Vehicle::with('vehicleType')->find($vehicleId);

        $assets = Asset::with('category')
            ->whereIn('id', $assetIds)
            ->get();

        $captain->setRelation('user', $user);
        $captain->setRelation('vehicle', $vehicle);
        $captain->setRelation('asset', $assets);

        $pdf = \PDF::loadView('captains.print.agreement', compact('captain'))
                    ->setOptions([
                        'margin-top'=> 0, 
                        'margin-left'=> 0, 
                        'margin-right'=> 0, 
                        'margin-bottom'=> 0
                    ]);


        return base64_encode($pdf->output());

    }
}
