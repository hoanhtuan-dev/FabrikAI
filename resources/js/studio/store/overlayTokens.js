/**
 * MÀU LỚP PHỦ VẼ TRÊN ẢNH — ĐỌC TỪ TOKEN, KHÔNG VIẾT LẠI MÃ MÀU (2026-09-25).
 *
 * Vì sao có tệp này: cùng một màu mặt nạ (đỏ 60%) từng được viết thẳng ở BA tệp —
 * store/actions/maskBrush.js · maskSelect.js · pathTool.js. Ba chỗ để lệch nhau lúc nào không biết,
 * mà lệch màu vẽ trên ảnh thì không ai phát hiện ra cho tới khi khách hỏi "sao vùng chọn chỗ đậm
 * chỗ nhạt". Nay màu đó là token CỐ ĐỊNH --color-mask-veil trong resources/css/app.css.
 *
 * Vì sao phải đọc qua JS: canvas 2D (fillStyle/strokeStyle) KHÔNG hiểu var(--…). Đây đúng cách mà
 * motion.js đang đọc --motion-dur-dock: token trong CSS là nguồn duy nhất, JS đọc hộ MỘT lần rồi
 * dùng lại (màu lớp phủ không đổi theo theme nên đọc một lần là đủ).
 */
let veil = null;

/** Màu phủ vùng mặt nạ lên ảnh (token --color-mask-veil). */
export function maskVeil() {
    if (veil === null) {
        const read = typeof window !== 'undefined' && document.documentElement
            ? getComputedStyle(document.documentElement).getPropertyValue('--color-mask-veil').trim()
            : '';
        veil = read || 'rgba(220, 38, 38, 0.6)';
    }
    return veil;
}
