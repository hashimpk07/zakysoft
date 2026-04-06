<?php
namespace App\Repositories;

use App\Asset;
use App\Captain;
use App\CaptainCommission;
use App\CaptainCommissionPayment;
use App\CaptainCustodyLog;
use App\CaptainDocument;
use App\CaptainSalaryPayment;
use App\CaptainSalaryPaymentDate;
use App\CaptainThirdPartyLogistic;
use App\Files_and_remainders;
use App\Interfaces\ThirdPartyLogisticInterface;
use App\Notifications\ActivationNotify;
use App\Order;
use App\OrderStatus;
use App\Partner;
use App\Services\Firebase\CloudMessage;
use App\ShiftStatus;
use App\ThirdPartyLogisticCompany;
use App\ThirdPartyLogisticCompanyUser;
use App\User;
use App\Vehicle;
use App\VehicleCaptain;
use App\VehicleImage;
use App\VehicleThirdPartyLogistic;
use Carbon\Carbon;
use Facades\App\Services\OrderStatusLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

class ThirdPartyLogisticInterfaceRepository implements ThirdPartyLogisticInterface
{

    public function getCaptains(int $userId)
    {
        $companyId = $this->getCompanyIdFromUser($userId);
        return Captain::query()->select('id')->withName()->active()->belongsTo3pl($companyId)->toBase()->get();
    }
    public function getOrderStatus()
    {
        return OrderStatus::logisticStatuses()->select('id', 'name')->orderBy('priority')->get()->each->setAppends([]);
    }

    protected function getCompanyIdFromUser(int $userId)
    {
        return ThirdPartyLogisticCompanyUser::where('user_id', $userId)->first()->third_party_logistic_company_id;
    }

