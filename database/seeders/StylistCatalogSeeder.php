<?php

namespace Database\Seeders;

use App\Models\StylistGarmentType;
use App\Models\StylistQuestion;
use App\Services\StylistCatalog;
use Illuminate\Database\Seeder;

class StylistCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = new StylistCatalog();

        foreach ($catalog->defaultGarmentTypes() as $i => $t) {
            StylistGarmentType::updateOrCreate(
                ['slug' => $t['id']],
                ['name' => $t['name'], 'emoji' => $t['emoji'], 'color' => $t['color'], 'sort_order' => $i],
            );
        }

        foreach ($catalog->defaultQuestions() as $i => $q) {
            StylistQuestion::updateOrCreate(
                ['key' => $q['key']],
                ['question' => $q['q'], 'options' => $q['opts'], 'sort_order' => $i],
            );
        }
    }
}
