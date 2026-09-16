<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stylist_garment_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique(); // id dùng cho route /garment/{slug}
            $table->string('name', 120);
            $table->string('emoji', 8)->default('');
            $table->string('color', 20)->default('#4a7a90');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('stylist_questions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('question', 500); // có thể chứa placeholder {name}
            $table->json('options');         // danh sách lựa chọn
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stylist_questions');
        Schema::dropIfExists('stylist_garment_types');
    }
};
