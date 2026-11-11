<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo org chart. Every account uses the password "password".
 *
 *   Maya Manager ── Eli Employee, Emma Employee      (team A: the main demo team)
 *   Marcus Manager ─ Nina Newhire                    (team B: proves managers cannot cross teams)
 *   Olivia Ops, Omar Ops                             (operations)
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $maya = User::factory()->manager()->create(['name' => 'Maya Manager', 'email' => 'manager@example.com']);
        $marcus = User::factory()->manager()->create(['name' => 'Marcus Manager', 'email' => 'manager2@example.com']);

        User::factory()->employee($maya)->create(['name' => 'Eli Employee', 'email' => 'employee@example.com']);
        User::factory()->employee($maya)->create(['name' => 'Emma Employee', 'email' => 'employee2@example.com']);
        User::factory()->employee($marcus)->create(['name' => 'Nina Newhire', 'email' => 'employee3@example.com']);

        User::factory()->operations()->create(['name' => 'Olivia Ops', 'email' => 'ops@example.com']);
        User::factory()->operations()->create(['name' => 'Omar Ops', 'email' => 'ops2@example.com']);
    }
}
