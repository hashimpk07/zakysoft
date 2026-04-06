<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class Base64Image implements Rule
{

    protected $accepted_formats = [];
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct($accepted_formats = ['image/png', 'image/jpeg'])
    {
        $this->accepted_formats = $accepted_formats;
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
        if(!is_array($value)) 
            $value = [$value];

        $contain_accepted_formate = true;

        foreach ($value as $key => $image) {
            $image = base64_decode($image);
            $f = finfo_open();
            $result = finfo_buffer($f, $image, FILEINFO_MIME_TYPE);
            if(!in_array($result, $this->accepted_formats)) {
                $contain_accepted_formate = false;
                break;
            }
        }
        return $contain_accepted_formate;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'The :attribute must be an image(png, jpeg).';
    }
}
