<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'fee', 'free_threshold', 'estimated_days', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'free_threshold' => 'decimal:2',
            'estimated_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
