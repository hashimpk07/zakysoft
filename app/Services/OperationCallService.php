<?php
namespace App\Services;

use App\EmployeeCallLog;
use App\EmployeeCallParticipent;
use App\Interfaces\OperationCallInterface;
use App\PresenceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OperationCallService
{
    public function __construct(private readonly OperationCallInterface $callInterface, private readonly ShiftService $shiftService)
    {}

    public function getOpertionsWithPresence($perPage, $status): LengthAwarePaginator | Collection
    {
        $operations = $this->callInterface->getOperationsWithPresence($perPage, $status);

        if ($perPage == -1 && $operations instanceof LengthAwarePaginator) {
            $operations = $operations->getCollection();
        }

        $operations->transform(function ($item) {
            $item->status = ! isset($item->presenceStatus) ? 'Off Duty' : $this->generateStatus($item->presenceStatus);
            return $item;
        });

        return $operations;
    }

    private function generateStatus(Model | PresenceStatus $presence)
    {
        $status = $presence->status ?? 'off_duty';

        return ucwords(str_replace('_', ' ', $status));
    }

    public function saveCallLog($data, $user): array | Model
    {
        $callLog = $this->callInterface->saveCallLog($data, $user);

        if (! $callLog) {
            return [
                'message' => 'save call log failed',
                'error'   => true,
            ];
        }

        return $callLog;
    }

    public function changeCallStatus(EmployeeCallParticipent $participent, string $status, int $userId): bool
    {
        // Normalize and validate allowed statuses
        $status = strtolower($status);

        if ($status == 'hangup') {
            $status = $participent->status == 'answered' ? 'left' : 'rejected';
        }

        // Side-effects for presence state (only when needed)
        if (in_array($status, ['answered', 'left'])) {
            $this->shiftService->setPresenceStatus($userId, [
                'status'            => $status === 'answered' ? 'in_call' : 'on_duty',
                'status_changed_at' => now(),
            ]);
        }

        // Compute payload with match
        $payload = match ($status) {
            'answered' => ['status' => 'answered', 'joined_at' => now()],
            'missed', 'rejected' => ['status' => $status],
            'left'     => ['left_at' => now()],
        }; // concise, strict, no fallthrough

        return $this->callInterface->updateParticipent($participent, $payload); // single exit
    }

    public function getNotificationMessage(string $initiatorName, string $participantName, string $status): array
    {
        return match ($status) {
            'accepted'    => [
                'initiator' => "{$participantName} accepted your call.",
                'participant' => "You accepted {$initiatorName}'s call.",
            ],

            'missed'      => [
                'initiator' => "{$participantName} missed your call.",
                'participant' => "You missed a call from {$initiatorName}.",
            ],

            'rejected' => [
                'initiator' => "{$participantName} rejected your call.",
                'participant' => "You rejected {$initiatorName}'s call.",
            ],

            'left' => [
                'initiator' => "{$participantName} left the call.",
                'participant' => "You left the call with {$initiatorName}.",
            ],

            'hangup' => [
                'initiator' => "You ended the call with {$participantName}.",
                'participant' => "{$initiatorName} ended the call.",
            ],

            default => [
                'initiator'   => 'Call status updated.',
                'participant' => 'Call status updated.',
            ],
        };
    }

    public function notifyUsers($initiator, $user, $status)
    {
        $messages = $this->getNotificationMessage($initiator->name, $user->name, $status);

        $this->callInterface->notifyUsers($messages['initiator'], $initiator, $status);
        if ($status == 'missed') {
            $this->callInterface->notifyUsers($messages['participant'], $user, $status);
        }
    }

    public function updateCallLog(EmployeeCallLog $call, array $data): bool
    {
        return $this->callInterface->updateCallLog($call, $data);
    }

    public function getRoomIdFromConference(string $confrence): ?string
    {
        if (! $confrence || ! str_contains($confrence, '@conference')) {
            return null;
        }
        [$roomId] = explode('@conference', $confrence, 2);
        return $roomId;
    }

    public function handleJaasWebhook(array $payload): void
    {
        $eventType  = $payload['event_type'] ?? null;
        $data       = $payload['data'] ?? [];
        $email      = data_get($data, 'email', 'unknown');
        $conference = data_get($data, 'conference', 'unknown');

        $roomId  = $this->getRoomIdFromConference($conference);
        $callLog = EmployeeCallLog::room($roomId)->first();

        if (! $callLog) {
            Log::channel('jaas_webhook')->warning('Call log not found.', compact('roomId', 'conference', 'eventType'));
            return;
        }

        match ($eventType) {
            'PARTICIPANT_LEFT' => $this->callInterface->handleParticipantLeft($callLog, $email),
            'ROOM_DESTROYED'   => $this->callInterface->handleRoomDestroyed($callLog),
            default            => $this->logUnexpectedEvent($eventType, $data, $roomId, $conference, $email),
        };
    }

    protected function logUnexpectedEvent(string $eventType, array $data, string $roomId, string $conference, string $email): void
    {
        Log::channel('jaas_webhook')->notice('Unhandled JaaS event received.', [
            'event'      => $eventType,
            'room'       => $roomId,
            'conference' => $conference,
            'email'      => $email,
            'payload'    => $data,
        ]);
    }
}
