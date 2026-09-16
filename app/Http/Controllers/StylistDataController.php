<?php

namespace App\Http\Controllers;

use App\Models\StylistGarmentType;
use App\Models\StylistQuestion;
use App\Services\StylistCatalog;
use Illuminate\Http\Request;

/**
 * Quản lý data của ✨ Trợ lý thiết kế (Thuật sỹ): loại trang phục + ma trận câu hỏi.
 * Trang Vue: /studio/stylist-data.
 */
class StylistDataController extends Controller
{
    /** Trả catalog và tự tạo bảng + seed nếu chưa có (hosting chưa chạy migrate). */
    protected function catalog(): StylistCatalog
    {
        $catalog = app(StylistCatalog::class);
        $catalog->ensureTables();
        return $catalog;
    }

    public function data(): \Illuminate\Http\JsonResponse
    {
        $catalog = $this->catalog();
        $defaultTypes = collect($catalog->defaultGarmentTypes())
            ->map(fn ($t, $i) => ['id' => null, 'slug' => $t['id'], 'name' => $t['name'], 'emoji' => $t['emoji'], 'color' => $t['color'], 'sort_order' => $i])
            ->values();
        $defaultQuestions = collect($catalog->defaultQuestions())
            ->map(fn ($q, $i) => ['id' => null, 'key' => $q['key'], 'q' => $q['q'], 'opts' => $q['opts'], 'sort_order' => $i])
            ->values();

        try {
            $types = StylistGarmentType::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'slug' => $r->slug, 'name' => $r->name, 'emoji' => $r->emoji, 'color' => $r->color, 'sort_order' => $r->sort_order])
                ->values();
            if ($types->isEmpty()) {
                $types = $defaultTypes;
            }
        } catch (\Throwable $e) {
            $types = $defaultTypes;
        }

        try {
            $questions = StylistQuestion::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'key' => $r->key, 'q' => $r->question, 'opts' => $r->options ?? [], 'sort_order' => $r->sort_order])
                ->values();
            if ($questions->isEmpty()) {
                $questions = $defaultQuestions;
            }
        } catch (\Throwable $e) {
            $questions = $defaultQuestions;
        }

        return response()->json(['types' => $types, 'questions' => $questions]);
    }

    public function presets(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['presets' => $this->catalog()->presets()]);
    }

    public function deletePreset(int $id): \Illuminate\Http\JsonResponse
    {
        $this->catalog()->deletePreset($id);
        return response()->json(['ok' => true]);
    }

    public function saveType(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->catalog();

        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'emoji' => ['nullable', 'string', 'max:8'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $dup = StylistGarmentType::where('slug', $d['slug'])->where('id', '!=', $d['id'] ?? 0)->exists();
            if ($dup) {
                return response()->json(['ok' => false, 'message' => 'Slug đã tồn tại.'], 422);
            }

            if (! empty($d['id'])) {
                $m = StylistGarmentType::findOrFail($d['id']);
            } else {
                $m = new StylistGarmentType();
                $m->sort_order = (int) StylistGarmentType::max('sort_order') + 1;
            }

            $m->slug = $d['slug'];
            $m->name = $d['name'];
            $m->emoji = $d['emoji'] ?? '';
            $m->color = $d['color'] ?? '#4a7a90';
            $m->save();

            return response()->json(['ok' => true, 'type' => [
                'id' => $m->id, 'slug' => $m->slug, 'name' => $m->name, 'emoji' => $m->emoji, 'color' => $m->color, 'sort_order' => $m->sort_order,
            ]]);
        } catch (\Throwable $e) {
            // M03: vòng $dup ở trên không atomic — request thứ hai (đồng thời) ném ConstraintViolation.
            // Trả 422 kèm hướng dẫn thay vì 500 chung chung.
            if (studio_is_unique_violation($e)) {
                return response()->json(['ok' => false, 'message' => 'Mục này đã tồn tại (có thể do thao tác đồng thời). Vui lòng tải lại và thử lại.'], 422);
            }

            return studio_fail($e, 'Lưu mục stylist', 500);
        }
    }

    public function deleteType(int $id): \Illuminate\Http\JsonResponse
    {
        $this->catalog();
        try {
            StylistGarmentType::where('id', $id)->delete();
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return studio_fail($e, 'Xóa mục stylist', 500);
        }
    }

    public function saveQuestion(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->catalog();

        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'q' => ['required', 'string', 'max:500'],
            'opts' => ['nullable', 'array'],
            'opts.*' => ['string', 'max:200'],
        ]);

        try {
            $dup = StylistQuestion::where('key', $d['key'])->where('id', '!=', $d['id'] ?? 0)->exists();
            if ($dup) {
                return response()->json(['ok' => false, 'message' => 'Key đã tồn tại.'], 422);
            }

            if (! empty($d['id'])) {
                $m = StylistQuestion::findOrFail($d['id']);
            } else {
                $m = new StylistQuestion();
                $m->sort_order = (int) StylistQuestion::max('sort_order') + 1;
            }

            $m->key = $d['key'];
            $m->question = $d['q'];
            $m->options = array_values(array_filter((array) ($d['opts'] ?? []), fn ($v) => $v !== null && $v !== ''));
            $m->save();

            return response()->json(['ok' => true, 'question' => [
                'id' => $m->id, 'key' => $m->key, 'q' => $m->question, 'opts' => $m->options, 'sort_order' => $m->sort_order,
            ]]);
        } catch (\Throwable $e) {
            // M03: vòng $dup ở trên không atomic — request thứ hai (đồng thời) ném ConstraintViolation.
            // Trả 422 kèm hướng dẫn thay vì 500 chung chung.
            if (studio_is_unique_violation($e)) {
                return response()->json(['ok' => false, 'message' => 'Mục này đã tồn tại (có thể do thao tác đồng thời). Vui lòng tải lại và thử lại.'], 422);
            }

            return studio_fail($e, 'Lưu mục stylist', 500);
        }
    }

    public function deleteQuestion(int $id): \Illuminate\Http\JsonResponse
    {
        $this->catalog();
        try {
            StylistQuestion::where('id', $id)->delete();
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return studio_fail($e, 'Xóa mục stylist', 500);
        }
    }
}
