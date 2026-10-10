<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:create-admin')]
#[Description('Creates the initial admin from environment variables')]
class CreateAdminCommand extends Command
{
    public function handle()
    {
        $username = env('ADMIN_USERNAME');
        $password = env('ADMIN_PASSWORD');

        if (!$username || !$password) {
            $this->error('ADMIN_USERNAME or ADMIN_PASSWORD not set in environment.');
            return;
        }

        $username = strtolower(trim($username));

        $exists = \Illuminate\Support\Facades\DB::table('library.user')->where('username', $username)->exists();

        if ($exists) {
            $this->info("Admin user '{$username}' already exists.");
            return;
        }

        \Illuminate\Support\Facades\DB::table('library.user')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'username' => $username,
            'password_hash' => \Illuminate\Support\Facades\Hash::make($password),
            'role' => 'admin',
        ]);

        $this->info("Admin user '{$username}' created successfully.");
    }
}
