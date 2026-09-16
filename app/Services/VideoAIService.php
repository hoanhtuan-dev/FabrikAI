<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Video catwalk render (Wan AI / QwenCloud via DashScope async video-synthesis).
 *
 * When a Wan / DashScope key is configured it submits a task, polls until SUCSEEDED
 * and downloads the video. Otherwise it returns the bundled demo MP4 (stub).
 */
class VideoAIService
{
    public function render(string $prompt, string $imageUrl, string $cameraPreset, ?string $resolution = null, ?string $duration = null, ?int $generationId = null, ?string $model = null, ?string $provider = null): string
    {
        // Failover: try Token Plan first, then Pay-As-You-Go on a quota error (studio_qwen_credentials('video')).
        $keys = studio_qwen_credentials('video');
        if ($keys) {
            $last = null;
            foreach ($keys as $key) {
                try {
                    return $this->callDashscopeVideo($prompt, $imageUrl, $cameraPreset, $resolution, $duration, $key, $generationId, $model, $provider);
                } catch (\Throwable $e) {
                    $last = $e->getMessage();
                    capture_provider_quota_reset($last);
                    $lower = strtolower((string) $last);
                    // Rotate to the next key/host on quota exhaustion OR a model-not-exist (wrong host/key type),
                    // so a pay-go error falls through to the plan slot (and vice versa) before giving up.
                    if (! is_qwen_quota_error($last) && ! str_contains($lower, 'model not exist') && ! str_contains($lower, 'invalidparameter')) {
                        throw $e;
                    }
                }
            }
            if ($last) {
                throw new \RuntimeException($last);
            }
        }

        // No key configured -> stub (bundled demo video).
        return '/samples/studio-catwalk.mp4';
    }

