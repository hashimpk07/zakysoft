<?php

namespace App\Exports\Exportable;

interface Exportable {
    public function download(string $file_name);
    public function stream(string $file_name);
    public function save(string $path);
} 