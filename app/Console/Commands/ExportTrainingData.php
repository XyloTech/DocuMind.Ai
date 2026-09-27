<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Privacy\PrivacyAudit;
use App\Services\Privacy\PrivacyDataService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Signature('privacy:training-export {--output= : Path inside the storage disk to write the JSONL dataset to}')]
#[Description('Build an anonymised training dataset from accounts that explicitly opted in')]
class ExportTrainingData extends Command
{
    /**
     * Only accounts with consent still on file contribute, and every pair is
     * passed through the anonymiser first — identity never leaves the account.
     */
    public function handle(PrivacyDataService $data, PrivacyAudit $audit): int
    {
        $rows = [];
        $accounts = 0;

        User::query()
            ->where('allow_model_training', true)
            ->whereNotNull('privacy_consent_at')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($data, $audit, &$rows, &$accounts): void {
                foreach ($users as $user) {
                    $pairs = $data->trainingPairs($user);

                    if ($pairs === []) {
                        continue;
                    }

                    $accounts++;

                    foreach ($pairs as $pair) {
                        $rows[] = json_encode($pair, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }

                    $audit->record(
                        $user,
                        'training.exported',
                        count($pairs).' anonymised conversation '.Str::plural('pair', count($pairs)).' exported for training.',
                        ['pairs' => count($pairs)],
                    );
                }
            });

        if ($rows === []) {
            $this->components->info('No account has consented to training. Nothing was exported.');

            return self::SUCCESS;
        }

        $path = trim(
            (string) ($this->option('output') ?: config('privacy.training_path').'/'.now()->format('Y-m-d-His').'.jsonl'),
            '/',
        );

        Storage::disk((string) config('privacy.training_disk', 'local'))
            ->put($path, implode("\n", $rows)."\n");

        $this->components->info(
            count($rows).' pairs from '.$accounts.' '.Str::plural('account', $accounts).' written to '.$path,
        );

        return self::SUCCESS;
    }
}
