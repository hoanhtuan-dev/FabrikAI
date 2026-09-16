<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('search_text')->nullable()->after('description')->index();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->text('search_text')->nullable()->after('body')->index();
        });

        // Backfill existing rows so search-by-no-diacritics works immediately.
        $this->backfill('products', ['name', 'short_description', 'description', 'brand', 'sku']);
        $this->backfill('posts', ['title', 'excerpt', 'body']);
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('search_text'));
        Schema::table('posts', fn (Blueprint $t) => $t->dropColumn('search_text'));
    }

    protected function backfill(string $table, array $columns): void
    {
        $rows = DB::table($table)->get(['id', ...$columns]);
        foreach ($rows as $row) {
            $parts = [];
            foreach ($columns as $col) {
                $parts[] = $row->{$col} ?? '';
            }
            DB::table($table)->where('id', $row->id)->update([
                'search_text' => normalize_vn(implode(' ', $parts)),
            ]);
        }
    }
};
