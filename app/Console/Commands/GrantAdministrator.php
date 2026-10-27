<?php

namespace App\Console\Commands;

use App\Models\Administrator;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('admin:grant {email : The email address of the user to make an administrator}')]
#[Description('Make a user an administrator of this installation (Admin in the user menu: add-ons and administrators)')]
class GrantAdministrator extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user has that email address. They need to sign up first.');

            return self::FAILURE;
        }

        $administrator = Administrator::query()->firstOrCreate(['user_id' => $user->id]);

        $this->info($administrator->wasRecentlyCreated
            ? "{$user->email} is now an administrator."
            : "{$user->email} was already an administrator.");

        return self::SUCCESS;
    }
}
