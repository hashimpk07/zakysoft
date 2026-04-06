<?php

namespace App\Jobs;

use App\SalesReport;
use App\GeneralExport;
use App\OrderStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use DateTime;
use Exception;
use App\Order;

class CreateSalesReportExport implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private GeneralExport $export,
        private string $exportFileName,
        private int $page = 1,
        private  $total_page,
        private  $request
   )
   {

       $this->export = GeneralExport::find($this->export->id);
      
   
   }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $captain = $this->request['request_captain'];
        $client = $this->request['request_client'];
        if($this->page == 1){
            $columns = $this->getColumns($captain ,$client);   
        }
        $path = 'general_exports/';
        
        $orders = SalesReport::getOrdersforExport(false,$this->request);
  
        $filesystemAdapter = Storage::disk('public');
        
        if($this->page == 1) {
            $fileName =  Carbon::now()->timestamp . '-' . $this->exportFileName . '-' . $this->export->created_by . '.csv';

            // add the headers only on the first run of this job... on subsequent runs, only append the data
            $filesystemAdapter->append($path.$fileName, implode(',', $columns) . PHP_EOL);
            $this->export->update([
                'file' => $fileName
            ]);
            
        } else {
            $fileName = $this->export->file;
        }

        
        if($this->export->status !== 'processing') {
            $this->export->update([
                'status' => 'processing',
                'status_message' => "Job {$this->page} in export processing started"
            ]);
        } elseif($this->export->status === 'processing') {
            $this->export->update([
                'status_message' => "Job {$this->page} in export processing started"
            ]);
        }

        $fileResource = fopen($filesystemAdapter->path($path.$fileName), 'a+');

        if ($client && $captain) {
            foreach ($orders as $order) {
                $paymentMode = $order->order_payment_mode ?? '';
                $onTimePayment = $order->on_time_payment;
                $billAmount = $order->amount;
                $deliveryCharge = (float) $order->delivery_charge;
                $deliveryChargeIncl = $order->delivery_charge_incl;
                $vat_incl = $order->vat_incl;
                $vat_rate = $order->vat_rate;
                
                if ($vat_incl == 'No') {
                    $percent = round(($vat_rate / 100) * $deliveryCharge);
                    $deliveryCharge = (float) $deliveryCharge + (float) $percent;
                } else {
                    $percent = round(($vat_rate / 100) * $deliveryCharge);
                    $deliveryCharge = (float) $deliveryCharge - (float) $percent;
                }
                
                $bank = 0;
                $cash = 0;
                $balance = 0;
                $tot = 0;
                if ($paymentMode == 'By Cash') {
                    $cash = $billAmount;
                } elseif ($paymentMode == 'By POS') {
                    $bank = $billAmount;
                } elseif ($paymentMode == 'Both') {
                    $bank = $order->pos_amount;
                    $cash = $order->cash;
                }
            
                fwrite($fileResource, implode(',', [
                    $order->created_at,
                    sprintf("%'03d", $order->id),
                    $order->client_name ?? '',
                    isset($order->shop_name) ? $order->shop_name : $order->shopname,
                    $order->client_order_id,
                    $order->shop_to_delivery_km,
                    $order->delivery_date,
                    $order->status,
                    $order->captain_name ?? '' ,
                    $order->iqama_number ?? '',
                    $paymentMode,
                    $billAmount, 
                    $cash, 
                    $bank, 
                    $percent,
                    $deliveryCharge
                ]) . PHP_EOL);
            }    
           
       
        } else if ($client && empty($captain)) {
            foreach ($orders as $order) {
                $paymentMode = $order->order_payment_mode ?? '';
                $billAmount = $order->amount;
                $deliveryChargeShow = $order->delivery_charge;
                $indelCharge = 0;
                $vat_incl = $order->vat_incl;
                $vat_rate = $order->vat_rate;
             
                $deliveryCharge = $order->delivery_charge;
                $percent = 0;
                if ($vat_incl == 'No') {
                    $percent = round(($vat_rate / 100) * $deliveryCharge, 2);
                    $deliveryCharge = (float) $deliveryCharge;
                } elseif ($vat_incl == 'Yes') {
                    $percent = round(($deliveryCharge * $vat_rate) / (100 + $vat_rate), 2);
                    $deliveryCharge = (float) $deliveryCharge - (float) $percent;
                }
                
                $deliveryChargeIncl = $order->delivery_charge_incl;
                $onTimePayment = $order->on_time_payment;
                $cash = 0;
                $bank = 0;
                $balance = 0;
                $tot = 0;
                if ($onTimePayment == 'No') {
                    if ($deliveryChargeIncl == 'No') {
                        $cash = (float) $deliveryCharge + (float) $percent;
                        $bank = (float) $deliveryCharge + (float) $percent;
                        $balance = 0;
                        $bal = '-';
                    } else {
                        $balance = (float) $deliveryCharge + (float) $percent;
                        $bal = $balance;
                    }
                } else {
                    $cash = $bank = (float) $billAmount;
                    $balance = (float) $billAmount - ((float) $deliveryCharge + (float) $percent);
                    $bal = $balance;
                }
                if(($order->delivery_payment_mode == Order::ORDER_PAYMENT_MODE_PRE_PAID) || ($order->delivery_payment_mode == Order::ORDER_PAYMENT_MODE_SWIPING_MACHINE)){
                    $balance = 0-$deliveryCharge;
                }
                
                if ($paymentMode == 'By Cash') {
                    $cash = $cash;
                    $bank = 0;
                } elseif ($paymentMode == 'By POS') {
                    $cash = 0;
                    $bank = $bank;
                } elseif ($paymentMode == 'Both') {
                    if ($cash != 0 && $bank != 0) {
                        $cash = (float) $order->cash;
                        $bank = (float) $order->pos_amount;
                    }
                } else {
                    $cash = 0;
                    $bank = 0;
                }
                
                if ($balance < 0) {
                    $bal = '(' . $balance . ')';
                }
                fwrite($fileResource, implode(',', [
                    $order->created_at,
                    sprintf("%'03d", $order->id),
                    $order->captain_name ?? '' ,
                    $order->iqama_number ?? '',
                    $order->client_name ?? '',
                    isset($order->shop_name) ? $order->shop_name : $order->shopname,
                    $order->client_order_id,
                    $order->shop_to_delivery_km,
                    $order->delivery_date,
                    $order->status,
                    $paymentMode,
                    $billAmount,
                    $deliveryChargeShow,
                    $percent,
                    $cash,
                    $bank,
                    $bal
                ]) . PHP_EOL);
            }    
        
        } else if ($captain && empty($client)) {
            foreach ($orders as $order) {
 
                $paymentMode = $order->order_payment_mode ?? '';
                $billAmount = $order->amount;
                $deliveryCharge = $order->delivery_charge;
                $deliveryChargeIncl = $order->delivery_charge_incl;
                $onTimePayment = $order->on_time_payment;
                $pos_amt = 0;
                $debit = 0;
                $bank = 0;
                $cash = 0;
                $balance = 0;
                $bal = 0;
                if ($onTimePayment == 'No' && $paymentMode != '') {
                    if ($deliveryChargeIncl == 'No') {
                        $debit = $billAmount - $deliveryCharge;
                      
                    } else {
                        $debit = $billAmount;
                       
                    }
                }
                if ($paymentMode == 'By Cash') {
                    $cash = $billAmount;
                    $balance = (float) $cash - (float) $debit;
                    $bal = (float) $balance;
                } elseif ($paymentMode == 'By POS') {
                    $bank = $billAmount;
                
                    if ($debit > 0) {
                        if ($debit == $billAmount - $deliveryCharge) {
                            $pos_amt = 0;
                            $balance = (float) $pos_amt - (float) $debit;
                            $bal = $balance;
                        } else {
                            $balance = (float) $bank - (float) $debit;
                            $bal = '(' . $balance . ')';
                        }
                    }
                } elseif ($paymentMode == 'Both') {
                    $cash = $order->cash;
                    $bank = $order->pos_amount;
                    $balance = (float) $cash;
                    $bal = $balance;
                }
                
                
                if ($balance < 0) {
                    $bal = '(' . $balance . ')';
                }
                
                fwrite($fileResource, implode(',', [
                        $order->created_at,
                        sprintf("%'03d", $order->id),
                        $order->captain_name ?? '' ,
                        $order->iqama_number ?? '',
                        $order->client_name ?? '',
                        isset($order->shop_name) ? $order->shop_name : $order->shopname,
                        $order->client_order_id,
                        $order->shop_to_delivery_km,
                        $order->delivery_date,
                        $order->status,
                        $paymentMode,
                        $billAmount,
                        $debit, 
                        $bank,
                        $cash,
                        $bal

                    ]) . PHP_EOL);
            }
          
      
        } else {
            foreach ($orders as $order) {
 
                $paymentMode = $order->order_payment_mode ?? '';
                $onTimePayment = $order->on_time_payment;
                $billAmount = $order->amount;
                $deliveryCharge = (float)$order->delivery_charge;
                $deliveryChargeIncl = $order->delivery_charge_incl;
                $vat_incl = $order->vat_incl;
                $vat_rate = $order->vat_rate;
                $percent = 0;
                if($vat_incl == 'No') {
                    $percent = round(($vat_rate / 100) * $deliveryCharge);
                    $deliveryCharge  = (float)$deliveryCharge + (float) $percent;
                } else {
                    $percent = round(($vat_rate / 100) * $deliveryCharge);
                    $deliveryCharge  = (float)$deliveryCharge - (float) $percent;
                }
                $bank = 0;
                $cash = 0;
                $balance = 0;
                $tot = 0;
                if( $paymentMode == 'By Cash'){
                    $cash = $billAmount;

                } else if( $paymentMode == 'By POS'){
                    $bank =  $billAmount;
                } else if($paymentMode == 'Both'){
                    $bank =  $order->pos_amount;
                    $cash = $order->cash;
                }

            fwrite($fileResource, implode(',', [
                    $order->created_at,
                    sprintf("%'03d", $order->id),
                    $order->client_name,
                    isset($order->shop_name) ? $order->shop_name : $order->shopname,
                    $order->client_order_id,
                    $order->shop_to_delivery_km,
                    $order->delivery_date ,
                    $order->status,
                    $order->captain_name ?? '' ,
                    $order->iqama_number ?? '',
                    $paymentMode,
                    $billAmount,
                    $cash,
                    $bank,
                    $percent,
                    $deliveryCharge

                ]) . PHP_EOL);
            }
           
        }

        fclose($fileResource);

     

        if($this->page == $this->total_page) {
         
            // we are done processing
            $this->export->update([
                'status' => 'processed',
                'status_message' => '',
                'file' => $fileName,
                'is_ready_for_download'=>1,
                'notify'=>1

            ]);
            return;
        }

        // refresh to get current state of export before using it for next job
        $this->export->refresh();
        
       

    }

    public function getColumns($captain = null,$client = null){
        if ($client && $captain) {
            return $columns = [
                'Order Date', 'Order No', 'Client Name','Shop Name','AWB','Dist. b/w Shop & Dlvry','Delivered Date','Order Status','Captain','Iqama No','Payment Type','Bill Amount','Cash','Bank','Vat%','Delivery Charge'
            ];

        }else if ($client && empty($captain)) {
            return $columns = [
                'Order Date','Order No','Captain','Iqama No','Client Name','Shop Name','AWB','Dist. b/w Shop & Dlvry','Delivered Date','Order Status','Payment Mode','Bill Amount','Delivery Charge','Vat%','Cash','Bank','Balance'
            ];

        }else if ($captain && empty($client)) {
            return $columns = [
                'Order Date','Order No','Captain','Iqama No','Client Name','Shop Name','AWB','Dist. b/w Shop & Dlvry','Delivered Date','Order Status','Payment Mode','Bill Amount','Debit','Bank','Cash','Balance'
            ];
        }else {
            return $columns = [
                'Order Date','Order No','Client Name','Shop Name','AWB','Dist. b/w Shop & Dlvry','Delivered Date','Order Status','Captain','Iqama No','Payment Type','Bill Amount','Cash','Bank','Vat%','Delivery Charge'
            ];

        }

    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }
}
