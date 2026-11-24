<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_cannot_be_updated_or_deleted(): void
    {
        $user = User::factory()->create();
        $entry = AuditLog::create([
            'user_id' => $user->id, 'action' => 'test_action', 'module' => 'test',
            'description' => 'Append-only audit entry.', 'old_values' => ['status' => 'draft'],
            'new_values' => ['status' => 'posted'], 'created_at' => now(),
        ]);

        try {
            $entry->update(['description' => 'Modified audit entry.']);
            $this->fail('An audit log entry was updated.');
        } catch (LogicException) {
            $this->assertSame('Append-only audit entry.', $entry->fresh()->description);
        }

        try {
            $entry->delete();
            $this->fail('An audit log entry was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseCount('audit_logs', 1);
        }
    }
}
