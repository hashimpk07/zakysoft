<?php

namespace App\Services\General\DTO;

final class ImportResultDTO
{
    private function __construct(
        public readonly bool    $success,
        public readonly ?string $errorFilePath = null,
    ) {}

    public static function success(): self
    {
        return new self(success: true);
    }

    public static function failed(string $errorFilePath): self
    {
        return new self(success: false, errorFilePath: $errorFilePath);
    }
}