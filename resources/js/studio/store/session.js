/**
 * PHIÊN LÀM VIỆC DÙNG CHUNG — một store cho CẢ BA khu của trang hợp nhất.
 *
 * [2026-09-26 · đợt 25] Trước đây mỗi khu tự lo danh tính của mình:
 *   · AdminApp gọi /api/boot rồi giữ `me` trong một ref riêng;
 *   · MySettingsApp đọc thuộc tính data-user-id/data-user-admin từ DOM;
 *   · thanh chung của trang hợp nhất không biết người dùng là ai.
 * Hệ quả thật: sửa TÊN của chính mình ở khu Quản trị xong, sang khu khác vẫn thấy tên cũ cho tới khi
 * nạp lại cả trang (blade đã render danh tính ngay từ đầu).
 *
 * Nay: MỘT store. Máy chủ nhúng sẵn danh tính (data-me, xem studio/hub.blade.php +
 * App\Support\SessionIdentity) nên không tốn thêm request; khu nào sửa người dùng thì gọi applyUser()
 * và mọi khu — kể cả thanh chung — thấy tên mới NGAY, không cần tải lại trang.
 *
 * Vì sao vẫn giữ load(): store phải dùng được cả khi trang không nhúng sẵn danh tính (ví dụ app được
 * mount lẻ trong tương lai). load() chia sẻ ĐÚNG MỘT request cho mọi nơi cùng hỏi.
 */
import { defineStore } from 'pinia';

export const useSessionStore = defineStore('session', {
  state: () => ({
    me: null,
    /** 'server' = do blade nhúng sẵn · 'api' = nạp qua /api/boot */
    source: null,
    /** Promise đang bay — để nhiều nơi cùng hỏi chỉ tốn một request. */
    pending: null,
  }),
  getters: {
    userId: (s) => (s.me ? s.me.id : null),
    name: (s) => (s.me ? s.me.name : ''),
    email: (s) => (s.me ? s.me.email : ''),
    roleLabel: (s) => (s.me ? s.me.role_label : ''),
    credits: (s) => (s.me ? s.me.credits_balance : null),
    isOwner: (s) => !!(s.me && s.me.is_admin),
    isSuper: (s) => !!(s.me && s.me.is_super_admin),
    /** Chữ cái đầu để hiện avatar chữ — không phụ thuộc ảnh. */
    initial: (s) => (s.me && s.me.name ? String(s.me.name).trim().charAt(0).toUpperCase() : '?'),
  },
  actions: {
    /** Nhận danh tính MÁY CHỦ đã render sẵn (không request nào). */
    hydrate(user) {
      if (user && user.id) {
        this.me = user;
        this.source = 'server';
      }
    },

    /** Nạp danh tính từ /api/boot — chỉ MỘT request dù nhiều nơi cùng gọi. */
    async load(force = false) {
      if (! force && this.me) return this.me;
      if (! force && this.pending) return this.pending;
      this.pending = (async () => {
        try {
          const r = await fetch('/api/boot', { headers: { Accept: 'application/json' } });
          if (r.ok) {
            const data = await r.json();
            if (data && data.user) {
              this.me = data.user;
              this.source = 'api';
            }
          }
        } catch (e) {
          // Không lấy được danh tính: giữ nguyên cái đang có (khu vẫn chạy phần không cần quyền).
        }
        this.pending = null;
        return this.me;
      })();
      return this.pending;
    },

    /**
     * Sau khi một khu sửa người dùng: nếu là CHÍNH MÌNH thì cập nhật ngay.
     * Đây là chỗ biến "sửa tên ở Quản trị" thành "mọi khu thấy tên mới".
     */
    applyUser(user) {
      if (! user || ! user.id || ! this.me || this.me.id !== user.id) return false;
      this.me = { ...this.me, ...user };
      return true;
    },
  },
});
