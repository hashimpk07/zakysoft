<?php
namespace App\Services\General\SalesManagement;

use App\Interfaces\General\SalesManagementInterface;
use Illuminate\Support\Facades\DB;
use App\Role;
use App\Events\NewClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Client;
use App\Events\ClientBrandCreated;
use App\Events\ClientBrandUpdated;
use App\City;
use App\Zone;
use App\ClientShop;
use Illuminate\Support\Str;
use App\DeliveryType;

class ClientService
{
    public function __construct(private readonly SalesManagementInterface $salesManagementRepository)
    {
    }
    public function getClientList(array $filters, int $perPage)
    {
        return $this->salesManagementRepository->getClientList($filters, $perPage);
    }

    public function createClient($request)
    {
        DB::beginTransaction();
        try {

            $data = $request->validated();
            $data['code'] = $data['code'] ?? uniqid('CL');
            $role = Role::where('name','client')->first();

            // Create User
            $user = $this->salesManagementRepository->createUser([
                "name" => $data['name'],
                "name_ar" => $data['name_ar'] ?? null,
                "email" => $data['email'],
                "password" => bcrypt($data['password']),
                "role_id" => $role->id
            ]);

            // Upload files
            $companyLogo = $request->hasFile('company_logo') ? str_replace('public','storage',$request->file('company_logo')->store('public/client'))
            : null;

            $crRegistration = $request->hasFile('cr_registration') ? str_replace('public','storage',$request->file('cr_registration')->store('public/client'))
            : null;

            $vat = $request->hasFile('vat') ? str_replace('public','storage',$request->file('vat')->store('public/client'))
            : null;

            $ownerIdCard = $request->hasFile('owner_id_card') ? str_replace('public','storage',$request->file('owner_id_card')->store('public/client'))
            : null;

            // Create Client
            $client = $this->salesManagementRepository->createClient([
                "user_id" => $user->id,
                "code" => $data['code'],
                "company_logo_path" => $companyLogo,
                "industry_id" => $data['industry'] ?? null,
                "address" => $data['head_office_location'] ?? null,
                "region_id" => $data['region'] ?? null,
                "mobile_number" => $data['mobile_number'],
                "email" => $data['email'],

                "contact_name" => $data['point_of_contact_name'] ?? null,
                "contact_email" => $data['point_of_contact_email'] ?? null,
                "contact_mobile_no" => $data['point_of_contact_mobile'] ?? null,
                "point_of_contact_position_id" => $data['point_of_contact_position'] ?? null,

                "owner_name" => $data['owner_name'] ?? null,
                "owner_email" => $data['owner_email'] ?? null,

                "on_time_payment" => !empty($data['on_time_payment']) ? 'Yes' : 'No',
                "send_otp_for_prepaid_orders" => !empty($data['send_otp_for_prepaid_orders']) ? 1 : 0,
                "send_otp_for_cod_orders" => !empty($data['send_otp_for_cod_orders']) ? 1 : 0,

                "vat_incl" => $data['vat_incl'],

                "type_of_client_id" => $data['integration_method'] ?? null,
                "source_id" => $data['platform'] ?? null,
                "vehicle" => json_encode($data['vehicle'] ?? []),

                "cr_registration_number" => $data['cr_registration_number'] ?? null,
                "cr_registration_document_path" => $crRegistration,

                "vat_registration_number" => $data['vat_number'] ?? null,
                "vat_registration_document_path" => $vat,

                "owner_id_number" => $data['owner_id_number'] ?? null,
                "owner_id_document_path" => $ownerIdCard,

                "status" => $data['status'] ?? null,
                "start_time" => $data['start_time'] ?? null,
                "end_time" => $data['end_time'] ?? null,

                "agreement_date" => Carbon::parse($data['agreement_date'])->format('Y-m-d'),
                "agreement_expiry_date" => Carbon::parse($data['agreement_expiry_date'])->format('Y-m-d'),

                "created_by" => auth()->id(),
            ]);

            // Bank
            if (!empty($data['bank'])) {

                $this->salesManagementRepository->createBank($client, [
                    "bank_id" => $data['bank'],
                    "name" => $data['account_name'],
                    "account_number" => $data['account_number'],
                    "iban_number" => $data['iban_number']
                ]);
            }
            // Commission
            if (!empty($data['commission_rule_id'])) {
                $this->salesManagementRepository->attachCommission($client,$data['commission_rule_id']);
            }
            // Documents
            if ($request->hasFile('documents')) {
                $documents = [];
                foreach ($request->file('documents') as $key => $doc) {
                    $documents[] = [
                        'name' => $request->documents_name[$key] ?? 'Other Document',
                        'path' => str_replace('public','storage',$doc->store('public/client'))
                    ];
                }
                $this->salesManagementRepository->createAttachments($client,$documents);
            }
            // Fallback rules
            $this->salesManagementRepository->createFallbackRule($client,[
                'delivery_charge_rule_id' => $request->delivery_charge_rule > 0 ? $request->delivery_charge_rule : null,
                'delivery_cancellation_charge_rule_id' => $request->delivery_cancellation_charge_rule > 0 ? $request->delivery_cancellation_charge_rule : null,
                'delivery_return_rule_id' => $request->delivery_return_rule > 0 ? $request->delivery_return_rule : null
            ]);

            // relations
            $user->employeeClient()->sync($client);
            $user->assignRole($role->id);
            DB::commit();
            NewClient::dispatch($client);
            return $client;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getClient($client)
    {
        return $this->salesManagementRepository->getClientData($client);
    }

    public function updateClient($request, $clientId)
    {
       
        $data = $request->validated();
        $client = Client::findOrFail($clientId);
        $updateData = [
            'code' => $data['code'] ?? $client->code,
            'mobile_number' => $data['mobile_number'] ?? $client->mobile_number,
            'industry_id' => $data['industry'] ?? $client->industry_id,
            'address' => $data['head_office_location'] ?? $client->address,
            'region_id' => $data['region'] ?? $client->region_id,
            'contact_name' => $data['point_of_contact_name'] ?? $client->contact_name,
            'contact_email' => $data['point_of_contact_email'] ?? $client->contact_email,
            'contact_mobile_no' => $data['point_of_contact_mobile'] ?? $client->contact_mobile_no,
            'point_of_contact_position_id' => $data['point_of_contact_position'] ?? $client->point_of_contact_position_id,
            'owner_name' => $data['owner_name'] ?? $client->owner_name,
            'owner_email' => $data['owner_email'] ?? $client->owner_email,
            'on_time_payment' => isset($data['on_time_payment']) && $data['on_time_payment'] == 'Yes' ? 'Yes' : 'No',
            'send_otp_for_prepaid_orders' => isset($data['send_otp_for_prepaid_orders']) ? 1 : 0,
            'send_otp_for_cod_orders' => isset($data['send_otp_for_cod_orders']) ? 1 : 0,
            'vat_incl' => $data['vat_incl'] ?? $client->vat_incl,
            'type_of_client_id' => $data['integration_method'] ?? $client->type_of_client_id,
            'source_id' => $data['platform'] ?? $client->source_id,
            'cr_registration_number' => $data['cr_registration_number'] ?? $client->cr_registration_number,
            'vat_registration_number' => $data['vat_number'] ?? $client->vat_registration_number,
            'owner_id_number' => $data['owner_id_number'] ?? $client->owner_id_number,
            'status' => $data['status'] ?? $client->status,
            'agreement_date' => isset($data['agreement_date'])
                ? Carbon::parse($data['agreement_date'])->format('Y-m-d')
                : $client->agreement_date,

            'agreement_expiry_date' => isset($data['agreement_expiry_date'])
                ? Carbon::parse($data['agreement_expiry_date'])->format('Y-m-d')
                : $client->agreement_expiry_date,
        ];

        if (isset($data['vehicle'])) {
            $updateData['vehicle'] = json_encode($data['vehicle']);
        }

        if ($request->hasFile('company_logo')) {
            $updateData['company_logo_path'] =
                str_replace('public', 'storage',
                    $request->file('company_logo')->store('public/client'));
        }

        if ($request->hasFile('cr_registration')) {
            $updateData['cr_registration_document_path'] =
                str_replace('public', 'storage',
                    $request->file('cr_registration')->store('public/client'));
        }

        if ($request->hasFile('vat')) {
            $updateData['vat_registration_document_path'] =
                str_replace('public', 'storage',
                    $request->file('vat')->store('public/client'));
        }

        if ($request->hasFile('owner_id_card')) {
            $updateData['owner_id_document_path'] =
                str_replace('public', 'storage',
                    $request->file('owner_id_card')->store('public/client'));
        }

        $client = $this->salesManagementRepository
        ->updateClient($client, $updateData);


        $user = $client->user;

        $userData = [
            'name' => $data['name'] ?? $user->name,
            'name_ar' => $data['name_ar'] ?? $user->name_ar,
        ];

        if (!empty($data['password'])) {
            $userData['password'] = Hash::make($data['password']);
        }

        $user->update($userData);

        if ($request->hasFile('documents')) {

             foreach ($client->attachments as $doc) {
        Storage::delete(str_replace('storage/', 'public/', $doc->path));
    }

    $client->attachments()->delete();
            $docs = [];

            foreach ($request->file('documents') as $key => $document) {

                $docs[] = [
                    'name' => $data['documents_name'][$key] ?? 'Other Document',
                    'path' => str_replace(
                        'public',
                        'storage',
                        $document->store('public/client')
                    )
                ];
            }

            $this->salesManagementRepository
                ->createAttachments($client, $docs);
        }

        if (!empty($data['bank'])) {

            $this->salesManagementRepository
                ->createBank($client, [
                    'bank_id' => $data['bank'],
                    'name' => $data['account_name'],
                    'account_number' => $data['account_number'],
                    'iban_number' => $data['iban_number']
                ]);
        }

    

        if (!empty($data['commission_rule_id'])) {

            $this->salesManagementRepository
                ->attachCommission($client, $data['commission_rule_id']);
        }



        if (
            isset($data['delivery_charge_rule']) ||
            isset($data['delivery_cancellation_charge_rule']) ||
            isset($data['delivery_return_rule'])
        ) {

            $this->salesManagementRepository
                ->createFallbackRule($client, [
                    'delivery_charge_rule_id' => $data['delivery_charge_rule'] ?? null,
                    'delivery_cancellation_charge_rule_id' => $data['delivery_cancellation_charge_rule'] ?? null,
                    'delivery_return_rule_id' => $data['delivery_return_rule'] ?? null
                ]);
        }

        return $client->load(['attachments','bank','commission','fallbackRule']);
    }
    public function updateClientNotes($clientId, array $data)
    {
        $client = Client::findOrFail($clientId);
        return $this->salesManagementRepository->updateClientNotes($client, $data);
    }

    public function getClientDetails($id, array $filters,$perPage)
    {
        return $this->salesManagementRepository->getClientDetails($id, $filters,$perPage);
    }

    public function createBrand($request)
    {
        $logoPath = null;

        if ($request->hasFile('logo')) {
            $logoFile = $request->file('logo');
            $path = $logoFile->store('public/brand_logos');
            $logoPath = str_replace('public', 'storage', $path);
        }

        $clientBrand = $this->salesManagementRepository->createBrand([
            'name_en' => $request->name_en,
            'name_ar' => $request->name_ar,
            'logo' => $logoPath,
            'client_id' => $request->client_id,
        ]);

        ClientBrandCreated::dispatch($clientBrand);
        return $clientBrand;
    }

    public function getBrandDetails($id)
    {
        return $this->salesManagementRepository->getBrandDetails($id);
    }

    public function updateBrand($request, $id)
    {
        $data = [
            'name_en' => $request->name_en,
            'name_ar' => $request->name_ar,
        ];

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $path = $file->store('public/brand_logos');
            $data['logo'] = str_replace('public', 'storage', $path);
        }
        $updatedBrand = $this->salesManagementRepository->updateBrand($id, $data);
        ClientBrandUpdated::dispatch($updatedBrand);
        return $updatedBrand;
    }

    public function createShop($request)
    {
        $coordinates = explode(',', $request->location);

        $lat = trim($coordinates[0]);
        $long = trim($coordinates[1]);

        $city = City::findByLatLong($lat, $long);

        if (!$city) {
            throw new \Exception("No city found for coordinates");
        }

        $zone = Zone::where('city_id', $city->id)->active()->first();

        if (!$zone) {
            throw new \Exception("No active zone found for city");
        }

        $data = [
            'client_id' => $request->client_id,
            'name' => strtoupper($request->name),
            'app_name' => $request->app_name,
            'location' => $request->location,
            'address' => $request->address,
            'shop_admin' => $request->shop_admin,
            'shop_admin_mobile' => $request->shop_admin_mobile,
            'shop_email_id' => $request->shop_email_id,
            'zone_id' => $request->zone_id,
            'partner_id' => $request->partner_id,
            'auto_assignable' => $request->auto_assignable,
            'active_at' => $request->activation_date ? Carbon::createFromFormat('m/d/Y', $request->activation_date)->format('Y-m-d') : null,
            'status' => $request->status,
            'created_by' => auth()->id(),
            'client_brand_id' => $request->client_brand_id,

            'express_time' => $request->express_delivery_time,
            'express_auto_assign_rule_id' => $request->auto_assign_rule_for_express,
            'schedule_auto_assign_rule_id' => $request->auto_assign_rule_for_schedule,

            'verify_captain_reached_shop' => $request->verify_captain_reached_shop ?? 0,
            'captain_reached_shop_distance' => $request->captain_reached_shop_distance,

            'verify_captain_reached_destination' => $request->verify_captain_reached_destination ?? 0,
            'reached_destination_distance' => $request->reached_destination_distance,

            'verify_captain_reached_pickup_point' => $request->verify_captain_reached_pickup_point ?? 0,
            'reached_pickup_point_distance' => $request->reached_pickup_point_distance,

            'delivery_price_rule_id' => $request->delivery_price_rule_id,
            'cancellation_rule_id' => $request->cancellation_rule_id,
            'delivery_order_return_charge_id' => $request->delivery_order_return_charge_id,
            'delivery_charge_based_on' => $request->delivery_charge_based_on,
        ];

        $shop = $this->salesManagementRepository->createShop($data);

        if (in_array(2, $request->delivery_type ?? [])) {
            $slots = [];
            foreach ($request->schedule_from_hours ?? [] as $key => $value) {
                $slots[] = [
                    'start_time' => Carbon::parse(sprintf('%02d:%02d %s', $request->schedule_from_hours[$key], $request->schedule_from_minute[$key], $request->schedule_from_meridiem[$key]))->format('H:i'),
                    'end_time' => Carbon::parse(sprintf('%02d:%02d %s', $request->schedule_to_hours[$key], $request->schedule_to_minute[$key], $request->schedule_to_meridiem[$key]))->format('H:i')
                ];
            }
            $this->salesManagementRepository->createTimeSlots($shop, $slots);
        }
        $this->salesManagementRepository->attachDeliveryTypes($shop, $request->delivery_type ?? []);
        if ($request->delivery_charge_based_on == 'zone') {
            foreach ($request->zones ?? [] as $zoneName) {
                $zone = Zone::where('name', $zoneName)->first();
                if ($zone) {
                    $this->salesManagementRepository->createZoneCharge([
                        'client_shop_id' => $shop->id,
                        'zone_id' => $zone->id,
                        'quadrant_id' => $zone->quadrant,
                        'region_id' => $zone->region_id,
                        'created_by' => auth()->id()
                    ]);
                }
            }
        }
        if ($request->delivery_charge_based_on == 'radius') {

            foreach ($request->radius_from ?? [] as $key => $from) {

                $this->salesManagementRepository->createRadiusCharge([
                    'client_shop_id' => $shop->id,
                    'from_km' => $from,
                    'to_km' => $request->radius_to[$key],
                    'delivery_charge' => $request->radius_charge_per_km[$key]
                ]);
            }
        }
        return $shop;
    }
    public function getShopDetails(int $id)
    {
        $shop = $this->salesManagementRepository->getShopDetails($id);

        if (!$shop) {
            throw new \Exception("Client shop not found");
        }

        return $shop->load([
            'shopZones',
            'deliveryTypes',
            'timeSlots',
            'shopRadius',
            'dispatchRuleForExpress',
            'dispatchRuleForSchedule',
            'deliveryChargeRule',
            'cancellationRule',
            'orderReturnRule',
            'brand',
            'client.user'
        ]);
    }

    public function updateShop(ClientShop $shop, $request)
    {
        DB::beginTransaction();
        try {
            $data = [
                'client_id' => $request->client_id,
                'name' => strtoupper($request->name),
                'app_name' => $request->app_name,
                'location' => $request->location,
                'address' => $request->address,
                'shop_admin' => $request->shop_admin,
                'shop_admin_mobile' => $request->shop_admin_mobile,
                'shop_email_id' => $request->shop_email_id,
                'zone_id' => $request->zone_id,
                'partner_id' => $request->partner_id,
                'auto_assignable' => $request->auto_assignable,
                'active_at' => $request->activation_date
                    ? Carbon::createFromFormat('m/d/Y', $request->activation_date)->format('Y-m-d')
                    : null,
                'status' => $request->status,
                'client_brand_id' => $request->client_brand_id,

                'express_time' => $request->express_delivery_time,
                'express_auto_assign_rule_id' => $request->auto_assign_rule_for_express,
                'schedule_auto_assign_rule_id' => $request->auto_assign_rule_for_schedule,

                'verify_captain_reached_shop' => $request->verify_captain_reached_shop ?? 0,
                'captain_reached_shop_distance' => $request->captain_reached_shop_distance,

                'verify_captain_reached_destination' => $request->verify_captain_reached_destination ?? 0,
                'reached_destination_distance' => $request->reached_destination_distance,

                'verify_captain_reached_pickup_point' => $request->verify_captain_reached_pickup_point ?? 0,
                'reached_pickup_point_distance' => $request->reached_pickup_point_distance,

                'delivery_price_rule_id' => $request->delivery_price_rule_id,
                'cancellation_rule_id' => $request->cancellation_rule_id,
                'delivery_order_return_charge_id' => $request->delivery_order_return_charge_id,
                'delivery_charge_based_on' => $request->delivery_charge_based_on,
            ];

            $this->salesManagementRepository->updateShop($shop, $data);
            $this->salesManagementRepository->syncDeliveryTypes($shop, $request->delivery_type);

            if (in_array(2, $request->delivery_type ?? [])) {

                $this->salesManagementRepository->deleteTimeSlots($shop->id);
                $slots = [];
                foreach ($request->schedule_from_hours ?? [] as $key => $value) {
                    $slots[] = [
                        'start_time' => Carbon::parse(sprintf('%02d:%02d %s', $request->schedule_from_hours[$key], $request->schedule_from_minute[$key], $request->schedule_from_meridiem[$key]))->format('H:i'),
                        'end_time' => Carbon::parse(sprintf('%02d:%02d %s', $request->schedule_to_hours[$key], $request->schedule_to_minute[$key], $request->schedule_to_meridiem[$key]))->format('H:i')
                    ];
                }
                $this->salesManagementRepository->updateTimeSlot($shop, $slots);
            }


            if ($request->delivery_charge_based_on == 'zone') {

                $this->salesManagementRepository->deleteRadiusCharges($shop->id);
                $this->salesManagementRepository->deleteZoneCharges($shop->id);

                foreach ($request->zones ?? [] as $key => $zoneName) {

                    $zone = Zone::where('name', $zoneName)->first();

                    if ($zone) {

                        $this->salesManagementRepository->createZoneCharge([
                            'client_shop_id' => $shop->id,
                            'zone_id' => $zone->id,
                            'quadrant_id' => $zone->quadrant,
                            'region_id' => $zone->region_id,
                            'express_delivery_charge' => $request->express_delivery[$key] ?? 0,
                            'scheduled_delivery_charge' => $request->schedule_delivery[$key] ?? 0,
                            'created_by' => auth()->id()
                        ]);
                    }
                }
            }

            if ($request->delivery_charge_based_on == 'radius') {

                $this->salesManagementRepository->deleteZoneCharges($shop->id);
                $this->salesManagementRepository->deleteRadiusCharges($shop->id);

                $this->salesManagementRepository->createRadiusCharge([
                    'client_shop_id' => $shop->id,
                    'from_km' => 0,
                    'to_km' => $request->base_radius,
                    'delivery_charge' => $request->base_price
                ]);

                foreach ($request->radius_from ?? [] as $key => $from) {

                    $this->salesManagementRepository->createRadiusCharge([
                        'client_shop_id' => $shop->id,
                        'from_km' => $from,
                        'to_km' => $request->radius_to[$key],
                        'delivery_charge' => $request->radius_charge_per_km[$key]
                    ]);
                }
            }

            DB::commit();

            return $shop;

        } catch (\Exception $e) {

            DB::rollBack();

            throw $e;
        }
    }

    public function createDemoClient($validated)
    {
        return DB::transaction(function () use ($validated) {

            $password = Str::random(8);

            $user = $this->salesManagementRepository->createUser([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => bcrypt($password),
                'role_id' => 2,
            ]);

            $client = $this->salesManagementRepository->createClient([
                'user_id' => $user->id,
                'code' => 'CL' . rand(1000,9999),
                'email' => $validated['email'],
                'mobile_number' => '0000000000',
                'status' => $validated['status'],
                'source_id' => 14
            ]);

            $client->employee()->attach($user);

            $user->assignRole($user->role_id);

            $shop = $this->salesManagementRepository->createShop([
                'client_id' => $client->id,
                'name' => $validated['name'].' Shop',
                'address' => 'Shop Address',
                'status' => 'active',
                'created_by' => auth()->id(),
                'zone_id' => 0,
                'active_at' => now(),
                'express_time' => 60
            ]);

            $shop->deliveryTypes()->attach(DeliveryType::EXPRESS);

            return [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $password,
                'shop_id' => $shop->id,
                'status' => $client->status
            ];
        });
    }

    public function importClientShop($clientId, $file)
    {
        return $this->salesManagementRepository->importClientShop($clientId,auth()->id(),$file);
    }
}