    protected function callDashscopeVideo(string $prompt, string $imageUrl, string $cameraPreset, ?string $resolution, ?string $duration, string $key, ?int $generationId = null, ?string $modelOverride = null, ?string $provider = null): string
    {
        $base = dashscope_base_url($key).'/api/v1';
        $model = $modelOverride ?: (string) studio_config('video_model', 'wan2.5-t2v');
        $size = $this->videoSize($resolution);

        $prompt = trim($prompt);
        $cameraPreset = trim($cameraPreset);
        // Avoid duplication: only append the "Kịch bản quay" camera action if the prompt doesn't already
        // specify a camera / camera-movement keyword.
        if ($cameraPreset !== '' && ! $this->promptHasCamera($prompt)) {
            $prompt = trim($prompt.' '.$cameraPreset);
        }

        $input = ['prompt' => $prompt];
        if ($imageUrl && str_starts_with($imageUrl, '/')) {
            $abs = url($imageUrl);
            // i2v models require input.media items whose 'type' is the starting frame role ('first_frame').
            $input['img_url'] = $abs;
            $input['media'] = [['type' => 'first_frame', 'url' => $abs]];
        }

        $t0 = microtime(true);
        $submit = Http::withToken($key)->withHeaders(['X-DashScope-Async' => 'enable'])->timeout(60)
            ->post($base.'/services/aigc/video-generation/video-synthesis', [
                'model' => $model,
                'input' => $input,
                'parameters' => ['size' => $size, 'duration' => (int) ($duration ?: 10), 'watermark' => false, 'prompt_extend' => true],
            ]);

        if (! $submit->successful()) {
            capture_provider_quota_reset((string) $submit->body());
            $body = (string) $submit->body();
            $lower = strtolower($body);
            if (str_contains($lower, 'model not exist') || str_contains($lower, 'invalidparameter') || str_contains($lower, 'model_not_supported')) {
                throw new \RuntimeException('Model video không tồn tại trên nhà cung cấp. Model_id không hợp lệ cho provider đã chọn — '
                    .'VD model video HỢP LỆ cho DashScope/Wan: wan2.5-t2v, wan2.2-i2v, wan2.5-i2v, wan2.1-i2v-turbo, happyhorse-1.1-i2v. '
                    .'Kiểm tra lại model_id trong Model Registry (không dùng tên model của provider khác như Kling nếu chỉ có key DashScope). '.Str::limit($body, 160));
            }
            throw new \RuntimeException('DashScope video ('.$submit->status().'): '.Str::limit($body, 240));
        }

        $taskId = data_get($submit->json(), 'output.task_id');
        if (! $taskId) {
            throw new \RuntimeException('DashScope không trả về task_id video.');
        }

        logger()->info('Video task submitted', ['task_id' => $taskId, 'model' => $model, 'size' => $size, 'duration' => (int) ($duration ?: 10), 'wait_s' => round(microtime(true) - $t0, 2)]);

        $deadline = microtime(true) + 480; // 8 min cap: legit videos (~90-180s) finish; slow providers get room
        $lastStatus = '';
        $lastWarn = 0;
        $setPhase = function (string $phase) use ($generationId) {
            if (! $generationId) { return; }
            $g = \App\Models\Generation::find($generationId);
            if ($g) { $m = (array) ($g->meta ?? []); $m['video_phase'] = $phase; $g->update(['meta' => $m]); }
        };
        $setPhase('submitted');

        while (microtime(true) < $deadline) {
            sleep(5);

            $q = Http::withToken($key)->timeout(30)->get($base.'/tasks/'.$taskId);

            if (! $q->successful()) {
                capture_provider_quota_reset((string) $q->body());
                throw new \RuntimeException('DashScope ('.$q->status().'): '.Str::limit((string) $q->body(), 240));
            }

            $status = (string) data_get($q->json(), 'output.task_status');
            if ($status !== $lastStatus && $status !== '') {
                $setPhase(strtolower((string) $status));
                logger()->info('Video task status', ['task_id' => $taskId, 'status' => $status, 'elapsed_s' => round(microtime(true) - $t0, 2)]);
                $lastStatus = $status;
            } elseif ($status === 'RUNNING' && microtime(true) - $lastWarn > 60) {
                logger()->warning('Video task still queued/running in provider', ['task_id' => $taskId, 'elapsed_s' => round(microtime(true) - $t0, 2)]);
                $lastWarn = microtime(true);
            }

            if ($status === 'SUCCEEDED') {
                $url = data_get($q->json(), 'output.video_url') ?: data_get($q->json(), 'output.results.0.url');
                if (! $url) {
                    throw new \RuntimeException('DashScope hoàn tất nhưng không trả video.');
                }

                $contents = @file_get_contents($url);
                if (! $contents) {
                    throw new \RuntimeException('Không tải được video.');
                }

                $name = Str::uuid().'.mp4';
                Storage::disk('public')->put('studio/'.$name, $contents);
                logger()->info('Video done', ['task_id' => $taskId, 'total_s' => round(microtime(true) - $t0, 2)]);

                return '/storage/studio/'.$name;
            }

            if ($status === 'FAILED') {
                $msg = (string) data_get($q->json(), 'output.message', 'Tạo video thất bại.');
                logger()->error('Video task FAILED', ['task_id' => $taskId, 'message' => $msg, 'elapsed_s' => round(microtime(true) - $t0, 2)]);
                throw new \RuntimeException('DashScope: '.$msg);
            }
        }

        throw new \RuntimeException('Hết thời gian chờ tạo video (task '.$taskId.', '.(int) round(microtime(true) - $t0).'s).');
    }

    protected function promptHasCamera(string $prompt): bool
    {
        $lower = strtolower($prompt);
        // Only skip appending the "Kịch bản quay" when the prompt genuinely describes a CONFLICTING camera
        // technique (rare in image/editorial prompts). Generic style words ("slow motion", "4k", "shot",
        // "runway") must NOT suppress the chosen scene — the base prompt no longer hardcodes a camera.
        foreach (['orbit', 'tracking', 'dolly shot', 'panning', 'crane shot', 'aerial shot', 'drone shot', 'pov', 'arc shot', 'zoom in', 'zoom out', 'tilt-up', 'tilt down', 'jib'] as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return false;
    }

    protected function videoSize(?string $resolution): string
    {
        return match ($resolution) {
            '480' => '960*480',
            '1080' => '1920*1080',
            default => '1280*720',
        };
    }
}
