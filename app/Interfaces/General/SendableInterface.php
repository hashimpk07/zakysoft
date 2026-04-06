<?php

namespace App\Interfaces\General;

use App\Sendable;
use Illuminate\Pagination\LengthAwarePaginator;

interface SendableInterface
{
     public function paginate(int $perPage = 10): LengthAwarePaginator;
 
    public function create(array $data): Sendable;
 
    public function update(Sendable $sendable, array $data): Sendable;
 
    public function delete(Sendable $sendable): void;
 
    public function syncEmails(Sendable $sendable, array $emails, array $ccEmails): void;
}
