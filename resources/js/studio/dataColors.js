/**
 * MÀU MẶC ĐỊNH CHO DỮ LIỆU NGƯỜI DÙNG (2026-09-25).
 *
 * Vì sao có tệp này: bốn mã màu "mặc định khi dữ liệu chưa có màu" từng được viết thẳng ở MƯỜI hai
 * chỗ trong bảy tệp — dự án (#7aa2f7 × 4) · trạng thái ảnh (#6b6657 × 2) · ô mood (#b9c8c2 × 2) ·
 * loại trợ lý (#4a7a90 × 6). Đổi một chỗ là những chỗ còn lại lệch, mà đây là màu ĐẠI DIỆN cho dữ
 * liệu nên nhìn ảnh/thẻ là thấy ngay sự lệch.
 *
 * Đây KHÔNG phải màu giao diện: chúng là màu của DỮ LIỆU (dự án, nhãn trạng thái, ô mood, loại phụ
 * kiện) — người dùng đặt màu thật trong CSDL, các hằng này chỉ là giá trị dùng khi họ chưa đặt. Nên
 * chúng CỐ Ý không theo theme, giống bảng màu tóc hay nền studio trong các card.
 */
export const PROJECT_COLOR = '#7aa2f7';
export const STATUS_COLOR = '#6b6657';
export const MOOD_COLOR = '#b9c8c2';
export const STYLIST_TYPE_COLOR = '#4a7a90';
