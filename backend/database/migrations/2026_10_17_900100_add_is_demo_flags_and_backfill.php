<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demo-data flag (System → Demo data).
 *
 * Adds `is_demo` (default false) to users, clinics and pharmacies, then flags
 * the records DatabaseSeeder created. Rules (conservative — when in doubt a
 * record stays real):
 *
 *  - users: email matches ^(clinicadmin|doctor|patient|driver|thirdparty|pharmacy)[0-9]+@promptaid.health$
 *    (case-insensitive) AND role is not super_admin. admin@promptaid.health
 *    and every account created outside the seeder are never flagged.
 *  - clinics: one of the three seeded names ("Prompt Aid Sandton/Rosebank/
 *    Pretoria Clinic") AND email is the seeded "{slug}@promptaid.health".
 *  - pharmacies: one of the two seeded names ("Prompt Aid Pharmacy Sandton",
 *    "Wellness Corner Pharmacy") AND email is the seeded "{slug}@promptaid.health".
 *
 * Everything else the seeder made (profiles, appointments, rides, orders,
 * products, services, lab requests ...) hangs off these rows and is found
 * through foreign keys by App\Services\Admin\DemoDataPurger.
 *
 * Also seeds the `demo_hide_providers` setting (off) and grants the
 * `demo-data.purge` key to the super-admin system role.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'clinics', 'pharmacies'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'is_demo')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->boolean('is_demo')->nullable()->default(false)->index();
                });
            }
        }

        if (Schema::hasTable('users')) {
            $ids = DB::table('users')
                ->where('email', 'like', '%@promptaid.health')
                ->where('role', '!=', 'super_admin')
                ->get(['id', 'email'])
                ->filter(fn ($u) => preg_match('/^(clinicadmin|doctor|patient|driver|thirdparty|pharmacy)[0-9]+@promptaid\.health$/i', (string) $u->email))
                ->pluck('id')
                ->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('users')->whereIn('id', $chunk)->update(['is_demo' => true]);
            }
        }

        $this->flagByNameAndEmail('clinics', ['Prompt Aid Sandton Clinic', 'Prompt Aid Rosebank Clinic', 'Prompt Aid Pretoria Clinic']);
        $this->flagByNameAndEmail('pharmacies', ['Prompt Aid Pharmacy Sandton', 'Wellness Corner Pharmacy']);

        if (Schema::hasTable('settings') && ! DB::table('settings')->where('key', 'demo_hide_providers')->exists()) {
            DB::table('settings')->insert(['key' => 'demo_hide_providers', 'value' => '0', 'created_at' => now(), 'updated_at' => now()]);
        }

        if (Schema::hasTable('roles') && Schema::hasTable('role_permissions')) {
            $roleId = DB::table('roles')->where('slug', 'super-admin')->value('id');
            if ($roleId && ! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_key', 'demo-data.purge')->exists()) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_key' => 'demo-data.purge',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    protected function flagByNameAndEmail(string $table, array $names): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'email')) {
            return;
        }
        foreach ($names as $name) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
            DB::table($table)->where('name', $name)->where('email', $slug.'@promptaid.health')->update(['is_demo' => true]);
        }
    }

    public function down(): void
    {
        foreach (['users', 'clinics', 'pharmacies'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'is_demo')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex($table.'_is_demo_index');
                    $t->dropColumn('is_demo');
                });
            }
        }
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->where('permission_key', 'demo-data.purge')->delete();
        }
    }
};
