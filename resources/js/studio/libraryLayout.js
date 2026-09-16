// Helper dùng chung cho bố cục Thư viện /studio (cả 3 tab: ảnh đã tạo / file tải lên / prompt).
// Tập trung ánh xạ cỡ lưới ảnh (s|m|l) → class Tailwind, để LibraryApp.vue và PromptLibraryTab.vue
// dùng chung một nguồn, tránh lệch cột giữa các tab.

/**
 * Ánh xạ cỡ lưới ảnh thành class grid Tailwind.
 * s (nhỏ) = nhiều cột → ô nhỏ; m (vừa) = mặc định; l (lớn) = ít cột → ô to.
 */
export function gridClass(size) {
  switch (size) {
    case 's':
      return 'grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8';
    case 'l':
      return 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4';
    case 'm':
    default:
      return 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5';
  }
}

/**
 * Nhãn tiếng Việt cho từng giá trị cỡ lưới (dùng cho title/tooltip).
 */
export function gridLabel(size) {
  switch (size) {
    case 's': return 'Lưới nhỏ';
    case 'l': return 'Lưới lớn';
    case 'm':
    default: return 'Lưới vừa';
  }
}
