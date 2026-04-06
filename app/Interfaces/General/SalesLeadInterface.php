<?php

namespace App\Interfaces\General;

use App\SalesLead;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface SalesLeadInterface
{
    public function getStatusCounts(): Collection;
    public function getLeads(Request $request): LengthAwarePaginator;
    public function createNote(SalesLead $lead, array $data);
    public function updateLead(SalesLead $lead, array $data);
    public function load(SalesLead $lead, ?Closure $callback = null): SalesLead;

    public function create(array $data): SalesLead;
    public function update(SalesLead $lead, array $data): SalesLead;
    public function syncRelations(SalesLead $lead, array $data): void;
    public function handleBank(SalesLead $lead, array $bankData): void;
    public function handleDocuments(SalesLead $lead, array $newDocuments, array $keepIds = []): void;
}