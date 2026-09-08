<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Str;

class ActivityLogger
{
    public static function add(?string $username, string $action, mixed $details = null): void
    {
        try {
            ActivityLog::create([
                '_id' => 'log_'.Str::lower(Str::random(20)),
                'user' => strtolower(trim((string) $username)),
                'action' => $action,
                'details' => $details,
                'timestamp' => now(),
            ]);
        } catch (\Throwable) {
            // Auditoria não pode derrubar a operação principal.
        }
    }
}
