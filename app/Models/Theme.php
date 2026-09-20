<?php

namespace App\Models;

use App\Support\DaisyTheme;
use App\Support\DaisyThemeLink;
use App\Support\ThemeRamp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MỘT THEME trong thư viện (2026-09-25) — xem database/migrations/…create_themes_table.php.
 *
 * Model này CỐ Ý mỏng: nó chỉ giữ dữ liệu và hai phép biến đổi thuần (payload → DaisyTheme → bảng
 * token). Mọi quyết định (import, kích hoạt, sinh bản đối ứng) nằm ở App\Support\ThemeLibrary —
 * để đường HTTP, đường artisan và đường test đi qua ĐÚNG một đoạn mã.
 */
class Theme extends Model
{
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_DERIVED = 'derived';

    protected $fillable = ['slug', 'name', 'scheme', 'source', 'batch', 'checksum', 'source_link', 'payload', 'created_by'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Payload đã kiểm → đối tượng theme dùng được cho ThemeRamp. */
    public function toDaisyTheme(): DaisyTheme
    {
        return DaisyTheme::fromArray($this->payload ?? [], $this->source_link ?? '');
    }

    /** @return array<string,string> bảng token đầy đủ (màu + hình học) của theme này */
    public function tokens(): array
    {
        return ThemeRamp::full($this->toDaisyTheme());
    }

    /** Liên kết dán lại được vào daisyUI Theme Generator (kể cả với bản đối ứng tự sinh). */
    public function link(): string
    {
        return DaisyThemeLink::url($this->toDaisyTheme());
    }

    public function isDerived(): bool
    {
        return $this->source === self::SOURCE_DERIVED;
    }
}
