<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('studio_models', function (Blueprint $table) {
            $table->id();
            $table->string('group');                 // image | video | inference
            $table->string('name');                  // display name
            $table->string('provider');              // wan | qwen | dashscope | gemini | fal | replicate | veo | ...
            $table->string('model_id');              // API model id
            $table->string('api_key_ref')->nullable(); // which api key slot (provider / custom ref)
            $table->integer('priority')->default(0);   // higher = preferred; auto-fallback on quota error
            $table->boolean('enabled')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['group', 'enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_models');
    }
};
