<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mở rộng bảng `projects` để hỗ trợ luồng công việc Designer trong Studio:
 *  - status: draft → in_progress → review → approved → archived
 *  - brief / deadline / thumbnail_url / tags / color / sort / archived
 *  - settings (JSON): tuỳ chọn render mặc định của dự án
 *  - started_at / completed_at: timestamps theo trạng thái (cho dashboard)
 *
 * Bổ sung bảng pivot `project_assets` để ghim StudioAsset vào dự án.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('status')->default('draft')->index()->after('base_concept');
            $table->text('brief')->nullable()->after('status');
            $table->datetime('deadline')->nullable()->after('brief');
            $table->string('thumbnail_url')->nullable()->after('deadline');
            $table->json('tags')->nullable()->after('thumbnail_url');
            $table->string('color')->nullable()->after('tags');
            $table->integer('sort')->default(0)->after('color');
            $table->boolean('archived')->default(false)->after('sort');
            $table->json('settings')->nullable()->after('archived');
            $table->datetime('started_at')->nullable()->after('settings');
            $table->datetime('completed_at')->nullable()->after('started_at');
        });

        if (! Schema::hasTable('project_assets')) {
            Schema::create('project_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('studio_asset_id')->constrained()->cascadeOnDelete();
                $table->string('role')->default('reference'); // reference | model | pose | background
                $table->integer('sort')->default(0);
                $table->timestamps();
                $table->unique(['project_id', 'studio_asset_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_assets');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status', 'brief', 'deadline', 'thumbnail_url', 'tags',
                'color', 'sort', 'archived', 'settings', 'started_at', 'completed_at',
            ]);
        });
    }
};
