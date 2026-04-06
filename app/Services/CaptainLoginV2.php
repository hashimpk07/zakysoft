<?php

namespace App\Services;

use App\AccessToken;
use App\Captain;
use App\User;
use Facades\App\Services\ApiServiceV2 as ApiService;

class CaptainLoginV2
{
    private $user;
    private $token;
    public function __construct(User $user, $token)
    {
        $this->user = $user;
        $this->token = $token;
    }

    public function respond()
    {
        $token = $this->user->createToken('API Token')->accessToken;

        $captain_data = Captain::with('user', 'vehicle')->where('user_id', $this->user->id)->first();
        if ($captain_data) {
            $captainActive = Captain::with('user', 'vehicle')->where('user_id', $this->user->id)->where('status', 'Active')->first();
            if ($captainActive) {
                AccessToken::query()
                    ->where('fb_token', $this->token)
                    ->update([
                        'fb_token' => null,
                    ]);

                $access_token_data = AccessToken::where('captain_id', $captain_data->id)->first();

                if ($access_token_data) {
                    $accessToken = AccessToken::where('captain_id', $captain_data->id)->update([
                        'access_token' => $token,
                        'fb_token' => $this->token
                    ]);
                } else {
                    $accessToken = AccessToken::create([
                        'captain_id' => $captain_data->id,
                        'access_token' => $token,
                        'fb_token' => $this->token
                    ]);

                }
                if ($accessToken) {
                    if ($captain_data->vehicle) {
                        $vehicle_id = $captain_data->vehicle->id;
                        $vechile_number = $captain_data->vehicle->number;
                    } else {
                        $vehicle_id = null;
                        $vechile_number = null;
                    }
                    //locationidentifier from user tabel is the device id not the uniqueid.so for traccing current location passing the captain code as its device uniqueid//
                    $data = [
                        'captain' => [
                            'id' => $captain_data->id,
                            'name' => $captain_data->user->name,
                            'email' => $captain_data->user->email,
                            'phone_number' => $captain_data->phone_number,
                            'status' => $captain_data->status,
                            'employee_id' => $captain_data->code
                        ],
                        'vehicle_number' => $vechile_number,
                        'vehicle_id' => $vehicle_id,
                        'access_token' => $token,
                        'rental_captain' => $captain_data->rental(),
                        'sponsored_captain' => $captain_data->sponsored(),
                        'freelancer_captain' => $captain_data->earningCommission(),
                    ];
                    return ApiService::response('Success', __('app/auth.success'), '200', $data);

                } else {
                    return ApiService::response('Error', __('app/auth.token_creation_failed'), '404');
                }
            } else {
                return ApiService::response('Error', __('app/auth.captain_not_active'), '404');
            }
        } else {
            return ApiService::response('Error', __('app/auth.captain_not_found'), '404');
        }
    }
}