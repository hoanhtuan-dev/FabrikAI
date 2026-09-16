<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_outfit_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('style')->nullable();                    // phong cách hiện tại (nhập tự do)
            $table->unsignedTinyInteger('ornament_level')->default(0); // mức trang trí hiện tại
            $table->unsignedTinyInteger('creative_level')->default(8); // mức sáng tạo hiện tại
            $table->json('presets')->nullable();                    // danh sách preset phong cách [{name, style, ornament, creative}]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_outfit_settings');
    }
};
