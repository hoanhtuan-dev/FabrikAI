<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Corrects the prompts_history_id foreign key that pointed at the wrong table.
return new class extends Migration
{
    public function up(): void
    {
        // generations was empty (all inserts failed), so recreate it safely.
        Schema::dropIfExists('generations');

        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prompts_history_id')->nullable()->constrained('prompts_history')->nullOnDelete();
            $table->string('type')->default('image');
            $table->string('status')->default('pending')->index();
            $table->text('prompt')->nullable();
            $table->string('media_url')->nullable();
            $table->string('base_image')->nullable();
            $table->string('mask_image')->nullable();
            $table->string('job_id')->nullable();
            $table->text('error')->nullable();
            $table->integer('credits_cost')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
