<?php

namespace App\Repositories\General;

use App\Interfaces\General\GeneralInterface;
use App\Partner;
use App\Account;
use App\OrderPayment;
use App\Order;
use App\ThirdPartyLogisticCompany;
use App\Files_and_remainders;
use App\Transaction;
use App\CompanyInformation;
use App\OrderStatus;
use App\TermsCondition;
use App\PrivacyPolicy;
use App\SocialMediaLink;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GeneralInterfaceRepository implements GeneralInterface
{
    public function getPartnerList($search = null, $perPage)
    {
        return Partner::query()
            ->when($search, function ($query, $search) {
                $query->whereLike(['first_name', 'last_name', 'email'], $search);
            })
            ->with('vehicleType')
            ->paginate($perPage);
    }

    public function getThirdPartyCompanyList(array $filters, int $perPage)
    {
        return ThirdPartyLogisticCompany::query()
            ->with('regions')
            ->when($filters['search'] ?? null, function ($query, $term) {
                $query->whereLike(['name', 'name_ar'], $term);
            })
            ->when($filters['regions'] ?? null, function ($query, $region) {
                $query->whereHas('regions', function ($query) use ($region) {
                    $query->where('quadrants.id', $region);
                });
            })
            ->paginate($perPage);
    }

    public function createPartner(array $data)
    {
        $partner = Partner::create($data);

        $expire['name'] = $data['first_name'] . " " . $data['last_name'];
        $expire['date'] = $data['agreement_expiry_date'];
        $expire['type'] = "Partner";
        $expire['detail'] = "agreement";
        $expire['reference_path'] = "/partners/" . $partner->id . "/edit";
        $expire['reference_id'] = $partner->id;

        Files_and_remainders::create($expire);

        return $partner;
    }

    public function getPartnerById(int $id): Partner
    {
        return Partner::with('vehicleType')
            ->findOrFail($id);
    }

    public function updatePartner(int $id, array $data)
    {
        $partner = Partner::findOrFail($id);
        $partner->update($data);
        return $partner;
    }

    public function updateExpiryReminder(int $id, array $expireData)
    {
        return Files_and_remainders::where('type', 'Partner')
            ->where('reference_id', $id)
            ->update($expireData);
    }

    public function createThirdPartyCompany(array $data)
    {
        return DB::transaction(function () use ($data) {
            $company = ThirdPartyLogisticCompany::create([
                "name" => $data['name'],
                "name_ar" => $data['name_ar'],
                "company_website" => $data['company_website'],
                "office_location" => $data['office_location'],
                "office_number" => $data['office_number'],
                "cr_number" => $data['cr_number'],
                "vat_number" => $data['vat_number'],
                "contact_person" => $data['contact_person'],
                "contact_email" => $data['contact_email'],
                "contact_mobile" => $data['contact_mobile'],
                "possession_id" => $data['possession_id'],
                "agreement_date" => $data['agreement_date'],
                "agreement_valid_until" => $data['agreement_valid_until'],
                "status" => $data['status'] ?? 'ACTIVE'
            ]);

            // Handling attachments if paths are provided in data
            $attachments = [
                'COMPANY_LOGO' => 'company_logo_path',
                'CR_COPY' => 'cr_copy_path',
                'VAT_COPY' => 'vat_copy_path',
                'OWNER_ID' => 'owner_id_path',
                'AGREEMENT' => 'agreement_copy_path',
            ];

            foreach ($attachments as $name => $key) {
                if (isset($data[$key])) {
                    $company->attachments()->create([
                        "name" => $name,
                        "path" => $data[$key]
                    ]);
                }
            }

            $company->regions()->attach($data['regions'] ?? []);
            $company->vehicleTypes()->attach($data['available_vehicle_types'] ?? []);

            if (isset($data['bank']) && $data['bank']) {
                $company->bank()->create([
                    "bank_id" => $data["bank"],
                    "name" => $data["bank_account_name"],
                    "account_number" => $data["bank_account_number"],
                    "iban_number" => $data["bank_iban_number"]
                ]);
            }

            $delivery_price_settings = $company->deliveryPriceSettings()->create([
                "cancellation_charge_applicable" => $data['cancellation_charge_applicable'] ?? false,
                "cancellation_if_order_status_reached_status_id" => json_encode($data['cancellation_if_order_status_reached_status_id'] ?? []),
                "cancellation_charge_strategy" => $data['cancellation_charge_strategy'] ?? null,
                "cancellation_fixed_amount" => $data['cancellation_fixed_amount'] ?? null,
                "cancellation_percentage_of_base_delivery_price" => $data['cancellation_percentage_of_base_delivery_price'] ?? null,
                "cancellation_percentage_of_final_delivery_price" => $data['cancellation_percentage_of_final_delivery_price'] ?? null,
                "return_order_charge_applicable" => $data['return_order_charge_applicable'] ?? false,
                "return_order_if_order_status_reached_status_id" => json_encode($data['return_order_if_order_status_reached_status_id'] ?? []),
                "return_order_charge_strategy" => $data['return_order_charge_strategy'] ?? null,
                "return_order_fixed_amount" => $data['return_order_fixed_amount'] ?? null,
                "return_order_percentage_of_base_delivery_price" => $data['return_order_percentage_of_base_delivery_price'] ?? null,
                "return_order_percentage_of_final_delivery_price" => $data['return_order_percentage_of_final_delivery_price'] ?? null,
            ]);

            foreach ($data['available_vehicle_types'] ?? [] as $key => $available) {
                $rule = $delivery_price_settings->rules()->create([
                    "vehicle_type_id" => $data['vehicle_type'][$key] ?? "",
                    "base_delivery_charge" => $data['base_delivery_charge'][$key] ?? "",
                    "base_delivery_km" => $data['base_delivery_km'][$key] ?? "",
                ]);

                $rule->extraRule()->create([
                    "km_from" => $data['km_from'][$key] ?? "",
                    "km_to" => $data['km_to'][$key] ?? "",
                    "price_per_km" => $data['price_per_km'][$key] ?? ""
                ]);
            }

            return $company;
        });
    }

    public function getThirdPartyCompanyById($id)
    {
        return ThirdPartyLogisticCompany::with([
            'regions',
            'vehicleTypes',
            'bank'
        ])->findOrFail($id);
    }

    public function updateThirdPartyCompany(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $company = ThirdPartyLogisticCompany::findOrFail($id);
            $company->update([
                "name" => $data['name'],
                "name_ar" => $data['name_ar'],
                "company_website" => $data['company_website'],
                "office_location" => $data['office_location'],
                "office_number" => $data['office_number'],
                "cr_number" => $data['cr_number'],
                "vat_number" => $data['vat_number'],
                "contact_person" => $data['contact_person'],
                "contact_email" => $data['contact_email'],
                "contact_mobile" => $data['contact_mobile'],
                "possession_id" => $data['possession_id'],
                "agreement_date" => $data['agreement_date'],
                "agreement_valid_until" => $data['agreement_valid_until'],
                "status" => $data['status'] ?? 'ACTIVE'
            ]);

            // Handling attachments if paths are provided in data
            $attachments = [
                'COMPANY_LOGO' => 'company_logo_path',
                'CR_COPY' => 'cr_copy_path',
                'VAT_COPY' => 'vat_copy_path',
                'OWNER_ID' => 'owner_id_path',
                'AGREEMENT' => 'agreement_copy_path',
            ];

            foreach ($attachments as $name => $key) {
                if (isset($data[$key])) {
                    $company->attachments()->where("name", $name)->delete();
                    $company->attachments()->create([
                        "name" => $name,
                        "path" => $data[$key]
                    ]);
                }
            }

            $company->regions()->sync($data['regions'] ?? []);
            $company->vehicleTypes()->sync($data['available_vehicle_types'] ?? []);

            if (isset($data['bank']) && $data['bank']) {
                $company->bank()->delete();
                $company->bank()->create([
                    "bank_id" => $data["bank"],
                    "name" => $data["bank_account_name"],
                    "account_number" => $data["bank_account_number"],
                    "iban_number" => $data["bank_iban_number"]
                ]);
            }

            $company->deliveryPriceSettings()->delete();

            $delivery_price_settings = $company->deliveryPriceSettings()->create([
                "cancellation_charge_applicable" => $data['cancellation_charge_applicable'] ?? false,
                "cancellation_if_order_status_reached_status_id" => json_encode($data['cancellation_if_order_status_reached_status_id'] ?? []),
                "cancellation_charge_strategy" => $data['cancellation_charge_strategy'] ?? null,
                "cancellation_fixed_amount" => $data['cancellation_fixed_amount'] ?? null,
                "cancellation_percentage_of_base_delivery_price" => $data['cancellation_percentage_of_base_delivery_price'] ?? null,
                "cancellation_percentage_of_final_delivery_price" => $data['cancellation_percentage_of_final_delivery_price'] ?? null,
                "return_order_charge_applicable" => $data['return_order_charge_applicable'] ?? false,
                "return_order_if_order_status_reached_status_id" => json_encode($data['return_order_if_order_status_reached_status_id'] ?? []),
                "return_order_charge_strategy" => $data['return_order_charge_strategy'] ?? null,
                "return_order_fixed_amount" => $data['return_order_fixed_amount'] ?? null,
                "return_order_percentage_of_base_delivery_price" => $data['return_order_percentage_of_base_delivery_price'] ?? null,
                "return_order_percentage_of_final_delivery_price" => $data['return_order_percentage_of_final_delivery_price'] ?? null,
            ]);

            foreach ($data['available_vehicle_types'] ?? [] as $key => $available) {
                $rule = $delivery_price_settings->rules()->create([
                    "vehicle_type_id" => $data['vehicle_type'][$key] ?? "",
                    "base_delivery_charge" => $data['base_delivery_charge'][$key] ?? "",
                    "base_delivery_km" => $data['base_delivery_km'][$key] ?? "",
                ]);

                $rule->extraRule()->create([
                    "km_from" => $data['km_from'][$key] ?? "",
                    "km_to" => $data['km_to'][$key] ?? "",
                    "price_per_km" => $data['price_per_km'][$key] ?? ""
                ]);
            }

            return $company;
        });
    }
    public  function getAccountReceivable(int $captainId)
    {
       return Transaction::with('user')->where('captain_id',$captainId)->orderBy('id','DESC')->first();
    }

    public function getCaptainWalletSummary(int $captainId)
    {
        $account = Account::where('captain_id', $captainId)->first();

        $payment = OrderPayment::select(
            DB::raw('COALESCE(sum(pos_amount),0) AS receivable_amount'),
            DB::raw('COALESCE(sum(given_amount),0) AS given_amount')
        )->where('captain_id', $captainId)->first();

        $order = Order::select(
            DB::raw('COALESCE(sum(delivery_charge),0) AS payable_amount')
        )->where('captain_id', $captainId)->first();

        $fixedCustody = $account?->fixed_custody_amount ?? 0;
        $givenAmount = $payment?->given_amount ?? 0;

        return [
            'captain_id' => $captainId,
            'fixed_custody_amount' => $fixedCustody,
            'given_amount' => $givenAmount,
            'safe_custody_amount' => $fixedCustody - $givenAmount,
            'receivable_amount' => $payment?->receivable_amount ?? 0,
            'account_payable' => $order?->payable_amount ?? 0,
        ];
    }
    public function getCaptainSafeCustodyAmountSummary(int $captainId)
    {
        return Account::where('captain_id',$captainId )->first();
    }
    public  function getTransactionAmountSummary(int $captainId)
    {
        return Transaction::with(['user:id,name'])->where('captain_id', $captainId)
                ->select('id','captain_id','receivable_amount','creditable_amount','status','created_at','created_by')->get();
    }
    public function createCaptainSafeCustodyAmount(array $data)
    {
        return Account::create([
            'captain_id'           => $data['captain_id'],
            'fixed_custody_amount' => $data['fixed_custody_amount'],
            'amount_payable'       => $data['amount_payable'],
            'created_by'           => Auth::id(),
        ]);
    }

    public function createCaptainReceivableAmount(array $data)
    {
        return Transaction::create([
            'captain_id'           => $data['captain_id'],
            'creditable_amount' => $data['creditable_amount'],
            'created_by'           => Auth::id(),
        ]);
    }

    public function getCompanies(array $filters = [],$perPage)
    {
        $query = CompanyInformation::query();

        if (!empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }
        return $query->select([ 'id', 'name', 'mobile_no', 'app_version', 'email', 'vat_id', 'website'])->latest()->paginate($perPage);

    }
    public function getCompanyDetails(int $id)
    {
        return CompanyInformation::with('termsConditions', 'privacyPolicies','socialMediaLinks')->find($id);
    }

    public function updateCompany(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
                $company = CompanyInformation::findOrFail($id);
                // Update company
                $company->update($data['company']);

                // Delete old relations
                TermsCondition::where('company_id', $id)->delete();
                PrivacyPolicy::where('company_id', $id)->delete();
                SocialMediaLink::where('company_id', $id)->delete();

                // Insert new relations
                if (!empty($data['terms'])) {
                    TermsCondition::insert($data['terms']);
                }

                if (!empty($data['policies'])) {
                    PrivacyPolicy::insert($data['policies']);
                }

                if (!empty($data['media'])) {
                    SocialMediaLink::insert($data['media']);
                }

            return $company->load([
                    'termsConditions',
                    'privacyPolicies',
                    'socialMediaLinks'
            ]);
        });
    } 

    
}