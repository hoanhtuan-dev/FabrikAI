<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptsHistory extends Model
{
    use HasFactory;

    protected $table = 'prompts_history';

    protected $fillable = ['user_id', 'project_id', 'idea', 'image_prompt_en', 'video_prompt_en', 'json_response'];

    protected function casts(): array
    {
        return [
            'json_response' => 'array',
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
