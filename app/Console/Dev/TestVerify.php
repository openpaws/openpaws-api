<?php

namespace App\Console\Dev;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Dev/test helper for the UI end-to-end suite; registered only in local/testing (routes/console.php).
 */
class TestVerify extends Command
{
    protected $signature = 'openpaws:test-verify {username} {--adult : Also set adult_confirmed_at}';

    protected $description = 'Mark a user\'s email as verified (local/testing only)';

    public function handle(): int
    {
        $user = User::where('username', $this->argument('username'))->first();
        if ($user === null) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        $user->email_verified_at ??= now();
        if ($this->option('adult')) {
            $user->adult_confirmed_at ??= now();
        }
        $user->save();

        $this->info("Verified {$user->username}.");

        return self::SUCCESS;
    }
}
