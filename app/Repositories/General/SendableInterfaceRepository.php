<?php

namespace App\Repositories\General;

use App\Interfaces\General\SendableInterface;
use App\Sendable;
use Illuminate\Pagination\LengthAwarePaginator;

final class SendableInterfaceRepository implements SendableInterface
{
   public function paginate(int $perPage = 10): LengthAwarePaginator
    {
        return Sendable::with('toEmails', 'toCcEmails')
            ->latest()
            ->paginate($perPage);
    }
 
    public function create(array $data): Sendable
    {
        return Sendable::create($data);
    }
 
    public function update(Sendable $sendable, array $data): Sendable
    {
        $sendable->update($data);
 
        return $sendable->fresh();
    }
 
    public function delete(Sendable $sendable): void
    {
        $sendable->delete();
    }
 
    public function syncEmails(Sendable $sendable, array $emails, array $ccEmails): void
    {
        $sendable->toEmails()->delete();
        $sendable->toCcEmails()->delete();
 
        foreach ($emails as $email) {
            if (!empty($email)) {
                $sendable->toEmails()->create(['email' => $email]);
            }
        }
 
        foreach ($ccEmails as $email) {
            if (!empty($email)) {
                $sendable->toEmails()->create(['email' => $email, 'is_cc' => 1]);
            }
        }
    }
}
