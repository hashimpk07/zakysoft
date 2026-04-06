<?php


namespace App\Services\General\Employees;

use App\EmployeeCallLog;
use App\EmployeeCallParticipent;
use App\Events\IncomingCallEvent;
use App\Interfaces\OperationCallInterface;
use App\PresenceStatus;
use App\Services\JitsiTokenService;
use App\Services\ShiftService;
use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class OperationCallService
{
    public function __construct(private readonly OperationCallInterface $callInterface, private readonly ShiftService $shiftService)
    {
    }

    public function getOperationsWithPresence($perPage, $status): LengthAwarePaginator|Collection
    {
        $operations = $this->callInterface->getOperationsWithPresence($perPage, $status);

        if ($perPage == -1 && $operations instanceof LengthAwarePaginator) {
            $operations = $operations->getCollection();
        }

        $operations->transform(function ($item) {
            $item->status = !isset($item->presenceStatus) ? 'Off Duty' : $this->generateStatus($item->presenceStatus);
            return $item;
        });

        return $operations;
    }

    private function generateStatus(Model|PresenceStatus $presence)
    {
        $status = $presence->status ?? 'off_duty';
        return ucwords(str_replace('_', ' ', $status));
    }

    public function makeCall(array $data, User $user): bool|array
    {
        $call = $this->saveCallLog($data, $user);
        if (!$call instanceof EmployeeCallLog) {
            return false;
        }
        $tokenData = JitsiTokenService::generateToken(room: $call->room_id, userName: $user->name, email: $user->email, moderator: true);
        $token = $tokenData['token'];
        $room = $tokenData['room'];

        event(
            new IncomingCallEvent($call, [
                'token' => $tokenData['token'],
            ]),
        );

        $domain = config('services.jitsi.domain');

        return [
            'status' => true,
            'message' => 'call initiated',
            'call_log' => $call,
            'token' => $token,
            'domain' => $domain,
            'display_name' => $user->name,
            'room_name' => $room,
        ];
    }

    public function saveCallLog($data, $user): array|Model
    {
        $callLog = $this->callInterface->saveCallLog($data, $user);

        if (!$callLog) {
            return [
                'message' => 'save call log failed',
                'error' => true,
            ];
        }

        return $callLog;
    }

    public function answerCall(EmployeeCallLog $call, User $user)
    {
        $participantIds = $call->participants->pluck('user_id');
        $user = auth()->user();
        $isParticipant = $participantIds->contains($user->id);

        if (!$isParticipant) {
            return ['message' => 'Unauthorized: not a participant', 'status' => false];
        }

        if (!in_array($call->status, ['pending', 'on_going'])) {
            return ['message' => 'Call is not available', 'status' => false];
        }

        $participant = $call->participants()->whereBelongsTo($user)->first();

        if ($this->changeCallStatus($participant, 'answered', $user->id)) {
            $tokenData = JitsiTokenService::generateToken(room: $call->room_id, userName: $user->name, email: $user->email);
            $token = $tokenData['token'];
            $room = $tokenData['room'];

            $domain = config('services.jitsi.domain');

            return [
                'status' => true,
                'message' => 'can answer',
                'call_log' => $call,
                'token' => $token,
                'domain' => $domain,
                'display_name' => $user->name,
                'room_name' => $room,
            ];
        }

        return ['message' => 'Server error', 'status' => false];

    }

    public function changeCallStatus(EmployeeCallParticipent $participant, string $status, int $userId): bool
    {
        // Normalize and validate allowed statuses
        $status = strtolower($status);

        if ($status == 'hangup') {
            $status = $participant->status == 'answered' ? 'left' : 'rejected';
        }

        // Side-effects for presence state (only when needed)
        if (in_array($status, ['answered', 'left'])) {
            $this->shiftService->setPresenceStatus($userId, [
                'status' => $status === 'answered' ? 'in_call' : 'on_duty',
                'status_changed_at' => now(),
            ]);
        }

        // Compute payload with match
        $payload = match ($status) {
            'answered' => ['status' => 'answered', 'joined_at' => now()],
            'missed', 'rejected' => ['status' => $status],
            'left' => ['left_at' => now()],
        }; // concise, strict, no fallthrough

        return $this->callInterface->updateParticipent($participant, $payload); // single exit
    }
}