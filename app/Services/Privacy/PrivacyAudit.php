<?php

namespace App\Services\Privacy;

use App\Models\PrivacyAuditLog;
use App\Models\User;

/**
 * Append-only record of privacy decisions. Only metadata is stored: the
 * `details` payload is encrypted by the model cast and message content is
 * never written to the trail.
 */
final class PrivacyAudit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function record(
        ?User $user,
        string $action,
        string $summary,
        array $details = [],
        ?string $ip = null,
    ): PrivacyAuditLog {
        return PrivacyAuditLog::query()->create([
            'user_id' => $user?->getKey(),
            'action' => $action,
            'summary' => $summary,
            'details' => $details === [] ? null : $details,
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }
}
