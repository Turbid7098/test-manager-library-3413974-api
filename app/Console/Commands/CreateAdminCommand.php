<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('app:create-admin')]
#[Description('Creates the initial admin from environment variables')]
class CreateAdminCommand extends Command
{
    public function handle()
    {
        $username = getenv('ADMIN_USERNAME') ?: '';
        $password = getenv('ADMIN_PASSWORD') ?: '';

        if ($username === '' || $password === '') {
            $this->error('ADMIN_USERNAME y ADMIN_PASSWORD deben configurarse en el entorno.');

            return self::FAILURE;
        }

        $username = strtolower(trim($username));
        if ($username === '') {
            $this->error('ADMIN_USERNAME no puede estar vacío.');

            return self::FAILURE;
        }

        $existingUser = DB::table('library.user')
            ->where('username', $username)
            ->first(['role', 'password_hash']);

        if ($existingUser !== null) {
            if ($existingUser->role !== 'admin') {
                $this->error('El nombre configurado ya pertenece a un usuario que no es administrador.');

                return self::FAILURE;
            }

            if (! Hash::check($password, $existingUser->password_hash)) {
                DB::table('library.user')
                    ->where('username', $username)
                    ->update(['password_hash' => Hash::make($password)]);

                $this->info('La contraseña del administrador se sincronizó con la configuración del entorno.');

                return self::SUCCESS;
            }

            $this->info('El administrador configurado ya existe y está actualizado.');

            return self::SUCCESS;
        }

        DB::table('library.user')->insert([
            'id' => (string) Str::uuid(),
            'username' => $username,
            'password_hash' => Hash::make($password),
            'role' => 'admin',
        ]);

        $this->info('Administrador inicial creado correctamente.');

        return self::SUCCESS;
    }
}
