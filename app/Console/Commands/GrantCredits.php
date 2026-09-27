<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Billing\CreditLedger;
use Illuminate\Console\Command;

class GrantCredits extends Command
{
    protected $signature = 'user:credits
                            {email : Account email address}
                            {amount : Credits to add (negative to remove)}
                            {--set : Set the balance to exactly this amount}';

    protected $description = 'Add or set credits on an account, e.g. for demo data or support';

    public function handle(CreditLedger $ledger): int
    {
        $amount = (int) $this->argument('amount');
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error('No account found for '.$email.'.');

            return self::FAILURE;
        }

        $before = $user->credits;

        if ($this->option('set')) {
            $difference = $amount - $before;

            $difference >= 0
                ? $ledger->grant($user, $difference)
                : $ledger->deduct($user, abs($difference));
        } else {
            $amount >= 0
                ? $ledger->grant($user, $amount)
                : $ledger->deduct($user, abs($amount));
        }

        $this->components->info(
            $user->email.': '.$before.' → '.$user->fresh()->credits.' credits',
        );

        return self::SUCCESS;
    }
}
