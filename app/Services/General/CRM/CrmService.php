<?php

namespace App\Services\General\CRM;

use App\Http\Requests\General\CRM\MockSendableRequest;
use App\Http\Requests\General\CRM\StoreSendableRequest;
use App\Http\Requests\General\CRM\UpdateSendableRequest;
use App\Http\Resources\General\CRM\SendableResource;
use App\Interfaces\General\SendableInterface;
use App\Sendable;
use App\Services\Sendable as SendableTypeRegistry;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
 
class CrmService
{
    protected string $sendableNamespace = 'App\\Services\\Sendable\\';
 
    public function __construct(
        protected SendableInterface $repository,
    ) {}
 
    public function allTypes(): Collection
    {
        return (new SendableTypeRegistry())->all();
    }
 
    public function list($perPage = 10)
    {
        $data =   SendableResource::collection($this->repository->paginate($perPage))->response()->getData(true);

        return [
            'data' => $data['data'],
            'pagination' => $data['meta']
        ];
    }
 
    public function resolveType(string $class): array
    {
        $typeClass = $this->sendableNamespace . $class;
 
        if (!class_exists($typeClass)) {
            throw new InvalidArgumentException("Sendable type [{$class}] not found.");
        }
 
        $sendableType = new SendableTypeRegistry(new $typeClass());
 
        if (!in_array($class, $sendableType->all()->toArray(), true)) {
            throw new InvalidArgumentException("Sendable type [{$class}] is not registered.");
        }

 
        return array_merge(
            ['class' => $class, 'sendable_type' => $sendableType],
            $sendableType->sendable()->partialData(),
        );
    }
 
    public function store(string $class, StoreSendableRequest $request): Sendable
    {
        $typeClass    = $this->sendableNamespace . $class;
        $sendableType = new SendableTypeRegistry(new $typeClass());
 
        $request->validate($sendableType->validate());
 
        $sendableType->sendable()->filters(
            $request->except(['title', 'frequency', 'time', 'emails', 'cc_emails'])
        );
 
        $sendable = $this->repository->create([
            'title'     => $request->title,
            'frequency' => $request->frequency,
            'time'      => $request->time,
            'class'     => serialize($sendableType),
        ]);
 
        $this->repository->syncEmails(
            $sendable,
            $this->parseEmails($request->emails),
            $this->parseEmails($request->cc_emails),
        );
 
        return $sendable;
    }
 
    public function prepareEditData(Sendable $sendable): array
    {
        $sendableType = unserialize($sendable->class);
 
        return array_merge(
            ['sendable' => $sendable, 'sendable_type' => $sendableType],
            $sendableType->sendable()->partialData(),
        );
    }
 
    public function update(Sendable $sendable, UpdateSendableRequest $request): Sendable
    {
        $sendableType = unserialize($sendable->class);
 
        $request->validate($sendableType->validate());
 
        $sendableType->sendable()->filters(
            $request->except(['title', 'frequency', 'time', 'emails', 'cc_emails'])
        );
 
        $sendable = $this->repository->update($sendable, [
            'title'     => $request->title,
            'frequency' => $request->frequency,
            'time'      => $request->time,
            'class'     => serialize($sendableType),
        ]);
 
        $this->repository->syncEmails(
            $sendable,
            $this->parseEmails($request->emails),
            $this->parseEmails($request->cc_emails),
        );
 
        return $sendable;
    }
 
    public function delete(Sendable $sendable): void
    {
        $this->repository->delete($sendable);
    }
 
    public function mock(Sendable $sendable, MockSendableRequest $request): void
    {
        $sendableType = unserialize($sendable->class);
        $sendableType->mock($request->email, $sendable);
    }
 
    protected function parseEmails(?string $raw): array
    {
        if (empty($raw)) {
            return [];
        }
 
        return array_filter(array_map('trim', explode(',', $raw)));
    }
}
