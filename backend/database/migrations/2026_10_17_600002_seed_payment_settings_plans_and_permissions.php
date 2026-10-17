<?php

use App\Support\PaymentSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds payment settings (insert-if-missing: every gateway disabled, so the
 * mock gateway stays active until credentials are entered), three INACTIVE
 * example subscription plans for the admin to price and switch on, and the
 * new Staff permission keys for the super-admin system role.
 */
return new class extends Migration
{
    protected array $keys = [
        'payment-intents.view', 'payment-intents.create', 'payment-intents.edit', 'payment-intents.delete',
        'payment-events.view', 'payment-events.create', 'payment-events.edit', 'payment-events.delete',
        'subscription-plans.view', 'subscription-plans.create', 'subscription-plans.edit', 'subscription-plans.delete',
        'subscriptions.view', 'subscriptions.create', 'subscriptions.edit', 'subscriptions.delete',
        'payment-settings.manage',
    ];

    public function up(): void
    {
        foreach (PaymentSettings::DEFAULTS as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        if (Schema::hasTable('subscription_plans') && ! DB::table('subscription_plans')->exists()) {
            $plans = [
                ['Dialysis shuttle pass', 'dialysis-shuttle-pass', 'shuttle_pass', 'Unlimited scheduled shuttle trips to and from your dialysis sessions, billed monthly.', 1200, 10, ['Priority dispatch for recurring trips', 'No booking fee', '10% off ad-hoc trips']],
                ['Chronic medication monthly', 'chronic-medication-monthly', 'chronic_medication', 'Your chronic medication delivered every month, with a member discount on pharmacy orders.', 99, 5, ['Monthly refill reminder', 'Free delivery on refills', '5% off pharmacy orders']],
                ['Telehealth membership', 'telehealth-membership', 'telehealth_membership', 'Discounted virtual consults and priority matching with an on-demand doctor.', 149, 15, ['15% off consults', 'Priority "See a doctor now" matching']],
            ];
            foreach ($plans as $i => [$name, $slug, $scope, $desc, $amount, $pct, $benefits]) {
                DB::table('subscription_plans')->insert([
                    'name' => $name, 'slug' => $slug, 'scope' => $scope, 'description' => $desc,
                    'amount' => $amount, 'frequency' => 'monthly', 'cycles' => 0, 'discount_percent' => $pct,
                    'benefits' => json_encode($benefits), 'is_active' => false, 'sort_order' => $i,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $roleId = DB::table('roles')->where('slug', 'super-admin')->value('id');
        if (! $roleId) {
            return;
        }

        foreach ($this->keys as $key) {
            if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_key', $key)->exists()) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_key' => $key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_keys(PaymentSettings::DEFAULTS))->delete();

        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission_key', $this->keys)->delete();
        }
    }
};
