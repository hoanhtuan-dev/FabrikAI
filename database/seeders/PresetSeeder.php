<?php

namespace Database\Seeders;

use App\Models\Preset;
use Illuminate\Database\Seeder;

class PresetSeeder extends Seeder
{
    public function run(): void
    {
        $rows = require database_path('data/studio_presets.php');

        // Bổ sung style/background/pose từ JSON.
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
