<?php
namespace App\Repositories;

use App\EmployeeCallLog;
use App\EmployeeCallParticipent;
use App\Events\CallStatusChanged;
use App\Interfaces\OperationCallInterface;
use App\PresenceStatus;
use App\Services\ShiftService;
use App\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OperationCallRepository implements OperationCallInterface
{
    public function __construct(private readonly ShiftService $shiftService)
    {}

    public function getOperationsWithPresence(int $perPage, ?string $status = null): LengthAwarePaginator | Collection
    {
        $query = User::select('id', 'name', 'email')->active()->operations()->with('presenceStatus');

        if ($status) {
            $query->when($status === 'off_duty', fn($q) => $q->where(fn($sub) => $sub->whereHas('presenceStatus', fn($s) => $s->where('status', 'off_duty'))->orWhereDoesntHave('presenceStatus')), fn($q) => $q->whereHas('presenceStatus', fn($s) => $s->where('status', $status)));
        }

        return $perPage !== -1 ? $query->paginate($perPage)->withQueryString() : $query->get();
    }

    public function saveCallLog(array $data, $user): bool | EmployeeCallLog
    {
        try {
            return DB::transaction(function () use ($data, $user) {
                $data['initiator_id'] = $user->id;
                $data['room_id']      = "call_{$user->id}_" . Str::random(12);

                $userIds = $data['callee_ids'];
                unset($data['callee_ids']);
                $data['started_at'] = now();

                $call = EmployeeCallLog::create($data);

                foreach ($userIds as $calleeId) {
                    $call->participants()->create([
                        'user_id' => $calleeId,
                        'status'  => 'initiated',
                    ]);
                }

                return $call->load('participants.user');
            });
        } catch (\Throwable $th) {
            Log::error('Error saving operations call log', [
                'error'   => $th->getMessage(),
                'user_id' => $user->id ?? null,
                'data'    => $data,
            ]);

            return false; // return false instead of silently failing
        }
    }

    public function updateParticipent(EmployeeCallParticipent $participent, $data): bool
    {
        return $participent->update($data);
    }

    public function notifyUsers($messages, $user, $status)
    {
        try {
            event(
                new CallStatusChanged(
                    message: $messages,
                    user: $user,
                    payload: [
                        'status' => $status,
                    ],
                ),
            );
        } catch (\Throwable $th) {
            // fail silently
            Log::info('call status event change failed', ['error' => $th->getMessage()]);
        }
    }

    public function updateCallLog(EmployeeCallLog $call, array $data): bool
    {
        return $call->update($data);
    }

    public function handleParticipantLeft(EmployeeCallLog $call, string $email)
    {
        $userId = User::where('email', $email)->value('id');

        if (! $userId) {
            Log::channel('jaas_webhook')->info('Participant left — user not found.', compact('email'));
            return;
        }

        $participant = EmployeeCallParticipent::where('call_id', $call->id)->where('user_id', $userId)->first();

        if (! $participant) {
            Log::channel('jaas_webhook')->info('Participant record not found.', [
                'email'   => $email,
                'call_id' => $call->id,
            ]);
            return;
        }

        $this->shiftService->setPresenceStatus($userId, [
            'status'            => 'on_duty',
            'status_changed_at' => now(),
        ]);

        $this->updateParticipent($participant, [
            'left_at' => now(),
        ]);

        return true;
    }

    public function handleRoomDestroyed(EmployeeCallLog $call)
    {
        DB::transaction(function () use ($call) {
            $call->load('participants');

            $userIds = $call->participants->pluck('user_id')->toArray();

            PresenceStatus::where('status', 'in_call')
                ->whereIn('user_id', $userIds)
                ->update([
                    'status'            => 'on_duty',
                    'status_changed_at' => now(),
                ]);

            $call
                ->participants()
                ->whereNull('left_at')
                ->update(['left_at' => now()]);

            $this->updateCallLog($call, ['status' => 'completed']);
        });
    }
}
