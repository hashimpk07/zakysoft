<?php

namespace App\Services;

use App\AccessToken;
use App\Zone;
use DB;

class ApiServiceV2
{
    public function getCaptainID($fbtoken)
    {
        $data = AccessToken::where('fb_token', $fbtoken)->first('captain_id');

        if ($data) {
            return $captain_id = $data->captain_id;
        } else {
            return 0;
        }
    }
    public function captainLocation($data)
    {
        $data_string = json_encode($data);
        $url = config('app.api.url') . "positions/";
        $username = config('app.api.username');
        $password = config('app.api.password');
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_USERPWD => "$username:$password",
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_POSTFIELDS => $data_string,
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
            ),
        ));
        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);
        return $response;
    }
    public function response($status, $msg, $code, $data = null)
    {

        if ($data != null) {

            return response()->json([
                'status' => $status,
                'code' => $code,
                "data" => $data,
                "message" => $msg,
            ],$code);
        } else {
            return response()->json([
                'status' => $status,
                'code' => $code,
                "message" => $msg,
            ],$code);
        }
    }
    public function getClientID($fbtoken)
    {
        $data = AccessToken::where('fb_token', $fbtoken)->first('client_id');

        if ($data) {
            return $client_id = $data->client_id;
        } else {
            return 0;
        }
    }

    public function getZone($location)
    {
        $data = [];
        $id = "";
        $locate = explode(",", $location);
        $lat = $locate[0];
        $lan = $locate[1];
        $latlong = $lat . " " . $lan;
        $Zones = Zone::get();
        foreach ($Zones as $zone) {

            $sqlQuery = DB::raw("SELECT MBRContains(ST_GeomFromText('" . $zone->polygon . "'),ST_GeomFromText('Point(" . $lat . " " . $lan . ")')) as a,name,id From zones");
           
            $results = DB::select($sqlQuery->getValue(DB::connection()->getQueryGrammar()));


            if ($results && empty($id)) {
                foreach ($results as $result) {
                    if ($result->a != null && $result->a != 0) {
                        $id = $zone->id;
                    }
                }
            }
        }

        $zone = Zone::with('region')->where("id", $id)->first();

        return isset($zone->region_id) ? $zone : "";

    }
}
