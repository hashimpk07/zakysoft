<?php

namespace App\Repositories\General;

use App\Captain;
use App\CaptainEmploymentType;
use App\Interfaces\General\CaptainInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class CaptainInterfaceRepository implements CaptainInterface
{
    public function getPaginated(Request $request, int $perPage = 10): LengthAwarePaginator
    {
        return $this->buildQuery($request)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getStatistics(): array
    {
        $activeOnline = Captain::active()->online();

        return [
            'captains_active' => Captain::active()->count(),
            'captains_banned' => Captain::where('status', 'Banned')->count(),
            'captains_online' => Captain::online()->count(),
            'captains_offline' => Captain::active()->offline()->count(),
            'captains_sponsored' => (clone $activeOnline)->where('captain_employment_type_id', CaptainEmploymentType::SPONSORED)->count(),
            'captains_rental' => (clone $activeOnline)->where('captain_employment_type_id', CaptainEmploymentType::RENTED_FREELANCER)->count(),
            'captains_freelancer' => (clone $activeOnline)->where('captain_employment_type_id', CaptainEmploymentType::FREELANCER_OWN_VEHICLE)->count(),
        ];

    }

    // -------------------------------------------------------------------------
    // Query builder
    // -------------------------------------------------------------------------

    private function buildQuery(Request $request): Builder
    {
        return Captain::query()
            ->with([
                'user',
                'regions.quadrant',
                'currentShift',
                'employmentType',
                'vehicle.vehicleType',
                'nationality',
                'autoAssignPriority',
                'captainThirdParty',
            ])
            ->withCount(['ordersDelivered'])
            ->where('captains.status', '!=', 'Request')
            ->tap(fn(Builder $q) => $this->applyFilters($q, $request))
            ->tap(fn(Builder $q) => $this->applySorting($q, $request))
            ->orderBy('captains.id', 'desc');
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    private function applyFilters(Builder $query, Request $request): void
    {
        $this->applySearch($query, $request->input('search'));
        $this->applyMobileNo($query, $request->input('mobile_no'));
        $this->applyCode($query, $request->input('code'));
        $this->applyShiftStatus($query, $request->input('shift_status'));
        $this->applyThirdPartyCompany($query, $request->integer('third_party_company_id') ?: null);
        $this->applyNationality($query, $request->integer('nationality') ?: null);
        $this->applyRegion($query, $request->filled('region_id') ? $request->integer('region_id') : null);
        $this->applyQuadrant($query, $request->integer('quadrant_id') ?: null);
        $this->applyVehicleType($query, $request->input('vehicle_type'));
        $this->applyCaptainId($query, $request->integer('captain') ?: null);
        $this->applyStatus($query, $request->input('status'));
        $this->applyJobType($query, $request->integer('job_type') ?: null);
        $this->applyShiftRule($query, $request->has('shift_rule') ? $request->integer('shift_rule') : null);
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        if (blank($search)) {
            return;
        }

        $query->whereLike(['user.name', 'user.email', 'phone_number', 'iqama_number', 'licence_number'], $search);
    }

    private function applyMobileNo(Builder $query, ?string $mobileNo): void
    {
        if (blank($mobileNo)) {
            return;
        }

        $query->where('phone_number', 'LIKE', '%' . $mobileNo . '%');
    }

    private function applyCode(Builder $query, ?string $code): void
    {
        if (blank($code)) {
            return;
        }

        $query->whereLike(['code'], $code);
    }

    private function applyShiftStatus(Builder $query, ?string $shiftStatus): void
    {
        match ($shiftStatus) {
            'ONLINE' => $query->has('currentShift'),
            'OFFLINE' => $query->doesntHave('currentShift'),
            default => null,
        };
    }

    private function applyThirdPartyCompany(Builder $query, ?int $companyId): void
    {
        if (blank($companyId)) {
            return;
        }

        $query->whereHas('captainThirdParty', function (Builder $q) use ($companyId) {
            $q->where('third_party_logistic_company_id', $companyId);
        });
    }

    private function applyNationality(Builder $query, ?int $nationalityId): void
    {
        if (blank($nationalityId)) {
            return;
        }

        $query->where('nationality_id', $nationalityId);
    }

    private function applyRegion(Builder $query, ?int $regionId): void
    {
        if (blank($regionId)) {
            return;
        }

        $query->where('captains.region_id', $regionId);
    }

    private function applyQuadrant(Builder $query, ?int $quadrantId): void
    {
        if (blank($quadrantId)) {
            return;
        }

        $query->whereHas('regions.quadrant', function (Builder $q) use ($quadrantId) {
            $q->where('id', $quadrantId);
        });
    }

    private function applyVehicleType(Builder $query, ?string $vehicleType): void
    {
        if (blank($vehicleType)) {
            return;
        }

        $query->whereHas('vehicle', function (Builder $q) use ($vehicleType) {
            $q->where('type', $vehicleType);
        });
    }

    private function applyCaptainId(Builder $query, ?int $captainId): void
    {
        if (blank($captainId)) {
            return;
        }

        $query->where('captains.id', $captainId);
    }

    private function applyStatus(Builder $query, ?string $status): void
    {
        if (blank($status)) {
            return;
        }

        $query->where('captains.status', $status);
    }

    private function applyJobType(Builder $query, ?int $jobType): void
    {
        if (blank($jobType)) {
            return;
        }

        $query->where('captain_employment_type_id', $jobType);
    }

    private function applyShiftRule(Builder $query, ?int $shiftRuleId): void
    {
        if (is_null($shiftRuleId)) {
            return;
        }

        if ($shiftRuleId === -1) {
            $query->whereNull('shift_rule_id');
        } else {
            $query->where('shift_rule_id', $shiftRuleId);
        }
    }

    // -------------------------------------------------------------------------
    // Sorting
    // -------------------------------------------------------------------------

    private function applySorting(Builder $query, Request $request): void
    {
        $sortBy = $request->input('sort_by');

        if (blank($sortBy)) {
            return;
        }

        $order = $this->sanitizeSortOrder($request->input('sort_order', 'ASC'));

        match ($sortBy) {
            'version' => $this->sortByVersion($query, $order),
            'job_type' => $this->sortByJobType($query, $order),
            'work_status' => $this->sortByWorkStatus($query, $order),
            'online_status' => $this->sortByOnlineStatus($query, $order),
            'vehicle_type' => $this->sortByVehicleType($query, $order),
            'nationality' => $this->sortByNationality($query, $order),
            default => null,
        };
    }

    private function sortByVersion(Builder $query, string $order): void
    {
        $query->orderByRaw("CAST(current_using_app_version AS DECIMAL(10,2)) {$order}");
    }

    private function sortByJobType(Builder $query, string $order): void
    {
        $query->orderBy('captain_employment_type_id', $order);
    }

    private function sortByWorkStatus(Builder $query, string $order): void
    {
        $query->orderByRaw("
            CASE captains.status
                WHEN 'Active'   THEN 1
                WHEN 'Leave'    THEN 2
                WHEN 'Inactive' THEN 3
                WHEN 'Banned'   THEN 4
            END {$order}
        ");
    }

    private function sortByOnlineStatus(Builder $query, string $order): void
    {
        $query->orderByRaw("
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM shift_statuses
                    WHERE captains.id = shift_statuses.captain_id
                      AND shift_end IS NULL
                ) THEN 1
                ELSE 2
            END {$order}
        ");
    }

    private function sortByVehicleType(Builder $query, string $order): void
    {
        $query->orderByRaw("ISNULL(vehicle_types.id), vehicle_types.id {$order}");
    }

    private function sortByNationality(Builder $query, string $order): void
    {
        $query->orderByRaw("ISNULL(nationality_id), nationality_id {$order}");
    }

    private function sanitizeSortOrder(string $order): string
    {
        return strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
    }

    public function getPendingRequests(array $filters, int $perPage = 10)
    {
        return Captain::query()
            ->with(['nationality', 'regions', 'vehicle', 'captainThirdParty'])
            ->where('status', Captain::STATUS_REQUEST)
            // Basic Filters
            ->when($filters['mobile_no'] ?? null, fn($q, $m) => $q->where('phone_number', 'LIKE', "%$m%"))
            ->when($filters['nationality'] ?? null, fn($q, $n) => $q->where('nationality_id', $n))
            ->when($filters['region_id'] ?? null, fn($q, $r) => $q->where('region_id', $r))
            // Date Range - Refactored for cleaner SQL
            ->when($filters['from_date'] ?? null, fn($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['to_date'] ?? null, fn($q, $date) => $q->whereDate('created_at', '<=', $date))
            // Vehicle Existence
            ->when($filters['vehicle_status'] ?? null, function ($q, $status) {
                $status === 'YES' ? $q->has('vehicle') : $q->doesntHave('vehicle');
            })
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }
}