<?php

namespace App\Exports;

use App\CaptainCommissionPayment;
use Maatwebsite\Excel\Concerns\FromCollection;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;


class CommissionPaymentExport implements FromView
{
    use Exportable;

    private $request = [];
    /**
    * @return \Illuminate\Support\Collection
    */

    public function __construct($request)
    {
        $this->request = $request;
    }

    public function view(): View
    {
        ini_set('memory_limit', '-1');
        $payments = CaptainCommissionPayment::with('commission','captain','settledBy','paymentMode')
        ->when($this->request->get('from_date'),function($query,$from_date){
            $query->where('settled_at','>=',$from_date);
        })
        ->when($this->request->get('from_date'),function($query,$from_date){
            $query->where('settled_at','>=',$from_date);
        })
        ->when($this->request->get('captain'),function($query,$captain){
            $query->where('captain_id','=',$captain);
        })
        ->when($this->request->get('paid_by'),function($query,$paid_by){
            $query->where('settled_by','=',$paid_by);
        })
        ->when($this->request->get('region'), function ($query, $region) {
            $query->whereHas('captain.regions.quadrant', function($query) use ($region) {
                $query->where('quadrants.id', $region);
            });
        })
        ->when($this->request->get('payment_type'),function($query,$payment_type){
            $query->where('payment_mode_id','=',$payment_type);
        })
        ->when($this->request->get('invoice_number'),function($query,$invoice_number){
            $query->where('id','=',$invoice_number);
        })
        ->orderBy('id','desc')
        ->get();

        return view('commission-payments.payment-export', [
            'payments' => $payments,
        ]);
    }
}
