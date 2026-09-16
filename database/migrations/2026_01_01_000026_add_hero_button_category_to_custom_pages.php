<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_pages', function (Blueprint $table) {
            $table->foreignId('hero_button_category_id')->nullable()->after('hero_button_link')->constrained('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('custom_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hero_button_category_id');
        });
    }
};
