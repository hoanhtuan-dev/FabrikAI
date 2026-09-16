<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('studio_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('provider');        // qwen | dashscope | wan | gemini | fal | replicate | veo | qwen_edit | kling
            $table->string('label');           // e.g. "Qwen Token-Plan", "Qwen Pay-As-You-Go"
            $table->text('value');             // the API key (encrypted on save)
            $table->string('kind')->nullable();// hint for host routing: 'plan' | 'paygo' | null
            $table->json('scopes')->nullable();// ['*'] (all) OR list of model_ids / groups it may serve
            $table->integer('priority')->default(0);
            $table->boolean('enabled')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['provider', 'enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_api_keys');
    }
};
