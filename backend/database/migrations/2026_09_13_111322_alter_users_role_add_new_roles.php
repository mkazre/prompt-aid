<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $roles = [
        'super_admin', 'clinic_admin', 'doctor', 'driver', 'patient', 'third_party', 'pharmacy_admin',
    ];

    protected array $oldRoles = [
        'super_admin', 'clinic_admin', 'doctor', 'driver', 'patient',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite enforces enum() as a CHECK constraint that can't be altered
            // in place — rebuild the table (SQLite's standard 12-step recipe).
            DB::statement('CREATE TABLE users_new AS SELECT * FROM users');
            Schema::drop('users');

            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('phone')->nullable()->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->enum('role', $this->roles)->default('patient');
                $table->string('avatar')->nullable();
                $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
                $table->rememberToken();
                $table->timestamps();
            });

            DB::statement('INSERT INTO users SELECT * FROM users_new');
            Schema::drop('users_new');

            return;
        }

        // MySQL / others support MODIFY natively.
        DB::statement("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'patient'");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('CREATE TABLE users_old AS SELECT * FROM users');
            Schema::drop('users');

            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('phone')->nullable()->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->enum('role', $this->oldRoles)->default('patient');
                $table->string('avatar')->nullable();
                $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
                $table->rememberToken();
                $table->timestamps();
            });

            DB::statement('INSERT INTO users SELECT * FROM users_old');
            Schema::drop('users_old');
        }
    }
};
