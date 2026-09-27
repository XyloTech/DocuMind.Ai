<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\PrivacyAuditLog;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('privacy:prune')]
#[Description('Delete conversations older than the retention window each account has chosen')]
class PruneChatHistory extends Command
{
    /**
     * Run once a day. Accounts without a retention window keep everything until
     * they delete it themselves.
     */
    public function handle(): int
    {
        $total = 0;

        User::query()
            ->whereNotNull('chat_retention_days')
            ->orderBy('id')
            ->chunkById(200, function ($users) use (&$total): void {
                foreach ($users as $user) {
                    $cutoff = now()->subDays((int) $user->chat_retention_days);

                    $count = (int) Chat::query()
                        ->where('user_id', $user->getKey())
                        ->where(function ($query) use ($cutoff): void {
                            $query->whereNull('last_message_at')
                                ->orWhere('last_message_at', '<', $cutoff);
                        })
                        ->delete();

                    if ($count === 0) {
                        continue;
                    }

                    $total += $count;

                    PrivacyAuditLog::query()->create([
                        'user_id' => $user->getKey(),
                        'action' => 'retention.pruned',
                        'summary' => 'Removed '.$count.' '.Str::plural('conversation', $count).' older than '.$user->chat_retention_days.' days.',
                        'details' => [
                            'conversations' => $count,
                            'retention_days' => (int) $user->chat_retention_days,
                        ],
                        'created_at' => now(),
                    ]);
                }
            });

        $this->components->info($total.' '.Str::plural('conversation', $total).' removed by retention rules.');

        return self::SUCCESS;
    }
}
