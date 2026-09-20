{{--
  THEME TOÀN CỤC — Sáng / Tối (2026-09-23 · docs/DESIGN_SYSTEM.md §1.1)

  VÌ SAO LÀ SCRIPT INLINE TRONG <head>, KHÔNG NẰM TRONG BUNDLE:
    · Nó phải chạy TRƯỚC khung hình đầu tiên. Bundle của Vite tải bất đồng bộ ⇒ nếu để trong
      đó, người chọn theme Sáng sẽ thấy một nháy nền tối rồi mới đổi màu (FOUC).
    · Trang do server render (bảng giá · trang chia sẻ · đăng nhập · trang lỗi) không chạy app
      JS nào, nhưng vẫn phải đổi được theme.

  THỨ TỰ QUYẾT ĐỊNH (giống mọi hệ thống theme):
    1. localStorage của MÁY NÀY  — biết ngay, không chờ mạng;
    2. tùy chọn đã lưu theo TÀI KHOẢN (server render sẵn vào data-theme của thẻ html);
    3. mặc định của sản phẩm: 'dark'.
  Lựa chọn 'system' được giải bằng prefers-color-scheme, và tự đổi khi hệ điều hành đổi.

  API cho phần còn lại của app:
    window.FabrikAITheme.pref        -> 'light' | 'dark' | 'system'
    window.FabrikAITheme.resolved    -> 'light' | 'dark'  (đang áp thật)
    window.FabrikAITheme.set(pref)   -> Promise<{saved:boolean,error?:string}>
    window.FabrikAITheme.onChange(fn) -> hàm huỷ đăng ký
  Cài đặt xong thì áp NGAY trên máy này; việc gửi lên tài khoản là bước sau, và nếu gửi hỏng
  thì giao diện BÁO RÕ là chưa lưu được (không im lặng giả vờ thành công).
--}}
@php
    $themePref = theme_pref();
    $themeResolved = theme_resolved();
@endphp
<script>
(function () {
  'use strict';
  var KEY = 'fabrikai.theme';
  var VALID = ['light', 'dark', 'system'];
  var SERVER_PREF = @json($themePref);
  var CAN_SAVE = @json(auth()->check());
  var root = document.documentElement;
  var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;
  var listeners = [];

  function readLocal() {
    try {
      var v = window.localStorage.getItem(KEY);
      return VALID.indexOf(v) >= 0 ? v : null;
    } catch (e) { return null; }
  }

  function resolve(pref) {
    if (pref === 'system') return (media && media.matches) ? 'light' : 'dark';
    return pref === 'light' ? 'light' : 'dark';
  }

  function paint(theme) {
    root.setAttribute('data-theme', theme);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'light' ? '#f1efe7' : '#0e0d09');
  }

  var pref = readLocal() || SERVER_PREF || 'dark';
  if (VALID.indexOf(pref) < 0) pref = 'dark';
  var resolved = resolve(pref);
  paint(resolved);

  function announce() {
    for (var i = 0; i < listeners.length; i++) {
      try { listeners[i]({ pref: pref, resolved: resolved }); } catch (e) { /* UI hỏng không được làm hỏng theme */ }
    }
  }

  function saveRemote() {
    if (!CAN_SAVE) return Promise.resolve({ saved: false });
    return fetch('/api/theme', {
      method: 'PUT',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': (document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) || [])[1]
          ? decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)[1]) : '',
      },
      body: JSON.stringify({ theme: pref }),
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return { saved: true };
    }).catch(function (e) {
      // Chi tiết kỹ thuật đi ra console (theo §6 của tài liệu chuẩn), người dùng nhận câu
      // nói được việc gì tiếp theo — ở đây là "chưa lưu lên tài khoản".
      console.warn('[studio:theme] không lưu được tùy chọn lên tài khoản:', e && e.message);
      return { saved: false, error: 'Chưa lưu được lựa chọn lên tài khoản — lần sau mở trên máy khác có thể vẫn là giao diện cũ.' };
    });
  }

  window.FabrikAITheme = {
    get pref() { return pref; },
    get resolved() { return resolved; },
    set: function (next) {
      if (VALID.indexOf(next) < 0) return Promise.resolve({ saved: false, error: 'Giá trị giao diện không hợp lệ.' });
      pref = next;
      try { window.localStorage.setItem(KEY, pref); } catch (e) { /* chế độ riêng tư: vẫn đổi được trong phiên */ }
      resolved = resolve(pref);
      paint(resolved);
      announce();
      return saveRemote();
    },
    onChange: function (fn) {
      listeners.push(fn);
      return function () { var i = listeners.indexOf(fn); if (i >= 0) listeners.splice(i, 1); };
    },
  };

  // Hệ điều hành đổi Sáng/Tối ⇒ chỉ đổi khi người dùng đang chọn 'system'.
  if (media) {
    var onMedia = function () {
      if (pref !== 'system') return;
      resolved = resolve(pref);
      paint(resolved);
      announce();
    };
    if (media.addEventListener) media.addEventListener('change', onMedia);
    else if (media.addListener) media.addListener(onMedia);
  }

  // Tab khác đổi theme ⇒ tab này theo (nếu không, hai tab cùng tài khoản lệch giao diện).
  window.addEventListener('storage', function (e) {
    if (!e || e.key !== KEY) return;
    var v = readLocal();
    if (!v || v === pref) return;
    pref = v;
    resolved = resolve(pref);
    paint(resolved);
    announce();
  });
})();
</script>
