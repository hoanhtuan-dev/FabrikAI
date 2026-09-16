<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuggestResult extends Model
{
    protected $fillable = [
        'user_id', 'project_id',
        'reference_url', 'reference_thumb',
        'styles', 'background', 'pose', 'fabric', 'silhouette', 'camera',
        'garment_type', 'embellishment', 'detail_notes', 'color_palette',
        'image_prompt_en', 'prompt_vi', 'video_prompt_en', 'negative_prompt', 'keywords',
        'creative_level', 'adherence', 'detail_level', 'category',
        'applied_at', 'apply_count',
    ];

    protected function casts(): array
    {
        return [
            'styles' => 'array',
            'color_palette' => 'array',
            'keywords' => 'array',
            'category' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
