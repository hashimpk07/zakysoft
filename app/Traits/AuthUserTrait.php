<?php

namespace App\Traits;

trait AuthUserTrait
{
    protected function getAuthUserIdOrFail($request): int
    {
        return $request->user()?->id ?? 0;
    }
}
