<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    /**
     * Seed the single Super Admin account.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@syncra.local'],
            [
                'name' => 'Sistem Yöneticisi',
                'password' => 'SyncraAdmin!2026',
                'department' => 'Yönetim',
                'is_active' => true,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ]
        );

        if (! $user->hasRole('Super Admin')) {
            $user->assignRole('Super Admin');
        }

        $this->command->info('Super Admin hazır:');
        $this->command->info('  Email: '.$user->email);
        $this->command->info('  Şifre: SyncraAdmin!2026 (ilk girişte değiştirilmesi zorunlu)');
        $this->command->info('  Rol: Super Admin');
    }
}
