<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class MaxFileSize implements Rule
{
    protected $maxSize; // Maximum file size in kilobytes
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct($maxSize)
    {
        $this->maxSize = $maxSize;
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
        // Ensure the uploaded file size is less than or equal to the maximum size
        return $value->getSize() <= $this->maxSize * 1024;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return "File must be less than or equal to {$this->maxSize} kilobytes.";
    }
}