    public function getEmployees(int $companyId, array $filters, int $perPage): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;
        $query  = User::query()
            ->select('id', 'name', 'email', 'status', 'role_id')
            ->with(['role:id,name'])
            ->thirdPartyEmployee($companyId)
            ->where('type', User::TYPE_3PL_EMPLOYEE)
            ->whereNotIn('role_id', [3])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
        return $query->paginate($perPage)->withQueryString();
    }

    public function changeEmployeeStatus($employeeId)
    {
        $employee         = User::findOrFail($employeeId);
        $employee->status = ($employee->status === 'active') ? 'inactive' : 'active';
        $employee->save();
        return [
            'id'     => $employee->id,
            'name'   => $employee->name,
            'email'  => $employee->email,
            'role'   => $employee->role ? $employee->role->name : null,
            'status' => $employee->status,
        ];
    }

    public function getDashboardCounts(array $filters, int $company_id_3pl)
    {
        [
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ] = $filters;

        $statisticQuery = Order::query()
            ->select(
                'order_statuses.name',
                'order_statuses.id',
                DB::raw('COUNT(*) as count')
            )
            ->leftJoin('order_statuses', 'order_statuses.id', 'orders.status_id')
            ->whereIn('status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CANCEL,
                OrderStatus::RETURN_TO_CLIENT,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
            ])
            ->withinDateRange($startDate, $endDate, 'delivery_date')
            ->belongsTo3pl($company_id_3pl)
            ->groupBy('order_statuses.name', 'order_statuses.id')
            ->orderBy('order_statuses.id')
            ->toBase()
            ->get();

        // Define display name mapping
        $nameMap = [
            OrderStatus::DELIVERED               => 'Delivered',
            OrderStatus::CANCEL                  => 'Canceled',
            OrderStatus::CANCEL_REQUEST_ACCEPTED => 'Canceled',
            OrderStatus::RETURN_TO_CLIENT        => 'Returned to client',
            OrderStatus::CLIENT_RETURN_ACCEPTED  => 'Returned to client',
        ];

        // Default counts to 0 for all display names
        $defaults = [
            'Delivered'          => 0,
            'Canceled'           => 0,
            'Returned to client' => 0,
        ];

        // Sum counts by display name
        $counts = $statisticQuery->reduce(function ($carry, $item) use ($nameMap) {
            $displayName         = $nameMap[$item->id] ?? $item->name;
            $carry[$displayName] = ($carry[$displayName] ?? 0) + $item->count;
            return $carry;
        }, $defaults);

        return collect($counts)->map(fn($count, $name) => [
            'name'  => $name,
            'count' => $count,
        ])->values();
    }

    public function createVehicle(array $data, int $companyId, int $employeedId)
    {

        DB::beginTransaction();
        try {
            // 1. Insert Vehicle
            $vehicleId = Vehicle::insertGetId($data['vehicle']);

            if (! $vehicleId) {
                DB::rollback();
                return false;
            }

            // 2. Assign Captain
            if (! empty($data['vehicle']['assigned_to'])) {

                VehicleCaptain::create([
                    'vehicle_id' => $vehicleId,
                    'captain_id' => $data['vehicle']['assigned_to'],
                    'current_km' => $data['vehicle']['current_km'],
                    'created_by' => $employeedId,
                    'from_date'  => now(),
                ]);
            }

            // 3. Link to 3PL company
            VehicleThirdPartyLogistic::create([
                'third_party_logistic_company_id' => $companyId,
                'vehicle_id'                      => $vehicleId,
            ]);

            // 4. Expiry Alerts
            Files_and_remainders::create([
                'name'           => $data['vehicle']['number'],
                'date'           => $data['vehicle']['rc_book_expiry_date'],
                'type'           => 'Vehicle',
                'detail'         => 'R C Book expire',
                'reference_path' => "/vehicles/{$vehicleId}/edit",
                'reference_id' => $vehicleId,
            ]);

            Files_and_remainders::create([
                'name'           => $data['vehicle']['number'],
                'date'           => $data['vehicle']['insurance_expiry_date'],
                'type'           => 'Vehicle',
                'detail'         => 'Insurance expire',
                'reference_path' => "/vehicles/{$vehicleId}/edit",
                'reference_id' => $vehicleId,
            ]);

            // 5. Upload Multiple Vehicle Images
            if (! empty($data['images'])) {
                foreach ($data['images'] as $fileName) {
                    VehicleImage::create([
                        'vehicle_id' => $vehicleId,
                        'name'       => $fileName,
                        'created_by' => $employeedId,
                    ]);
                }
            }

            // 6. Log Creation
            $vehicle = Vehicle::find($vehicleId);

            OrderStatusLog::logs(
                'Vehicle Creation',
                "New Vehicle {$vehicle->number} Created",
                $employeedId,
            );

            DB::commit();
            return $vehicleId;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }
    public function changeVehicleIdStatus(int $vehicleId)
    {
        $vehicle         = Vehicle::findOrFail($vehicleId); //Onboarding', 'Banned', 'Active'
        $vehicle->status = ($vehicle->status === 'Active') ? 'Banned' : 'Active';
        return $vehicle->save();
    }
    public function getVehicle(int $id)
    {
        return Vehicle::find($id);
    }

    public function updateVehicle(array $data, int $id, int $userId)
    {
        //return Vehicle::where('id', $id)->update($data);

        $vehicle = Vehicle::find($id);
        if (! $vehicle) {
            return null;
        }
        $updated = $vehicle->update($data);
        if ($updated) {
            // Logging moved here
            $content = "Vehicle {$vehicle->number} Updated";
            OrderStatusLog::logs("Vehicle Updated", $content, $userId);
        }

        return $updated ? $vehicle->fresh() : null;
    }

    public function updateExpireData(string $detail, string $date, string $name, int $id)
    {
        $data = [
            "name"           => $name,
            "date"           => $date,
            "type"           => "Vehicle",
            "detail"         => $detail,
            "reference_path" => "/vehicles/$id/edit",
            "reference_id"   => $id,
        ];

        $existing = Files_and_remainders::where("type", "Vehicle")
            ->where("reference_id", $id)
            ->first();

        if ($existing) {
            return $existing->update($data);
        }

        return Files_and_remainders::create($data);
    }

    public function assignVehicleToCaptain(int $vehicleId, int $captainId, int $userId)
    {
        $existingCaptain = Vehicle::where('assigned_to', (int) $captainId)->first();
        $captainData     = Captain::with('user')->find($captainId);
        $module          = 'Vehicle Assigning';

        if ($existingCaptain) {
            $existingCaptain->update(['assigned_to' => null]);
            $vehicle = Vehicle::find((int) $vehicleId);

            $content = 'Vehicle ' . $vehicle->number . ' assigned ' . $captainData->firstname . " " . $captainData->lastname;
            OrderStatusLog::logs($module, $content, $userId);

            if ($vehicle) {
                $vehicle->update(['assigned_to' => (int) $captainId]);

                $exist = VehicleCaptain::where('vehicle_id', (int) $vehicleId)
                    ->where('status', 'Active')
                    ->orderBy('id', 'DESC')
                    ->first();

                if ($exist) {
                    $exist->update(['status' => 'Inactive', 'to_date' => now(), 'detached_by' => $userId]);
                }

                $captain_vehicle = VehicleCaptain::create([
                    'vehicle_id'      => (int) $vehicleId,
                    'captain_id'      => (int) $captainId,
                    'created_by'      => $userId,
                    'from_date'       => now(),
                    'is_rented'       => $captainData->rental(),
                    'rented_valid_at' => $captainData->rental() ? $captainData->rent_valid_from : null,
                    'rent'            => $captainData->rental() ? $captainData->daily_rent : null,
                ]);

                foreach (request()->file('vehicle_images', []) as $key => $vehicle_image) {
                    $captain_vehicle->images()->create([
                        'path' => $this->upload($vehicle_image, 'public/vehicle_image'),
                    ]);
                    return $content;
                }
            }
        } else {

            $vehicle = Vehicle::find((int) $vehicleId);
            $content = 'Vehicle ' . $vehicle->number . ' assigned to ' . $captainData->firstname . " " . $captainData->lastname;
            OrderStatusLog::logs($module, $content, $userId);

            if ($vehicle) {
                $vehicle->update(['assigned_to' => $captainId]);
                $exist = VehicleCaptain::where('vehicle_id', $vehicleId)->where('status', 'Active')->orderBy('id', 'DESC')
                    ->first();
                if ($exist) {
                    $exist->update(['status' => 'Inactive', 'to_date' => now()]);
                }

                $captain_vehicle = VehicleCaptain::create([
                    'vehicle_id'      => $vehicleId,
                    'captain_id'      => $captainId,
                    'created_by'      => $userId,
                    'from_date'       => now(),
                    'is_rented'       => $captainData->rental(),
                    'rented_valid_at' => $captainData->rental() ? $captainData->rent_valid_from : null,
                    'rent'            => $captainData->rental() ? $captainData->daily_rent : null,
                ]);

                foreach (request()->file('vehicle_images', []) as $key => $vehicle_image) {
                    $captain_vehicle->images()->create([
                        'path' => $this->upload($vehicle_image, 'public/vehicle_image'),
                    ]);
                }
                return $content;
            }
        }
        return false;
    }

    public function getOwnerData(int $ownerId)
    {
        return Partner::find($ownerId);
    }

    public function createCaptain($request, $captainInput, $userId)
    {
        return DB::transaction(function () use ($request, $captainInput, $userId) {

            $companyId = ThirdPartyLogisticCompanyUser::where('user_id', $userId)
                ->value('third_party_logistic_company_id');

            $user = User::create([
                'role_id'  => 3,
                'name'     => $request['firstname'],
                'email'    => $request['email'],
                'password' => Hash::make($request['password']),
            ]);
            $user->assignRole($user->role_id);

            $captainInput['user_id'] = $user->id;
            $captain                 = Captain::create($captainInput);

            CaptainThirdPartyLogistic::create([
                'third_party_logistic_company_id' => $companyId,
                'captain_id'                      => $captain->id,
            ]);

            $regions = $request->regions;

            if (is_string($regions)) {
                $regions = explode(',', $regions);
            }

            $captain->regions()->sync($regions);

            CaptainCustodyLog::create([
                'captain_id'     => $captain->id,
                'custody_amount' => $request->maxAmount ?? 100000,
                'given_amount'   => $request->given_custodyamount,
                'created_by'     => $userId,
            ]);

            $this->storeDocuments($captain->id, $request);
            $this->createExpiryRecord('iqama', $captainInput['iqama_expiry_date'], $captain->firstname, $captain->id);
            $this->createExpiryRecord('licence', $captainInput['licence_expiry_date'], $captain->firstname, $captain->id);

            OrderStatusLog::logs(
                "Captain Creation",
                "Captain {$captain->firstname} {$captain->lastname} Created",
                Auth::id()
            );

            if (! empty($request['vehicle'])) {
                $this->assignVehicleToCaptain($request['vehicle'], $captain->id, $userId);
            }

            return $captain;
        });
    }
    private function storeDocuments($captainId, $request)
    {
        $existing = CaptainDocument::where('captain_id', $captainId)->first();

        $data['captain_id'] = $captainId;

        // if ($request->hasFile('vehicle_images')) {
        //     $data['vehicle'] = $this->upload($request->file('vehicle_images'), 'captain_profiles', $existing?->vehicle);
        // }

        if ($request->hasFile('upload_agreement_copy')) {
            $data['agreement'] = $this->upload($request->file('upload_agreement_copy'), 'captain_docs', $existing?->agreement);
        }

        if ($request->hasFile('iqama')) {
            $data['iqama_file_path'] = $this->upload($request->file('iqama'), 'captain_docs', $existing?->iqama_file_path);
        }

        if ($request->hasFile('licence')) {
            $data['license_file_path'] = $this->upload($request->file('licence'), 'captain_docs', $existing?->license_file_path);
        }

        return $existing ? $existing->update($data) : CaptainDocument::create($data);
    }
    private function createExpiryRecord($type, $date, $name, $captainId)
    {
        Files_and_remainders::create([
            'name'           => $name,
            'date'           => $date,
            'type'           => "Captain",
            'detail'         => $type,
            'reference_path' => "/captains/{$captainId}/edit",
            'reference_id' => $captainId,
        ]);
    }

    public function upload(UploadedFile $file, string $folder = 'uploads')
    {
        $path = $file->store($folder, 'public');
        return $path;
    }
    public function getCaptainData(int $id)
    {
        $captain = Captain::with([
            'user:id,name,email',
            'regions:id,name',
            'account',
            'autoAssignPriority',
            'asset.category:id,name',
            'document',
            'convertedBy',
            'createdBy',
            'employmentType:id,name',
            'nationality:id,name',
            'captainVehicle.images',
            'CaptainCustodyLog:id,captain_id,given_amount',
        ])->find($id);

        return $captain;
    }
    public function updateCaptain(
        Captain $captain,
        array $validated,
        array $captainData,
        int $userId
    ) {
        if (empty($validated)) {
            throw new \InvalidArgumentException('Validated data cannot be empty.');
        }

        DB::connection('mysql::write')->beginTransaction();

        try {
            $status = $validated['status'] ?? null;

            $user = $captain->user;

            $user->name = $validated['firstname'];

            if (! empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }

            $user->save();

            if (
                $status === Captain::STATUS_ACTIVE &&
                $captain->status === Captain::STATUS_REQUEST
            ) {
                $captainData['request_converted_at'] = now();
                $captainData['request_converted_by'] = auth()->id();
            }

            $captainData['user_id'] = $user->id;

            $captain->update($captainData);

            $captain->regions()->sync($validated['regions'] ?? []);

            $this->storeDocuments($captain->id, request());

            if (! empty($captainData['iqama_expiry_date'])) {
                $this->createExpiryRecord(
                    'iqama',
                    $captainData['iqama_expiry_date'],
                    $captain->firstname,
                    $captain->id
                );
            }

            if (! empty($captainData['licence_expiry_date'])) {
                $this->createExpiryRecord(
                    'licence',
                    $captainData['licence_expiry_date'],
                    $captain->firstname,
                    $captain->id
                );
            }

            $assets = $validated['asset'] ?? [];
            $assets = is_array($assets) ? $assets : explode(',', $assets);
            $assets = array_filter($assets);

            Asset::where('captain_id', $captain->id)
                ->update(['captain_id' => null]);

            foreach ($assets as $assetId) {
                Asset::where('id', (int) $assetId)
                    ->update(['captain_id' => $captain->id]);
            }

            if (! empty($validated['vehicle'])) {
                $this->assignVehicleToCaptain(
                    $validated['vehicle'],
                    $captain->id,
                    $userId
                );
            }

            if (
                $captain->vehicle &&
                $status === Captain::STATUS_BANNED
            ) {
                $captain->vehicle->detachCaptain();

                if ($captain->isOnline()) {
                    $this->closeCaptainShift($captain);
                }
            }

            if (
                $status === Captain::STATUS_ACTIVE &&
                $captain->status !== Captain::STATUS_ACTIVE &&
                ! empty($captain->phone_number)
            ) {
                Notification::route('sms_api', $captain->phone_number)
                    ->notify(new ActivationNotify());
            }

            OrderStatusLog::logs(
                'Captain Updated',
                "Captain {$captain->firstname} {$captain->lastname} updated",
                auth()->id()
            );

            DB::commit();

            return $captain->fresh();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function closeCaptainShift($captain)
    {
        $captain->currentShift->terminate();
        $metadata = \App\Reminder::getNotificationMetadata(\App\Reminder::SHIFT_CLOSED);
        $data = [
            'priority'          => 'High',
            'title'             => __('app/notifications.shift_close.title', [], $captain->user->language ?? 'en'),
            'body'              => __('app/notifications.shift_close.body', [], $captain->user->language ?? 'en'),
            "sound"             => $metadata['sound'],
            "android_channel_id" => $metadata['android_channel_id'],
            "content_available" => true,
            "mutable_content"   => true,
        ];
        (new CloudMessage($captain->firebaseVersion()))->send(['to' => $captain->accessToken->fb_token ?? '', 'notification' => $data, 'data' => $data]);
    }
    public function findOrderWithRelations(int $id, int $companyId)
    {
        return Order::select('orders.*')
            ->belongsTo3pl($companyId)
            ->with([
                'captain',
                'client',
                'items',
                'logsExecpt.progress',
                'logsExecpt.createdBy',
                'addresses',
                'shop:id,name',
                'items.customizations',
            ])
            ->find($id);
    }

    public function getCaptainDetailStats($id, $fromDate = null, $toDate = null)
    {
        $dateFilter = function ($q) use ($fromDate, $toDate) {
            $q->when($fromDate, fn($q) => $q->whereDate('created_at', '>=', $fromDate))
                ->when($toDate, fn($q) => $q->whereDate('created_at', '<=', $toDate));
        };

        return Captain::query()
            ->select('id')
            ->whereId($id)
            ->withCount([
                'orders as orders_count'                    => $dateFilter,
                'ordersDelivered as delivered_orders_count' => $dateFilter,
                'ordersReturned as returned_orders_count'   => $dateFilter,
            ])
            ->first();
    }

    public function getCaptainShiftsPaginated(int $captainId, int $perPage = 10)
    {
        return ShiftStatus::query()
            ->with(['vehicle.vehicleType'])
            ->where('captain_id', $captainId)
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getCaptainOrders(int $captainId, int $perPage = 20)
    {
        return Order::query()
            ->with([
                'client.user',
                'progress',
                'shop',
                'payment',
            ])
            ->where('captain_id', $captainId)
            ->whereIn('status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CANCEL,
                OrderStatus::FORYOU_RETURN_ACCEPTED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
            ])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getVehiclesFor3PL($companyId, $type = null, $assigned = true)
    {
        return Vehicle::select('id', 'number')
            ->ThirdParty($companyId)
            ->when($type, fn($q) => $q->where('type', $type))
            ->when($assigned, fn($q) => $q->whereNull('assigned_to'))
            ->toBase()->get();
    }
    public function companyEarningList($companyId, $perPage, $filters)
    {
        $query = Order::query()
            ->leftJoin('third_party_commissions', 'third_party_commissions.order_id', '=', 'orders.id')
            ->where('third_party_commissions.third_party_company_id', $companyId)
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
            ])
            ->when($filters['from_date'] ?? null, function ($q, $date) {
                $q->where('orders.delivery_date', '>=', Carbon::parse($date)->format('Y-m-d') . ' 00:00:00');
            })
            ->when($filters['to_date'] ?? null, function ($q, $date) {
                $q->where('orders.delivery_date', '<=', Carbon::parse($date)->format('Y-m-d') . ' 23:59:59');
            })
            ->when($filters['q'] ?? null, function ($q, $search) {
                $q->where('orders.client_order_id', 'LIKE', $search . '%');
            })
            ->when($filters['status'] ?? null, function ($q, $status) {
                $q->where('orders.status_id', $status);
            })
            ->when($filters['client'] ?? null, function ($q, $client) {
                $q->where('orders.client_id', $client);
            })
            ->when($filters['shop'] ?? null, function ($q, $shop) {
                $q->where('orders.shopname', $shop);
            });

        $orders = (clone $query)
            ->select(
                'orders.id',
                'orders.client_id',
                'orders.captain_id',
                'orders.status_id',
                'orders.delivery_date',
                'orders.client_order_id',
                'orders.shopname',
                'orders.shop_to_delivery_km',
                'additional_km',
                'additional_km_earning',
                'basic_delivery_earnings',
                'total_earned_commission',
                'orders.created_at'
            )
            ->with([
                'captain.user',
                'client.user',
                'payment',
            ])
            ->orderBy('third_party_commissions.id', 'desc')
            ->paginate($perPage);

        $statistics = (clone $query)
            ->select(
                DB::raw('COUNT(*) as attended_orders'),
                DB::raw('AVG(third_party_commissions.total_earned_commission) as avg_commission'),
                DB::raw('SUM(third_party_commissions.total_earned_commission) as total_commission'),
                DB::raw('SUM(third_party_commissions.settled_amount) as total_payed_commission')
            )
            ->first();

        $thirdPartyCompany = ThirdPartyLogisticCompany::query()
            ->leftJoin('third_party_commissions as tc', function ($join) {
                $join->on('tc.third_party_company_id', '=', 'third_party_logistic_companies.id')
                    ->whereRaw(
                        'tc.id = (
                            SELECT MAX(id)
                            FROM third_party_commissions
                            WHERE third_party_company_id = third_party_logistic_companies.id
                        )'
                    );
            })
            ->where('third_party_logistic_companies.id', $companyId)
            ->select('tc.balance')
            ->toBase()
            ->first();

        return [
            'orders'     => $orders,
            'statistics' => [
                'attended_orders'        => (int) ($statistics->attended_orders ?? 0),
                'avg_commission'         => number_format($statistics->avg_commission ?? 0, 2),
                'total_commission'       => number_format($statistics->total_commission ?? 0, 2),
                'total_payed_commission' => number_format($statistics->total_payed_commission ?? 0, 2),
                'payable_commission'     => number_format($thirdPartyCompany->balance ?? 0, 2),
            ],
        ];
    }

    public function getOrderPayment(int $id)
    {
        return Order::query()
            ->without(['captain', 'shop'])
            ->select('id', 'amount')
            ->with(['payment:id,order_id,payment_mode'])
            ->findOrFail($id);
    }

    public function getPaymentCaptain($companyId)
    {
        $captains = Captain::commissionedCaptain()
            ->withName('captain_name')
            ->join('users', 'users.id', '=', 'captains.user_id')
            ->whereHas('captainThirdParty', function ($query) use ($companyId) {
                $query->where('third_party_logistic_company_id', $companyId);
            })
            ->select('captains.id')
            ->orderBy('users.name')
            ->get();
        return $captains;
    }

    public function getReconciliationCaptain($companyId)
    {
        return Captain::query()
            ->belongsTo3pl($companyId)
            ->select('id', 'firstname')
            ->orderBy('firstname')
            ->get()
            ->map(fn($captain) => [
                'id'   => $captain->id,
                'name' => $captain->firstname,
            ]);
    }

    public function captainPaidBy(array $filters)
    {
        $thirdPartyCompanyId = $filters['company__id'] ?? null;

        return CaptainCommissionPayment::query()
            ->when($thirdPartyCompanyId, function ($query, $companyId) {
                $query->whereHas('captain.captainThirdParty', function ($q) use ($companyId) {
                    $q->where('third_party_logistic_company_id', $companyId);
                });
            })
            ->join('users', 'users.id', '=', 'captain_commission_payments.settled_by')
            ->select('users.id', 'users.name')
            ->groupBy('users.id', 'users.name')
            ->orderBy('users.name')
            ->get();
    }
    public function createCaptainCommission(Captain $captain, array $data)
    {
        DB::connection('mysql::write')->beginTransaction();
        try {
            $transferred  = $data['transferred'];
            $payment_mode = $data['payment_mode'];
            $reference_no = $data['reference_no'] ?? "";
            $attachments  = $data['attachments'] ?? [];
            $from_date    = $data['date_from'] ?? null;
            $to_date      = $data['date_to'] ?? null;
            $orders_count = $data['orders_count'] ?? null;

            $commission = CaptainCommission::where('captain_id', $captain->id)
                ->latest('id')
                ->first();

            $balance = $commission->balance;

            $commission->settled_amount  += $transferred;
            $commission->payment_mode_id  = $payment_mode;
            $commission->reference_no     = $reference_no;
            $commission->balance         -= $transferred;
            $commission->settled_by       = auth()->id();
            $commission->settled_at       = now();
            $commission->save();

            $commissionPayments = [
                'commission_id'   => $commission->id,
                'captain_id'      => $commission->captain_id,
                'prev_balance'    => $balance,
                'amount_paid'     => $transferred,
                'reference_no'    => $reference_no,
                'balance'         => $commission->balance,
                'payment_mode_id' => $payment_mode,
                'order_count'     => $orders_count,
                'from_date'       => $from_date,
                'to_date'         => $to_date,
                'settled_by'      => auth()->id(),
                'settled_at'      => now(),
            ];

            CaptainCommissionPayment::create($commissionPayments);

            $attachments_upload = [];

            foreach ($attachments as $attachment) {
                $attachments_upload[] = [
                    "path" => str_replace(
                        'public',
                        'storage',
                        $attachment->storePublicly('public/captain_commission_settlement_attachment')
                    ),
                ];
            }

            if (! empty($attachments_upload)) {
                $commission->attachments()->createMany($attachments_upload);
            }

            DB::commit();

            return $commission->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function createCaptainCommissionConfirmationPayment(array $data)
    {
        DB::connection('mysql::write')->beginTransaction();

        try {

            $captainPayments = $data['captainPaymentsData'];

            $from_date        = $data['from_date'] ?? null;
            $to_date          = $data['to_date'] ?? null;
            $salary_from_date = $data['salary_from_date'] ?? null;
            $salary_to_date   = $data['salary_to_date'] ?? null;

            $now                = now();
            $commissionPayments = [];

            foreach ($captainPayments as $payment) {

                if (! empty($payment['paying_amount']) && $payment['paying_amount'] > 0) {

                    $amount_paid  = $payment['paying_amount'];
                    $payment_mode = $payment['payment_mode'];
                    $orders_count = $payment['orders_count'];

                    $commission = CaptainCommission::where('captain_id', $payment['id'])->latest('id')->first();
                    if (! $commission) {
                        continue; // skip if not found
                    }

                    $balance = $commission->balance;

                    $commission->settled_amount  += abs($amount_paid);
                    $commission->payment_mode_id  = $payment_mode;
                    $commission->balance         -= $amount_paid;
                    $commission->settled_by       = auth()->id();
                    $commission->settled_at       = $now;
                    $commission->save();

                    $commissionPayments[] = [
                        'commission_id'   => $commission->id,
                        'captain_id'      => $payment['id'],
                        'prev_balance'    => $balance,
                        'amount_paid'     => $amount_paid,
                        'balance'         => $commission->balance,
                        'payment_mode_id' => $payment_mode,
                        'order_count'     => $orders_count,
                        'from_date'       => $from_date,
                        'to_date'         => $to_date,
                        'settled_by'      => auth()->id(),
                        'settled_at'      => $now,
                    ];
                }
                if ($salary_from_date && $salary_to_date) {

                    if (! empty($payment['paying_salary']) && $payment['paying_salary'] > 0) {

                        $salary_from  = \Carbon\Carbon::parse($salary_from_date);
                        $salary_to    = \Carbon\Carbon::parse($salary_to_date);
                        $current_date = $salary_from->copy();

                        $salaryPayment = CaptainSalaryPayment::create([
                            'captain_id'        => $payment['id'],
                            'from_date'         => $salary_from,
                            'to_date'           => $salary_to,
                            'worked_days'       => $payment['worked_days'],
                            'salary_per_day'    => $payment['per_day_salary'],
                            'total_salary_paid' => $payment['paying_salary'],
                            'payment_mode_id'   => $payment['payment_mode'],
                            'paid_by'           => auth()->id(),
                            'created_at'        => $now,
                        ]);

                        $captainSalaryPaymentDate = [];
                        while ($current_date->lte($salary_to)) {

                            if (! CaptainSalaryPaymentDate::where('captain_id', $payment['id'])->where('paid_on_date', $current_date->copy())->exists()) {

                                $captainSalaryPaymentDate[] = [
                                    'salary_payment_id' => $salaryPayment->id,
                                    'captain_id'        => $payment['id'],
                                    'per_day_salary'    => $payment['per_day_salary'],
                                    'paid_on_date'      => $current_date->copy(),
                                ];
                            }
                            $current_date->addDay();
                        }
                        if (! empty($captainSalaryPaymentDate)) {
                            CaptainSalaryPaymentDate::insert($captainSalaryPaymentDate);
                        }
                    }
                }
            }
            if (! empty($commissionPayments)) {
                CaptainCommissionPayment::insert($commissionPayments);
            }
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getOrderCounts($companyId, $status)
    {
        return Order::belongsTo3pl($companyId)->whereIn("status_id", $status)->toBase()->count();
    }

}
