<?php

namespace App\Services\General\Vehicle;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleRentResource extends JsonResource
{
    public function toArray(Request $request)
    {
        // Use the $this->resource to access the model
        $fromDate = Carbon::parse($this->from_date);
        $toDate = Carbon::parse($this->to_date);

        // If you want sequential IDs per page
        $index = $this->resource->index ?? 0; // this will be set later in controller if needed

        return [
            'id' => $index + 1, // sequential id starting from 1
            'year' => $fromDate->format('Y'),
            'month' => $fromDate->format('F'),
            'from_date' => $fromDate->format('d-m-Y'),
            'to_date' => $toDate->format('d-m-Y'),
            'days_count' => $this->rent_count,
            'rent_per_day' => number_format($this->rent_amount, 2),
            'total_amount' => number_format($this->total_rent, 2),
            'received_amount' => number_format($this->settled_amount, 2),
            'due_amount' => number_format($this->sub_total_rent - $this->total_settled, 2),
            'can_receive' => $this->settled_amount ? true : false,
        ];
    }
}
