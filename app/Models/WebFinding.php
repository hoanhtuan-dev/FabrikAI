<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MỘT NGUỒN đã được công cụ tìm kiếm mang về cho MỘT tài khoản — xem WebFindingService.
 *
 * Đây là bản ghi SỰ THẬT (URL + tiêu đề + thời điểm + đoạn trích lúc đọc được), không phải bản sao nội dung
 * trang: chỉ giữ đúng thứ đã đưa cho model đọc, để giao diện dẫn nguồn lại được và để lần sau khỏi đi mạng.
 */
class WebFinding extends Model
{
    protected $table = 'web_findings';

    protected $fillable = [
        'user_id', 'query', 'query_key', 'region', 'url', 'url_hash', 'title', 'source_name',
        'snippet', 'published_at', 'hits', 'first_seen_at', 'last_seen_at', 'saved_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'saved_at' => 'datetime',
        'hits' => 'integer',
    ];

    /** Người dùng đã bấm "Lưu nguồn" chưa (khác với máy tự tìm thấy). */
    public function isSaved(): bool
    {
        return $this->saved_at !== null;
    }

    /**
     * Dạng ITEM mà công cụ tìm kiếm và khối DỮ LIỆU đang dùng — một hình dạng cho mọi đường.
     *
     * \`reused\` là cờ SỰ THẬT: nguồn này đến từ SỔ chứ không phải vừa đi mạng — giao diện phải nói đúng
     * "dùng lại nguồn đã tra" thay vì ám chỉ vừa tra mới.
     *
     * @return array<string, mixed>
     */
    public function toItem(): array
    {
        return [
            'title' => (string) $this->title,
            'url' => (string) $this->url,
            'summary' => (string) $this->snippet,
            'source' => '',
            'source_name' => (string) $this->source_name,
            'published_at' => optional($this->published_at)->toISOString(),
            'finding_id' => $this->id,
            'found_query' => (string) $this->query,
            'found_by' => 'ai_search',
            'reused' => true,
            'saved' => $this->isSaved(),
        ];
    }
}
