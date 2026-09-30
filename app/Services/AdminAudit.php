<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;

/**
 * One place that writes the administrative audit trail, so every admin
 * controller logs the same way: actor, optional subject, a stable action
 * code, a human summary and optional encrypted details.
 */
final class AdminAudit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function record(
        User $actor,
        ?User $subject,
        string $action,
        string $summary,
        array $details = [],
    ): void {
        AdminAuditLog::query()->create([
            'actor_id' => $actor->getKey(),
            'subject_user_id' => $subject?->getKey(),
            'action' => $action,
            'summary' => $summary,
            'details' => $details === [] ? null : $details,
            'created_at' => now(),
        ]);
    }
}
