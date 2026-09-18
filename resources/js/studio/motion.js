/**
 * motion.js — CẦU NỐI JS ↔ CSS của CƠ SỞ CHUYỂN ĐỘNG.
 *
 * Nguồn sự thật về thời lượng/đường cong nằm ở CSS (khối "CƠ SỞ CHUNG CHO CHUYỂN ĐỘNG"
 * trong resources/css/app.css, token --motion-dur-*). Ở đây KHÔNG chép lại con số nào
 * làm nguồn thứ hai: JS ĐỌC LẠI đúng biến CSS đang có hiệu lực.
 *
 * Vì sao cần đọc lại: có việc JS chỉ được làm SAU KHI hiệu ứng chạy xong (đo lại canvas,
 * vẽ lại overlay, cuộn tới mục…). Nếu JS tự viết "260ms" thì chỉ cần ai sửa token trong
 * CSS là hai bên lệch nhau: panel co xong từ lâu mà overlay vẫn đo sai bề rộng.
 *
 * Bảng số dưới đây là LƯỚI AN TOÀN (CSS không đọc được vì biến chưa định nghĩa / chạy
 * trong môi trường test không có DOM), không phải nguồn sự thật.
 */

/** Thời lượng dự phòng (ms) — chỉ dùng khi KHÔNG đọc được biến CSS. */
const FALLBACK_MS = { instant: 90, fast: 150, base: 220, slow: 320, dock: 260, reveal: 600 };

/**
 * Người dùng có bật "giảm chuyển động" của hệ điều hành không.
 * @returns {boolean}
 */
export function prefersReducedMotion() {
    try {
        return typeof window !== 'undefined'
            && typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
        return false; // trình duyệt cũ không có matchMedia → coi như chuyển động bình thường
    }
}

/**
 * Thời lượng hiệu ứng đang có hiệu lực, tính bằng ms — đọc từ biến CSS --motion-dur-<name>.
 * Trả 0 khi người dùng bật giảm chuyển động (lúc đó CSS đã đặt token về 0ms).
 *
 * @param {'instant'|'fast'|'base'|'slow'|'dock'|'reveal'} name
 * @returns {number} ms (0 = không có hiệu ứng, việc "sau hiệu ứng" phải chạy ngay)
 */
export function motionDurationMs(name = 'base') {
    const fallback = FALLBACK_MS[name] != null ? FALLBACK_MS[name] : FALLBACK_MS.base;
    if (prefersReducedMotion()) return 0;
    try {
        const raw = getComputedStyle(document.documentElement)
            .getPropertyValue('--motion-dur-' + name)
            .trim();
        const value = parseFloat(raw);
        if (!Number.isFinite(value)) return fallback;
        // Token khai bằng ms; chấp nhận cả trường hợp ai đó đổi sang s (0.26s).
        return raw.endsWith('ms') ? value : Math.round(value * 1000);
    } catch (e) {
        return fallback;
    }
}
