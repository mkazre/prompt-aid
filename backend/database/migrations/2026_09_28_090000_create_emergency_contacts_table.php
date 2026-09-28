<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('phone');
            $table->string('tel_url');
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed with the numbers that were previously hardcoded across
        // emergency.blade.php / emergency-results.blade.php / promptaid.js /
        // contact.blade.php, so nothing goes blank on deploy.
        $now = now();
        DB::table('emergency_contacts')->insert([
            ['label' => 'Ambulance', 'phone' => '10177', 'tel_url' => 'tel:10177', 'is_primary' => true, 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Netcare 911', 'phone' => '082 911', 'tel_url' => 'tel:082911', 'is_primary' => false, 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'ER24', 'phone' => '084 124', 'tel_url' => 'tel:084124', 'is_primary' => false, 'sort_order' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Poison Information', 'phone' => '0861 555 777', 'tel_url' => 'tel:0861555777', 'is_primary' => false, 'sort_order' => 4, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Childline', 'phone' => '116', 'tel_url' => 'tel:116', 'is_primary' => false, 'sort_order' => 5, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'GBV Command Centre', 'phone' => '0800 428 428', 'tel_url' => 'tel:0800428428', 'is_primary' => false, 'sort_order' => 6, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Suicide Crisis Line', 'phone' => '0800 567 567', 'tel_url' => 'tel:0800567567', 'is_primary' => false, 'sort_order' => 7, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
    }
};
