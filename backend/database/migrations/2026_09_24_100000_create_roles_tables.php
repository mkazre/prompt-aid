<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // True for the small built-in set that mirrors the User::role
            // enum (see RoleSeeder) — those can be edited but not deleted,
            // since removing one would orphan the enum value it backs.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission_key');
            $table->timestamps();

            $table->unique(['role_id', 'permission_key']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Nullable and separate from `role` on purpose — `role` still
            // drives panel access + tenant scoping (ScopesToClinicOrDoctor
            // etc.), this only drives the fine-grained permission checks in
            // ChecksPermissions. Null means "use the enum role's defaults".
            $table->foreignId('role_id')->nullable()->after('role')->constrained('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
