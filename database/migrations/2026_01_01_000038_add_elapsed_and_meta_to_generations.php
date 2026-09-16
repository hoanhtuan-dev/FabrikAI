<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedInteger('elapsed_ms')->nullable()->after('duration');
            $table->json('meta')->nullable()->after('elapsed_ms');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['elapsed_ms', 'meta']);
        });
    }
};
