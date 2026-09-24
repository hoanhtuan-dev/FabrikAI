/**
 * KHÔNG GIAN (SPACES) — bản đồ điều hướng của shell 2026.
 *
 * Một nguồn chân lý cho CommandBar trên mọi entry (home · studio · agent · collections · hub):
 * orb ở góc trái Thanh lệnh mở menu này. Thêm/bớt không gian = sửa MỘT chỗ.
 *
 * mobileHidden: Studio là xưởng canvas cử chỉ — trên điện thoại canvas KHÔNG xuất hiện
 * (quyết định 2026-09-24: "canvas ẩn tức là không xuất hiện trên điện thoại"), nên nút Studio
 * cũng ẩn khỏi menu trên điện thoại; desktop vẫn thấy đủ 5.
 */
export const SPACES = [
  { id: 'home',        icon: 'sparkles',   label: 'Tạo',        url: '/',            desc: 'Prompt & ý tưởng mới' },
  { id: 'studio',      icon: 'layers',     label: 'Studio',     url: '/studio',      desc: 'Canvas · lớp · chỉnh AI', mobileHidden: true },
  { id: 'agent',       icon: 'bot',        label: 'Agent',      url: '/agent-studio', desc: 'Trợ lý nghiên cứu BST' },
  { id: 'collections', icon: 'folderOpen', label: 'Bộ sưu tập', url: '/bo-suu-tap',  desc: 'Thư viện look & dự án' },
  { id: 'hub',         icon: 'gear',       label: 'Hub',        url: '/cai-dat',     desc: 'Gói · cài đặt · đội nhóm' },
];

/** Danh sách không gian theo thiết bị: điện thoại ẩn các mục mobileHidden. */
export function spacesForViewport() {
  const isPhone = typeof matchMedia !== 'undefined' && matchMedia('(max-width: 520px)').matches;
  return SPACES.filter((s) => !(isPhone && s.mobileHidden));
}
