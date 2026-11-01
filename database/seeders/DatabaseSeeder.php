<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * No users are seeded: accounts exist only through invitations (P1-10). Reference data such as roles and
 * permissions is created by migrations, so every environment has the same set.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
