<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    /** @param array<string, mixed>|null $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(string $action, string $module, string $description, ?Model $subject = null, ?array $oldValues = null, ?array $newValues = null, ?int $userId = null): AuditLog
    {
        $request = app(Request::class);
        $sensitiveKeys = ['password', 'password_confirmation', 'token', 'remember_token', 'session_id', 'credential', 'secret'];
        $sanitize = null;
        $sanitize = static function (?array $values) use (&$sanitize, $sensitiveKeys): ?array {
            if ($values === null) {
                return null;
            }

            foreach ($values as $key => $value) {
                if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                    unset($values[$key]);
                } elseif (is_array($value)) {
                    $values[$key] = $sanitize($value);
                }
            }

            return $values;
        };

        return AuditLog::create([
            'user_id' => $userId ?? $request->user()?->getAuthIdentifier(),
            'action' => $action, 'module' => $module, 'description' => $description,
            'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(),
            'old_values' => $sanitize($oldValues), 'new_values' => $sanitize($newValues),
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'created_at' => now(),
        ]);
    }
}
