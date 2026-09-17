<?php

namespace Database\Seeders;

use App\Models\Preset;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->guardProductionSeeding();

        // ── Luôn cần: tài khoản quản trị ──
        // (Không seed settings: mọi giá trị studio_* đều có default trong config/studio.php và chỉ
        //  được ghi khi người dùng lưu ở trang Cài đặt — seed sẵn chỉ tạo dữ liệu thừa.)
        $this->users();

        // ── Gói cước (plans) — dữ liệu CẤU HÌNH, không phải demo ──
        $this->call(PlanSeeder::class);

        // ── Nội dung CẤU HÌNH của app (không phải dữ liệu demo) ──
        // Preset thợ may / khuôn mặt / tư thế + thư viện Trợ lý thiết kế. Đây là thứ app cần để chạy,
        // không phải hàng mẫu, nên seed cả ở production.
        $this->presets();
        $this->call(FacePresetSeeder::class);
        $this->call(PosePresetSeeder::class);
        $this->call(StylistCatalogSeeder::class);

        // ── Dự án mẫu: chỉ dev/test (production phải bật SEED_DEMO_DATA=true) ──
        if (! $this->demoDataEnabled()) {
            $this->command?->warn(
                'Production: ĐÃ BỎ QUA dự án mẫu. Đặt SEED_DEMO_DATA=true nếu thật sự muốn. '.
                'Tài khoản quản trị KHÔNG bị ảnh hưởng.'
            );

            return;
        }

        $this->call(StudioProjectSeeder::class);

        $this->command?->info('Seeded FabrikAI successfully.');
    }

    /**
     * Chốt an toàn cho production — chạy TRƯỚC khi ghi bất cứ thứ gì.
     *
     * Repo này là PUBLIC. Mật khẩu hardcode trong seeder coi như **đã bị lộ**: bất kỳ ai đọc repo
     * đều biết email + mật khẩu của tài khoản quyền cao nhất, và trước đây `updateOrCreate` còn
     * GHI ĐÈ lại mật khẩu mỗi lần seed (đổi mật khẩu xong, seed lại là mất).
     *
     * ⇒ Ở production, mật khẩu tài khoản quản trị BẮT BUỘC đến từ env, không có giá trị mặc định.
     * Thiếu env thì NÉM LỖI (fail-closed) thay vì lặng lẽ tạo tài khoản với mật khẩu đã lộ.
     * Ở dev/test vẫn có mặc định cho tiện, nhưng KHÔNG bao giờ ghi đè mật khẩu đã tồn tại.
     */
    protected function guardProductionSeeding(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        foreach (['SEED_SUPER_ADMIN_PASSWORD', 'SEED_ADMIN_PASSWORD'] as $key) {
            if (blank(env($key))) {
                throw new \RuntimeException(
                    "Thiếu {$key} trong .env.\n".
                    'Seeder KHÔNG dùng mật khẩu mặc định cho tài khoản quản trị ở production: '.
                    'repo này là PUBLIC nên mật khẩu hardcode coi như đã bị lộ.\n'.
                    'Đặt '.$key.'=<mật-khẩu-mạnh> rồi chạy lại. (Dữ liệu demo KHÔNG được seed ở production '.
                    'trừ khi bật SEED_DEMO_DATA=true.)'
                );
            }
        }
    }

    /**
     * Mật khẩu dùng khi TẠO MỚI một tài khoản seed (không bao giờ dùng để ghi đè tài khoản đã có).
     */
    protected function seedPassword(string $envKey, string $devDefault): string
    {
        $value = env($envKey);

        return filled($value) ? (string) $value : $devDefault;
    }

    /**
     * Production: chỉ seed dữ liệu demo (sản phẩm mẫu, khách hàng mẫu, đơn hàng mẫu…) khi chủ động
     * bật SEED_DEMO_DATA=true. Mặc định TẮT để một lần `db:seed` nhầm không đổ dữ liệu rác vào
     * hệ thống thật.
     */
    protected function demoDataEnabled(): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        return filter_var(env('SEED_DEMO_DATA', false), FILTER_VALIDATE_BOOL);
    }

    protected function users(): void
    {
        // ── Tài khoản QUẢN TRỊ: firstOrCreate, KHÔNG BAO GIỜ ghi đè mật khẩu đã có ──
        // Bản cũ dùng updateOrCreate + mật khẩu hardcode ⇒ (a) mật khẩu bị lộ vì repo PUBLIC, và
        // (b) chạy lại seeder là RESET mật khẩu về giá trị đã lộ đó. firstOrCreate chỉ tạo khi tài
        // khoản CHƯA tồn tại; người vận hành đổi mật khẩu rồi thì seeder không đụng tới nữa.
        //
        // 'role' / 'credits_balance' / 'email_verified_at' KHÔNG nằm trong User::$fillable
        // (xem chú thích ở model) -> phải ghi tường minh bằng forceFill().
        $super = User::firstOrCreate(
            ['email' => $this->seedEmail('SEED_SUPER_ADMIN_EMAIL', 'owner@fabrikai.shop')],
            [
                'name' => 'FabrikAI Owner',
                'password' => Hash::make($this->seedPassword('SEED_SUPER_ADMIN_PASSWORD', 'password')),
                'phone' => '0900000001',
            ]
        );
        $super->forceFill([
            'role' => User::ROLE_SUPER_ADMIN,
            'credits_balance' => $super->credits_balance ?: 0,
            'email_verified_at' => $super->email_verified_at ?: now(),
        ])->save();

        // Admin thường — vào được khu vực quản trị nhưng KHÔNG quản lý tài khoản.
        $admin = User::firstOrCreate(
            ['email' => $this->seedEmail('SEED_ADMIN_EMAIL', 'admin@fabrikai.shop')],
            [
                'name' => 'Quản trị FabrikAI',
                'password' => Hash::make($this->seedPassword('SEED_ADMIN_PASSWORD', 'password')),
                'phone' => '0900000000',
            ]
        );
        $admin->forceFill([
            'role' => User::ROLE_ADMIN,
            'credits_balance' => $admin->credits_balance ?: 1000,
            'email_verified_at' => $admin->email_verified_at ?: now(),
        ])->save();

        // ── Người dùng thường (demo) — không tạo ở production trừ khi bật SEED_DEMO_DATA ──
        if (! $this->demoDataEnabled()) {
            return;
        }

        User::firstOrCreate(
            ['email' => 'user@fabrikai.shop'],
            [
                'name' => 'Người dùng demo',
                'password' => Hash::make('password'),
                'phone' => '0912345678',
            ]
        )->forceFill([
            'role' => User::ROLE_CUSTOMER,
            'credits_balance' => 200,
            'email_verified_at' => now(),
        ])->save();
    }

    /** Email tài khoản seed — cho phép đổi qua env khi deploy (repo này là PUBLIC). */
    protected function seedEmail(string $envKey, string $devDefault): string
    {
        $value = env($envKey);

        return filled($value) ? (string) $value : $devDefault;
    }


    protected function presets(): void
    {
        $rows = require database_path('data/studio_presets.php');

        $file = database_path('data/fashion_presets.json');
        if (file_exists($file)) {
            $extra = json_decode(file_get_contents($file), true) ?: [];
            foreach (['styles' => 'style', 'backgrounds' => 'background'] as $key => $cat) {
                foreach ($extra[$key] ?? [] as $item) {
                    $rows[] = [$cat, $item['label'], $item['prompt'], ''];
                }
            }
        }

        Preset::whereIn('category', ['camera', 'lens', 'video_scene', 'pose'])->delete();

        $sort = 0;
        $all = collect($rows)->map(fn ($row) => [$row[0], $row[1], $row[2], $row[3] ?? '']);
        foreach ($all as [$category, $label, $injection, $note]) {
            Preset::updateOrCreate(
                ['category' => $category, 'ui_label' => $label],
                ['prompt_injection' => $injection, 'note' => $note, 'sort_order' => $sort++],
            );
        }
    }
}
