<?php

namespace App\Http\Controllers;

use App\Services\DesignAgentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DesignAgentController extends Controller
{
    public function __construct(private readonly DesignAgentService $agents) {}

    public function radar(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'region' => ['nullable', 'string', 'in:all,hcm,hanoi,danang'],
        ]);

        return response()->json($this->agents->radar(
            $request->user(), (string) ($data['region'] ?? 'all')
        ));
    }

    public function collection(Request $request): \Illuminate\Http\JsonResponse
    {
        $payload = $request->only([
            'prompt', 'region', 'trend_ids', 'brief', 'size_distribution',
        ]);
        $payload['prompt'] = trim((string) ($payload['prompt'] ?? ''));
        $payload['brief'] = trim((string) ($payload['brief'] ?? ''));
        $payload['trend_ids'] = array_values(array_filter(
            array_map('trim', (array) ($payload['trend_ids'] ?? [])),
            'strlen',
        ));
        $payload['size_distribution'] = is_array($payload['size_distribution'] ?? null)
            ? $payload['size_distribution'] : [];

        $validator = Validator::make($payload, [
            'prompt' => ['required', 'string', 'max:2000'],
            'region' => ['nullable', 'string', 'in:all,hcm,hanoi,danang'],
            'trend_ids' => ['nullable', 'array', 'max:10', 'distinct'],
            'trend_ids.*' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,79}$/'],
            'brief' => ['nullable', 'string', 'max:4000'],
            'size_distribution' => ['nullable', 'array', 'max:20'],
            'size_distribution.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $validator->after(function ($validator) use ($payload) {
            if (mb_strlen($payload['prompt'] ?? '') < 3) {
                $validator->errors()->add('prompt', 'Mô tả bộ sưu tập phải có ít nhất 3 ký tự.');
            }

            $ids = $payload['trend_ids'] ?? [];
            if (count(array_unique($ids)) !== count($ids)) {
                $validator->errors()->add('trend_ids', 'Mỗi xu hướng chỉ được chọn một lần.');
            }
            $known = $this->agents->trendIds();
            foreach ($ids as $id) {
                if (! in_array($id, $known, true)) {
                    $validator->errors()->add('trend_ids', 'Có xu hướng không tồn tại trong TrendRadar.');
                    break;
                }
            }

            foreach (array_keys($payload['size_distribution'] ?? []) as $size) {
                if (! preg_match('/^[A-Za-z0-9]{1,8}$/', (string) $size)) {
                    $validator->errors()->add('size_distribution', 'Mã size chỉ được chứa chữ và số (tối đa 8 ký tự).');
                    break;
                }
            }
        });

        $data = $validator->validate();
        $data['size_distribution'] = collect($data['size_distribution'] ?? [])
            ->mapWithKeys(fn ($count, $size) => [strtoupper(substr((string) $size, 0, 8)) => (int) $count])
            ->all();

        return response()->json($this->agents->collectionBrief($data, $request->user()));
    }
}
