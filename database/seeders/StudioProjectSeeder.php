<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seed vài dự án mẫu cho Designer (Super Admin / Admin) để bảng "Dự án thiết kế"
 * trong Studio có dữ liệu minh hoạ luồng công việc (draft → in_progress → review → approved).
 */
class StudioProjectSeeder extends Seeder
{
    public function run(): void
    {
        $super = User::where('email', 'tuan.ho.designer@gmail.com')->first();
        $admin = User::where('email', 'admin@trillfa.com')->first();
        if (! $super && ! $admin) {
            return;
        }

        $samples = [
            [
                'user' => $super,
                'name' => 'BST Xuân Hè 2026 — Đầm Linen',
                'base_concept' => 'Phong cách tối giản, tone kem & xanh rêu, chất linen thoáng mát.',
                'status' => Project::STATUS_REVIEW,
                'brief' => 'Chụp 3 mẫu đầm linen với người mẫu studio, ưu tiên ánh sáng softbox và nền trắng.',
                'deadline' => now()->addDays(7),
                'tags' => ['linen', 'xuan-he', 'studio'],
                'color' => '#559b78',
                'sort' => 0,
            ],
            [
                'user' => $super,
                'name' => 'Áo Blazer Nữ Công Sở',
                'base_concept' => 'Blazer dáng rộng, màu be, chất liệu tweed nhẹ.',
                'status' => Project::STATUS_IN_PROGRESS,
                'brief' => 'Tạo 4 góc chụp (trước/sau/nghiêng/cận chi tiết) trên nền xám studio.',
                'deadline' => now()->addDays(3),
                'tags' => ['blazer', 'cong-so'],
                'color' => '#b56a37',
                'sort' => 1,
            ],
            [
                'user' => $admin ?? $super,
                'name' => 'Túi Tote Da — Lookbook',
                'base_concept' => 'Lookbook túi da với bối cảnh urban tối giản.',
                'status' => Project::STATUS_DRAFT,
                'brief' => 'Lên ý tưởng lookbook 6 ảnh cho dòng túi tote da mới.',
                'tags' => ['tui', 'lookbook'],
                'color' => '#36332a',
                'sort' => 2,
            ],
            [
                'user' => $super,
                'name' => 'BST Đã Duyệt — Giày Sneaker',
                'base_concept' => 'Sneaker trắng phối outfit thể thao thanh lịch.',
                'status' => Project::STATUS_APPROVED,
                'brief' => 'Đã chốt bộ ảnh sneaker, sẵn sàng đẩy lên sản phẩm.',
                'deadline' => now()->subDays(2),
                'tags' => ['sneaker', 'approved'],
                'color' => '#2d6f4d',
                'sort' => 3,
                'started_at' => now()->subDays(10),
                'completed_at' => now()->subDays(2),
            ],
        ];

        foreach ($samples as $i => $s) {
            $user = $s['user'] ?? $super;
            if (! $user) {
                continue;
            }
            // user_id + status đã rút khỏi $fillable của Project (chống
            // mass-assignment) → seeder chạy trong unguarded() để set được cả hai.
            Project::unguarded(fn () => Project::updateOrCreate(
                ['user_id' => $user->id, 'name' => $s['name']],
                [
                    'base_concept' => $s['base_concept'],
                    'status' => $s['status'],
                    'brief' => $s['brief'] ?? null,
                    'deadline' => $s['deadline'] ?? null,
                    'tags' => $s['tags'] ?? [],
                    'color' => $s['color'] ?? null,
                    'sort' => $s['sort'] ?? $i,
                    'archived' => false,
                    'started_at' => $s['started_at'] ?? null,
                    'completed_at' => $s['completed_at'] ?? null,
                ]
            ));
        }
    }
}
