<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * LINK CHIA SẺ công khai của một bộ sưu tập (Đợt 4).
 *
 * Người nhận KHÔNG cần tài khoản FabrikAI: mở link là xem được ảnh + brief, và gửi phản hồi
 * (Duyệt / Yêu cầu sửa). Link có hạn và THU HỒI được.
 */
class ProjectShare extends Model
{
    protected $fillable = ['project_id', 'created_by', 'token', 'expires_at', 'revoked_at', 'views', 'last_viewed_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'views' => 'integer',
    ];

    /**
     * [P0.7 — 2026-09-20] Khoá định tuyến là TOKEN, không phải id.
     *
     * Vì sao: giao diện gọi `DELETE /api/projects/{id}/share/{token}` (store.js: revokeShare) —
     * đúng con token mà nó vừa nhận từ API. Nhưng model không khai getRouteKeyName() nên Laravel
     * bind theo `id`, chuỗi token 48 ký tự không phải id ⇒ **404 trước khi vào controller** ⇒ nút
     * "Thu hồi" chưa bao giờ chạy được: khách vẫn mở được link đã bị thu hồi.
     */
    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /** Token ngẫu nhiên 48 ký tự — đủ dài để không đoán được. */
    public static function newToken(): string
    {
        return Str::random(48);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function publicUrl(): string
    {
        return url('/chia-se/'.$this->token);
    }
}
