<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the five supported application roles.
     */
    public function run(): void
    {
        $roles = [
            'super-admin' => 'Super Administrador',
            'admin' => 'Administrador',
            'developer' => 'Desenvolvedor',
            'user' => 'Usuário',
            'viewer' => 'Visualizador',
        ];

        foreach ($roles as $slug => $name) {
            Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            );
        }
    }
}
