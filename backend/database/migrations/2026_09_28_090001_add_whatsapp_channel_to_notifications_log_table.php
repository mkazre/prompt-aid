<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Widen from enum(['sms','email','push']) to a plain string so a
        // 'whatsapp' channel (and any future channel) doesn't need another
        // schema change — Laravel 12 rewrites this natively on sqlite/mysql,
        // no doctrine/dbal required.
        Schema::table('notifications_log', function (Blueprint $table) {
            $table->string('channel')->change();
        });
    }

    public function down(): void
    {
        Schema::table('notifications_log', function (Blueprint $table) {
            $table->enum('channel', ['sms', 'email', 'push'])->change();
        });
    }
};
