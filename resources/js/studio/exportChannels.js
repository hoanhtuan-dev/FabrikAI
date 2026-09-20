/**
 * KÊNH BÁN cho TÊN FILE trong gói xuất xưởng (2026-09-23).
 *
 * Vì sao để tên file theo kênh: ảnh gửi lên sàn TMĐT hoặc đưa vào catalogue cần tên file ĐỌC ĐƯỢC và
 * có thứ tự (sàn sắp xếp theo tên), thay vì tên ngẫu nhiên của máy ảnh. Chủ xưởng/chủ shop chọn kênh
 * một lần rồi tải gói là dùng được ngay.
 *
 * LƯU Ý: Danh sách này PHẢI khớp whitelist ở PHP: `export_channels()` trong app/Support/helpers.php.
 * Server vẫn kiểm lại (sai ⇒ 422), nên lệch nhau sẽ báo lỗi to chứ không âm thầm bỏ qua.
 */
export const EXPORT_CHANNELS = [
  { id: '', label: 'Mặc định (không tiền tố)' },
  { id: 'shopee', label: 'Shopee' },
  { id: 'lazada', label: 'Lazada' },
  { id: 'tiktok', label: 'TikTok Shop' },
  { id: 'catalogue', label: 'Catalogue / lookbook' },
  { id: 'xuong', label: 'Gửi xưởng (mẫu kỹ thuật)' },
];

/** Nhãn của một kênh — dùng cho câu xác nhận trước khi tải. */
export function channelLabel(id) {
  const hit = EXPORT_CHANNELS.find((c) => c.id === (id || ''));
  return hit ? hit.label : 'Mặc định (không tiền tố)';
}
