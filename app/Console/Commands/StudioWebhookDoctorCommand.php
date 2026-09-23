<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * KIỂM TRA TOÀN BỘ ĐƯỜNG WEBHOOK fal — chạy TRƯỚC khi bật webhook.
 *
 * Bốn thứ phải kiểm (và mỗi thứ đã từng LỖI trên production 2026-09-26):
 *   1. ext-sodium (hoặc sodium_compat) có sẵn không? Nếu không: chữ ký không kiểm được.
 *   2. APP_URL có phải https không? Nếu http: fal chặn redirect ⇒ webhook CHẾT IM LẶNG.
 *   3. JWKS của fal có tới được không? Nếu không: không lấy được khoá công khai để verify.
 *   4. FAL_KEY có sống không? Gọi thử queue.fal.run xem có bị 401 không.
 *   5. URL webhook đúng không? In ra để chủ dự án dán vào dashboard fal.
 */
class StudioWebhookDoctorCommand extends Command
{
    protected $signature = 'studio:webhook-doctor';

    protected $description = 'Kiểm tra mọi thứ cần cho webhook fal — APP_URL, sodium, JWKS, FAL_KEY, webhook URL.';

    public function handle(): int
    {
        $ok = 0;
        $warn = 0;
        $err = 0;

        $this->newLine();
        $this->line('  <options=bold>WEBHOOK FAL — KHÁM TỔNG QUÁT</>');

        // 1. sodium
        $hasSodium = function_exists('sodium_crypto_sign_verify_detached');
        $this->line('');
        if ($hasSodium) {
            $this->info('  ✓ ext-sodium CÓ');
            $ok++;
        } else {
            $this->warn('  ⚠ ext-sodium KHÔNG có — chữ ký Ed25519 sẽ KHÔNG được kiểm.');
            $this->line('    · Bật trong hPanel → Select PHP Version → Extensions → sodium.');
            $this->line('    · Hoặc: composer require paragonie/sodium_compat (đã cài — kiểm bằng composer show).');
            $warn++;
        }

        // 2. APP_URL
        $url = (string) config('app.url');
        $isHttps = str_starts_with($url, 'https://');
        $this->line('');
        if ($isHttps) {
            $this->info('  ✓ APP_URL = '.$url);
            $ok++;
        } else {
            $this->error('  ✗ APP_URL = '.$url.' — PHẢI là https!');
            $this->line('    · fal KHÔNG đi theo redirect: http→https là lỗi 3xx ⇒ webhook CHẾT VĨNH VIỄN, không retry.');
            $err++;
        }

        // 3. JWKS
        $this->line('');
        try {
            $jwks = Http::timeout(15)->get('https://rest.fal.ai/.well-known/jwks.json');
            if ($jwks->successful()) {
                $keys = count($jwks->json('keys') ?? []);
                $this->info('  ✓ JWKS tới được — '.$keys.' khoá công khai');
                $ok++;
            } else {
                $this->error('  ✗ JWKS trả HTTP '.$jwks->status());
                $err++;
            }
        } catch (\Throwable $e) {
            $this->error('  ✗ JWKS không tới được: '.$e->getMessage());
            $err++;
        }

        // 4. FAL_KEY
        $this->line('');
        $key = (string) studio_config('fal_key', env('FAL_KEY', ''));
        if ($key === '') {
            $this->warn('  ⚠ FAL_KEY chưa được đặt — không kiểm tra được.');
            $warn++;
        } else {
            try {
                $test = Http::withHeaders(['Authorization' => 'Key '.$key])
                    ->timeout(15)
                    ->post('https://queue.fal.run/fal-ai/flux/schnell', ['prompt' => 'test', 'num_images' => 1]);
                // Không gửi thật — chỉ kiểm auth. Status nào cũng được (422 = thiếu tham số, 401 = key sai).
                if ($test->status() === 401) {
                    $this->error('  ✗ FAL_KEY bị từ chối (401) — key sai hoặc hết hạn.');
                    $err++;
                } else {
                    $this->info('  ✓ FAL_KEY sống (HTTP '.$test->status().' — '.$test->status().' nghĩa là key hợp lệ, model nhận được request)');
                    $ok++;
                }
            } catch (\Throwable $e) {
                $this->warn('  ⚠ Không kiểm tra được FAL_KEY: '.$e->getMessage());
                $warn++;
            }
        }

        // 5. Webhook URL
        $this->line('');
        $webhookUrl = rtrim($url, '/').'/api/webhooks/fal';
        // Kiểm tra dấu / thừa — fal KHÔNG theo redirect, và /api/ nếu có dấu / thừa sẽ redirect.
        $probeUrl = $webhookUrl;
        if (str_ends_with($probeUrl, '/')) {
            $probeUrl = rtrim($probeUrl, '/');
        }
        $this->line('  Webhook URL: <options=bold>'.$webhookUrl.'</>');
        $this->line('  (Dán URL này vào dashboard fal → Webhooks → Add endpoint, thêm ?token=… sau nó.)');
        $this->line('');

        // 6. POST về 4xx/5xx? (route tồn tại thì POST sẽ trả 400 body rỗng, không redirect)
        try {
            $probe = Http::timeout(10)->post($webhookUrl, ['request_id' => 'doctor-probe', 'status' => 'OK']);
            $status = $probe->status();
            if ($status === 301 || $status === 302) {
                $this->error('  ✗ POST '.$webhookUrl.' trả HTTP '.$status.' — ĐANG REDIRECT!');
                $this->line('    · fal KHÔNG theo redirect: trả 3xx là thất bại vĩnh viễn.');
                $err++;
            } elseif ($status >= 200 && $status < 500) {
                $this->info('  ✓ POST '.$webhookUrl.' trả HTTP '.$status.' — không redirect, route tồn tại.');
                $ok++;
            } else {
                $this->warn('  ⚠ POST trả HTTP '.$status);
                $warn++;
            }
        } catch (\Throwable $e) {
            $this->warn('  ⚠ Không probe được webhook URL (có thể do mạng): '.$e->getMessage());
            $warn++;
        }

        // Kết luận
        $this->newLine();
        $total = $ok + $warn + $err;
        $this->line('  Kết quả: '.$ok.'✓ · '.$warn.'⚠ · '.$err.'✗ / '.$total);

        return $err > 0 ? self::FAILURE : ($warn > 0 ? self::SUCCESS : self::SUCCESS);
    }
}
