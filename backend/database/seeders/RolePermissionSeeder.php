<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Canonical permission dictionary. Every module follows a
     * `module.action` naming convention with `guard_name = web`
     * (Sanctum SPA cookie session).
     *
     * @var array<int, string>
     */
    protected array $permissions = [
        'dashboard.view',

        'users.view', 'users.create', 'users.update', 'users.delete',
        'users.manage_roles', 'users.reset_password', 'users.toggle_active',

        'roles.view', 'roles.create', 'roles.update', 'roles.delete',

        'leads.view', 'leads.create', 'leads.update', 'leads.delete',
        'leads.import', 'leads.convert', 'leads.assign',

        'contacts.view', 'contacts.create', 'contacts.update', 'contacts.delete',

        'companies.view', 'companies.create', 'companies.update', 'companies.delete',

        'deals.view', 'deals.create', 'deals.update', 'deals.delete',
        'deals.move', 'deals.assign',

        'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete', 'tasks.assign',

        'activities.view', 'activities.create', 'activities.update', 'activities.delete',

        'tickets.view', 'tickets.create', 'tickets.update', 'tickets.delete', 'tickets.assign',

        'products.view', 'products.create', 'products.update', 'products.delete',

        'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete', 'quotes.send',

        'reports.view', 'reports.export',

        'logs.view', 'logs.export',

        'notifications.view',

        'settings.manage',

        'chat.use',
    ];

    /**
     * Seed the roles and permissions.
     */
    public function run(): void
    {
        // Make sure every permission from the dictionary exists (idempotent).
        foreach ($this->permissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        $viewPermissions = array_values(array_filter(
            $this->permissions,
            fn (string $name): bool => str_ends_with($name, '.view')
        ));

        $roles = [
            // Super Admin: no explicit permissions — full access comes from
            // Gate::before (wired by a parallel lane). Role must still exist.
            'Super Admin' => [],

            'Admin' => [
                'users.view', 'users.create', 'users.update', 'users.reset_password', 'users.toggle_active',
                'roles.view',

                'leads.view', 'leads.create', 'leads.update', 'leads.delete',
                'leads.import', 'leads.convert', 'leads.assign',

                'contacts.view', 'contacts.create', 'contacts.update', 'contacts.delete',

                'companies.view', 'companies.create', 'companies.update', 'companies.delete',

                'deals.view', 'deals.create', 'deals.update', 'deals.delete',
                'deals.move', 'deals.assign',

                'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete', 'tasks.assign',

                'activities.view', 'activities.create', 'activities.update', 'activities.delete',

                'tickets.view', 'tickets.create', 'tickets.update', 'tickets.delete', 'tickets.assign',

                'products.view', 'products.create', 'products.update', 'products.delete',

                'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete', 'quotes.send',

                'reports.view', 'reports.export',

                'logs.view',

                'settings.manage',
                'chat.use',
                'dashboard.view',
                'notifications.view',
            ],

            'Satış Müdürü' => [
                'leads.view', 'leads.create', 'leads.update', 'leads.delete',
                'leads.import', 'leads.convert', 'leads.assign',

                'contacts.view', 'contacts.create', 'contacts.update', 'contacts.delete',

                'companies.view', 'companies.create', 'companies.update', 'companies.delete',

                'deals.view', 'deals.create', 'deals.update', 'deals.delete',
                'deals.move', 'deals.assign',

                'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete', 'tasks.assign',

                'activities.view', 'activities.create', 'activities.update', 'activities.delete',

                'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete', 'quotes.send',

                'products.view',

                'reports.view', 'reports.export',

                'dashboard.view',
                'chat.use',
                'notifications.view',
            ],

            'Satış Temsilcisi' => [
                'leads.view', 'leads.create', 'leads.update', 'leads.convert',

                'contacts.view', 'contacts.create', 'contacts.update',

                'companies.view', 'companies.create', 'companies.update',

                'deals.view', 'deals.create', 'deals.update', 'deals.move',

                'tasks.view', 'tasks.create', 'tasks.update',

                'activities.view', 'activities.create', 'activities.update',

                'quotes.view', 'quotes.create',

                'products.view',

                'dashboard.view',
                'chat.use',
                'notifications.view',
            ],

            'Destek Temsilcisi' => [
                'tickets.view', 'tickets.create', 'tickets.update', 'tickets.assign',

                'contacts.view',
                'companies.view',

                'tasks.view', 'tasks.create', 'tasks.update',

                'activities.view', 'activities.create',

                'dashboard.view',
                'chat.use',
                'notifications.view',
            ],

            // Viewer: every `.view` permission, no create/update/delete, no chat.
            'İzleyici' => $viewPermissions,
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            if (! empty($rolePermissions)) {
                $role->syncPermissions($rolePermissions);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('Roller ve izinler senkronize edildi: '.count($this->permissions).' izin, '.count($roles).' rol.');
    }
}
