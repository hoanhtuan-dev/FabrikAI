/**
 * HAPTIC — rung hồi đáp siêu nhẹ trên thiết bị cảm ứng (Phase 5 · shell 2026).
 * Một chạm có trọng lượng: orb, chọn menu, quyết định giữ/bỏ, gửi lệnh.
 * Không làm gì trên thiết bị không hỗ trợ; KHÔNG bao giờ ném lỗi.
 */
export function haptic(ms = 8) {
  try {
    if (typeof navigator !== 'undefined' && navigator.vibrate) navigator.vibrate(ms);
  } catch (e) { /* một số trình duyệt chặn — bỏ qua */ }
}
