<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom provider registry — modeled after DeepSeek Harness's configurable-provider
 * directory: a stored profile that names its own protocol + base URL + auth style,
 * so any OpenAI-compatible (or DashScope-compatible) endpoint becomes callable
 * without code changes. Built-in providers (qwen, gemini, fal…) stay hardcoded;
 * this table only ADDS user-declared routes on top.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('studio_providers')) {
            return;
        }

        Schema::create('studio_providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();        // provider route key, e.g. 'openrouter' — models/keys reference this
            $table->string('name');                       // display name, e.g. 'OpenRouter'
            $table->string('protocol', 30)->default('openai'); // wire protocol: openai | dashscope | gemini
            $table->string('base_url');                   // endpoint root, e.g. https://openrouter.ai/api/v1
            $table->string('auth_style', 20)->default('bearer'); // bearer | x-goog-api-key
            $table->string('api_key_ref', 60)->nullable(); // slug of the StudioApiKey provider holding the secret
            $table->boolean('enabled')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_providers');
    }
};
