<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            'Admin Général',
            'Responsable Administratif',
            'Chef de Terrain Supérieur',
            'Chef de Terrain',
            'Superviseur',
            'Enquêteur',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }
}
