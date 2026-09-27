<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Site;
use App\Models\WidgetConversationAuditLog;

#[Signature('widget:prune-visitors')]
#[Description('Delete widget visitor conversations after each site retention period')]
class PruneWidgetVisitorData extends Command
{
    public function handle(): int
    {
        $deleted = 0;

        Site::query()
            ->orderBy('id')
            ->each(function (Site $site) use (&$deleted): void {
                $cutoff = now()->subDays((int) $site->visitor_retention_days);

                $site->conversations()
                    ->where('created_at', '<', $cutoff)
                    ->orderBy('id')
                    ->chunkById(100, function ($conversations) use ($site, &$deleted): void {
                        foreach ($conversations as $conversation) {
                            WidgetConversationAuditLog::query()->create([
                                'workspace_id' => $site->workspace_id,
                                'site_id' => $site->getKey(),
                                'conversation_id' => $conversation->getKey(),
                                'action' => 'visitor.retention_expired',
                                'details' => ['reason' => 'site_retention_period_elapsed'],
                                'created_at' => now(),
                            ]);

                            $conversation->delete();
                            $deleted++;
                        }
                    });
            });

        $this->info("Deleted {$deleted} expired visitor conversations.");

        return self::SUCCESS;
    }
}
