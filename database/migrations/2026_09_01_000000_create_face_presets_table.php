<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description');          // English description used in the swap prompt
            $table->string('ethnicity')->nullable();
            $table->string('image')->nullable();  // optional reference face image (/storage/... or /samples/...)
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_presets');
    }
};
