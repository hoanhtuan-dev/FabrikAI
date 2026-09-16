<?php

use App\Models\Preset;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: seed the Studio presets so a fresh deploy (migrate --force)
 * has the full preset list without needing to run db:seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Preset::count() > 0) {
            return;
        }

        $presets = [
            // fabric
            ['fabric', 'Lụa bóng', 'silk satin fabric with a subtle sheen'],
            ['fabric', 'Voan mỏng', 'sheer chiffon fabric'],
            ['fabric', 'Cotton cao cấp', 'premium organic cotton'],
            ['fabric', 'Tweed', 'structured tweed fabric'],
            ['fabric', 'Da lộn', 'soft suede leather'],
            ['fabric', 'Organza', 'stiff organza fabric'],
            // silhouette
            ['silhouette', 'Phom chữ A', 'A-line silhouette'],
            ['silhouette', 'Đầm dạ hội', 'elegant evening gown silhouette'],
            ['silhouette', 'Ống suông', 'slim column silhouette'],
            ['silhouette', 'Váy xòe', 'flared skirt silhouette'],
            ['silhouette', 'Đầm ôm', 'body-hugging mermaid silhouette'],
            // style
            ['style', 'Tối giản', 'minimalist luxury style'],
            ['style', 'Cổ điển', 'classic timeless style'],
            ['style', 'Lãng mạn', 'romantic feminine style'],
            ['style', 'Phóng khoáng', 'bohemian free-spirited style'],
            ['style', 'Đương đại', 'modern contemporary style'],
            // camera
            ['camera', 'Xoay 360 độ', '360 degree rotating camera shot'],
            ['camera', 'Bước tới', 'camera tracking forward with the model'],
            ['camera', 'Cận cảnh', 'extreme close-up detail shot'],
            ['camera', 'Chạy đà', 'dynamic runway walk shot'],
            ['camera', 'Từ dưới lên', 'low-angle dramatic shot'],
        ];

        $file = database_path('data/fashion_presets.json');
        if (file_exists($file)) {
            $extra = json_decode((string) file_get_contents($file), true) ?: [];
            foreach (['styles' => 'style', 'backgrounds' => 'background', 'poses' => 'pose'] as $group => $cat) {
                foreach ($extra[$group] ?? [] as $item) {
                    $presets[] = [$cat, $item['label'], $item['prompt']];
                }
            }
        }

        $sort = 0;
        foreach ($presets as [$category, $label, $prompt]) {
            Preset::updateOrCreate(
                ['category' => $category, 'ui_label' => $label],
                ['prompt_injection' => $prompt, 'sort_order' => $sort++],
            );
        }
    }

    public function down(): void
    {
        // Keep presets; no-op to avoid deleting user data.
    }
};
