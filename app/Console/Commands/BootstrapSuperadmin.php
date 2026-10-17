<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

#[Signature('app:bootstrap-superadmin {--email= : Email akun baru} {--name=Superadmin : Nama akun}')]
#[Description('Pindahkan akun LPM lama dan buat superadmin awal sekali saja')]
class BootstrapSuperadmin extends Command
{
    public function handle(): int
    {
        $email = trim((string) $this->option('email'));
        $name = trim((string) $this->option('name'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            $this->error('Gunakan --email=alamat@domain dan --name=Nama.');

            return self::FAILURE;
        }
        $password = Str::password(24);
        $created = DB::transaction(function () use ($email, $name, $password): bool {
            DB::table('scheduling_state')->where('id', 1)->lockForUpdate()->first();
            Role::findOrCreate('lpm_admin', 'web')->syncPermissions(Permission::all());
            Role::findOrCreate('superadmin', 'web');
            if (User::where('email', $email)->exists() || (User::role('lpm_admin')->exists() && User::role('superadmin')->exists())) {
                return false;
            }
            User::role('superadmin')->lockForUpdate()->get()->each(function (User $user): void {
                $user->removeRole('superadmin');
                $user->update(['is_root_superadmin' => false]);
                $user->assignRole('lpm_admin');
            });
            $admin = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true, 'must_change_password' => true, 'is_root_superadmin' => true]);
            $admin->assignRole('superadmin');

            return true;
        }, 3);
        if (! $created) {
            $this->error('Email sudah dipakai atau migrasi LPM sudah pernah dilakukan.');

            return self::FAILURE;
        }

        $this->info('Akun superadmin dibuat. Akun LPM lama telah menjadi lpm_admin.');
        $this->line('Email: '.$email);
        $this->line('Kata sandi sementara (tampil sekali): '.$password);

        return self::SUCCESS;
    }
}
