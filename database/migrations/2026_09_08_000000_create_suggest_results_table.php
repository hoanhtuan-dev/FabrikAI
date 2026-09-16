<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suggest_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            // Ảnh nguồn đã phân tích
            $table->string('reference_url', 2048)->nullable()->comment('URL ảnh nguồn đã phân tích');
            $table->string('reference_thumb', 2048)->nullable()->comment('Thumbnail 160px WebP của ảnh nguồn');

            // Kết quả phân tích (từ StyleSuggestService::finalize)
            $table->json('styles')->nullable()->comment('Phong cách: ["Minimalist", "Korean street"]');
            $table->string('background', 200)->nullable();
            $table->string('pose', 200)->nullable();
            $table->string('fabric', 200)->nullable();
            $table->string('silhouette', 200)->nullable();
            $table->string('camera', 200)->nullable();
            $table->string('garment_type', 200)->nullable();
            $table->string('embellishment', 200)->nullable();
            $table->text('detail_notes')->nullable();
            $table->json('color_palette')->nullable()->comment('Bảng màu: ["ivory", "navy", "gold"]');

            // Prompt (đã enrich)
            $table->text('image_prompt_en')->nullable();
            $table->text('prompt_vi')->nullable();
            $table->text('video_prompt_en')->nullable();
            $table->text('negative_prompt')->nullable();
            $table->json('keywords')->nullable()->comment('Từ khoá: ["fashion", "studio", "minimal"]');

            // Metadata phân tích
            $table->integer('creative_level')->default(6);
            $table->integer('adherence')->default(8);
            $table->integer('detail_level')->default(8);
            $table->json('category')->nullable()->comment('Category injection object từ CreativeDirection');

            // Tham chiếu — tái sử dụng
            $table->timestamp('applied_at')->nullable()->comment('Lần cuối áp dụng prompt này vào Tạo ảnh');
            $table->integer('apply_count')->default(0)->comment('Số lần đã áp dụng');

            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'idx_suggest_user_created');
            $table->index('project_id', 'idx_suggest_project');
            $table->index('garment_type', 'idx_suggest_garment_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggest_results');
    }
};
