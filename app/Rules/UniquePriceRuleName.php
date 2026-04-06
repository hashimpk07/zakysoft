<?php

namespace App\Rules;

use App\DeliveryChargeRule;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class UniquePriceRuleName implements Rule
{
    protected $exceptId;

    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct($exceptId = null)
    {
        $this->exceptId = $exceptId;
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
        $query =  DeliveryChargeRule::where('name', $value);
        if ($this->exceptId !== null) {
            $query->where('id', '!=', $this->exceptId);
        }
        return $query->doesntExist();
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'The name is already taken. Please choose a different name';
    }
}
