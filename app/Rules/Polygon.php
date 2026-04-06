<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;


class Polygon implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $value = trim($value);

        if(!$value) {
            return true;
        }
        // POLYGON ((11.26328505493629 75.76579182624312, 11.265086591843286 75.78255373606325, 11.266888117468397 75.79150872898154, 11.279623146266871 75.78430488584354, 11.28512136177018 75.79173527042178, 11.287108642676273 75.79632859907099, 11.286909915204589 75.80125967247236, 11.27531724136854 75.8070688822338, 11.27034880939533 75.80943309550958, 11.266440249236922 75.81064897662179, 11.264452825393349 75.81179730878412, 11.262730380290321 75.80963574236179, 11.259815449701193 75.80787946964278, 11.255177999280605 75.80990593816401, 11.249877964477818 75.80139477037466, 11.248089309125334 75.7993565277774, 11.243775447596292 75.79903469999857, 11.240513704546473 75.80375484075165, 11.236094509934631 75.80439849630923, 11.239882395176977 75.79506549072806, 11.237988458775462 75.7949582148012, 11.239566739973824 75.79098900553251, 11.237778020630145 75.78669796848325, 11.235252750902134 75.77768679068083, 11.234305769054401 75.77639947956757, 11.246195426650516 75.7712502351088, 11.26328505493629 75.76579182624312))
        // replace POLYGON (( with empty string

        $polygon_without_space = Str::of($value)->replace(' ', '');
        $polygon_pre_string = Str::of($polygon_without_space)->substr(0, 9)->lower();
        $polygon_last_string = Str::of($polygon_without_space)->substr(-2, 2)->value();
        $latitudes_longitudes = Str::of($value)
            ->trim()
            ->replace('POLYGON ((', '')
            ->replace('POLYGON((', '')
            ->replace('POLYGON( (', '')
            ->replace(') )', '')
            ->replace(' ))', '')
            ->replace('))', ''); 

        if(strtolower($polygon_pre_string) === "polygon((" && $polygon_last_string === "))") {
            foreach ($latitudes_longitudes as $key => $latitude_longitude) {
                $latitude_longitude = trim($latitude_longitude);
                try {
                    [$latitude, $longitude] = explode(' ', $latitude_longitude);
                } catch (\Throwable $th) {
                    [$latitude, $longitude] = [null, null];
                }
                $fail = Validator::make(
                    [
                        "latitude" => $latitude,
                        "longitude" => $longitude,
                    ],
                    [
                        "latitude" => ['required', new Latitude],
                        "longitude" => ['required', new Longitude]
                    ]
                )->fails();

                if($fail) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'The :attribute must be a polygon in the format of POLYGON((latitude longitude, latitude longitude))';
    }
}
