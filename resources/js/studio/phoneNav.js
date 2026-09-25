/**
 * YÊU CẦU ĐIỀU HƯỚNG DÀNH RIÊNG CHO NHÁNH ĐIỆN THOẠI (đợt 63 · 2026-09-26).
 *
 * StudioApp truyền yêu cầu "mở cái gì đó" xuống StudioPhone qua prop `phoneToolRequest`, và mặc định
 * `id` là một id CÔNG CỤ trong thanh công cụ (`activityNav`). Nhưng có những đích KHÔNG phải công cụ:
 * lưới kết quả là một MẶT (màn chiếm trọn) chứ không nằm trong thanh công cụ.
 *
 * VÌ SAO KHAI HẰNG SỐ Ở ĐÂY: nếu hai bên tự gõ chuỗi ('__results') thì một lần gõ sai là điều hướng im
 * lặng rơi vào nhánh "không tìm thấy công cụ" — tức là mở bảng nâng cấp gói. Đó là kiểu lỗi không ai
 * đoán ra khi đọc mã. Một hằng số dùng chung thì không lệch được.
 */
export const PHONE_NAV = {
  /** Mở LƯỚI KẾT QUẢ (màn chiếm trọn) — không phải một công cụ. */
  RESULTS: '__results',
};
