<?php

namespace Database\Seeders;

use App\Models\PosePreset;
use Illuminate\Database\Seeder;

class PosePresetSeeder extends Seeder
{
    public function run(): void
    {
        // Pose chỉ mô tả TƯ THẾ (sạch, dễ quản lý); phom người do prompt riêng đảm nhận.
        $poses = [
            ['Đứng thẳng', 'standing straight and elegant, arms relaxed at sides, weight even, one foot slightly forward, full body head to toe', 'pose-01'],
            ['Tay chống hông', 'standing with one hand on hip, weight on one leg, chin slightly up, full body head to toe', 'pose-02'],
            ['Sải bước catwalk', 'walking mid-stride catwalk, one hand on hip, dynamic confident stride, full body head to toe', 'pose-03'],
            ['Nghiêng người', 'standing at a slight angle, one shoulder forward, one hand lightly brushing hair, full body head to toe', 'pose-04'],
            ['Ngồi ghế', 'sitting gracefully on a high stool, back straight, one leg extended, full body head to toe', 'pose-05'],
            ['Tay đút túi', 'standing in relaxed side profile, one hand in pocket, full body head to toe', 'pose-06'],
            ['Xoay lưng', 'back view turned away, glancing over one shoulder, full body head to toe', 'pose-07'],
            ['Tựa tường', 'leaning lightly against a wall, one leg bent, arms relaxed, full body head to toe', 'pose-08'],
        ];

        $names = array_column($poses, 0);
        foreach ($poses as $i => [$name, $desc, $file]) {
            PosePreset::updateOrCreate(
                ['name' => $name],
                ['description' => $desc, 'image' => '/storage/studio/dang-nguoi-mau/'.$file.'.png', 'sort' => ($i + 1) * 10, 'enabled' => true]
            );
        }

        // Tắt preset cũ không còn trong 8 tư thế.
        PosePreset::whereNotIn('name', $names)->update(['enabled' => false]);
    }
}
