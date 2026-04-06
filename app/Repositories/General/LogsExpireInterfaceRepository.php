<?php

namespace App\Repositories\General;
use App\Interfaces\General\LogsExpireInterface;
use App\Files_and_remainders;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LogsExpireInterfaceRepository implements LogsExpireInterface
{
   
   public function getLogsList(int $perPage)
    {
        return DB::table('logs')
            ->join('users', 'users.id', '=', 'logs.created_by')
            ->orderByDesc('logs.id')
            ->select(
                'logs.id',
                'logs.module',
                'logs.content',
                'logs.created_at',
                'logs.updated_at',
                'users.name'
            )
            ->paginate($perPage);
    }

    public function getExpireList(array $filters, int $perPage)
    {
        $query = Files_and_remainders::query()
                ->select(
                    'id',
                    'name',
                    'type',
                    'date',
                    'reference_path'
                )
                ->orderByDesc('id');

            if (!empty($filters['from_date'])) {
                $fromDate = Carbon::createFromFormat('m/d/Y', $filters['from_date'])
                            ->format('Y-m-d');
                $query->whereDate('date', '>=', $fromDate);
            }

            if (!empty($filters['to_date'])) {
                $toDate = Carbon::createFromFormat('m/d/Y', $filters['to_date'])
                            ->format('Y-m-d');

                $query->whereDate('date', '<=', $toDate);
            }
        return $query->paginate($perPage);
    }

}