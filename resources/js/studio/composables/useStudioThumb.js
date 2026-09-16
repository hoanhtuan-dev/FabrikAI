// Composable dùng chung cho thumbnail ảnh Studio (single source of truth).
// Trùng khớp với PHP helper studio_image_thumb_url() ở app/Support/helpers.php:
// chỉ tạo thumbnail cho path AN TOÀN (chữ-số / _ . - / /); path đặc biệt → giữ ảnh gốc,
// tránh lỗi 500 do middleware ValidatePathEncoding khi URL chứa ký tự không hợp lệ.
//
// Ảnh gốc full-size vẫn dùng cho viewer (store.viewer = g → media_url gốc) và AI vision.
// Thumbnail 160px WebP/JPEG giảm ~50x dung lượng so với ảnh gốc 2K-4K.
//
// Dùng ở: OutputModule (right dock Outputs), GalleryModal (dải thumbnail viewer),
// LibraryApp (grid ảnh lớn), AdminProductsApp (Studio picker).

const SAFE = /^[a-zA-Z0-9/_.-]+$/;

/**
 * Tạo URL thumbnail cho ảnh Studio từ media_url gốc.
 * Nhận diện cả URL tương đối (/storage/..., /api/image/...) lẫn URL tuyệt đối cùng origin
 * (http(s)://<host>/storage/... — do asset() sinh ra ở AdminProductsApp picker).
 * - /storage/...      → /api/image-thumb/{path}
 * - /api/image/... → /api/image-thumb/{path}
 * - /api/image-thumb/... → giữ nguyên (tránh double-wrap)
 * - URL ngoài / data:URL / path đặc biệt / khác origin → trả nguyên url gốc (fallback an toàn).
 * - url rỗng/null → trả nguyên (component tự xử lý v-if để tránh img rỗng).
 *
 * @param {string} url   media_url gốc.
 * @param {number} size  Cỡ thumbnail (160|320|480|640). Bỏ trống/160 → không thêm query (mặc định 160px).
 *                       Dùng 480 cho grid lớn (Thư viện /api/library) để không bị nhòe.
 */
export function thumbUrl(url, size = 0) {
  if (!url) return url;
  let clean = String(url).split(/[?#]/)[0]; // bỏ query string / fragment
  // URL tuyệt đối cùng origin → tách path (asset() có thể sinh http(s)://<host>/storage/...).
  if (/^https?:\/\//i.test(clean)) {
    try {
      const u = new URL(clean);
      // Khác origin (CDN/domain ngoài) → giữ nguyên ảnh gốc.
      if (u.origin !== window.location.origin) return url;
      clean = u.pathname;
    } catch (e) {
      return url; // URL malformed → giữ nguyên
    }
  }
  const qs = size && size !== 160 ? '?size=' + size : '';
  // Đã là thumbnail URL → giữ nguyên (tránh double-wrap /api/image-thumb/api/image-thumb/...).
  if (clean.startsWith('/api/image-thumb/')) {
    return size ? clean + qs : url;
  }
  if (clean.startsWith('/storage/')) {
    const p = clean.slice(9);
    return SAFE.test(p) ? '/api/image-thumb/' + p + qs : url;
  }
  if (clean.startsWith('/api/image/')) {
    const p = clean.slice(14);
    return SAFE.test(p) ? '/api/image-thumb/' + p + qs : url;
  }
  return url; // URL ngoài hoặc data:URL → giữ nguyên
}

/**
 * Fallback @error handler: khi thumbnail 404/lỗi, quay về ảnh gốc rồi placeholder.
 * Dùng cho <img @error="onThumbError($event, g.media_url)">.
 * Thứ tự: thumb → ảnh gốc → placeholder SVG (tránh vỡ ảnh trống).
 */
export function onThumbError(e, originalUrl) {
  const el = e.target;
  if (!el) return;
  // Lần lỗi đầu: thử ảnh gốc full-size (thumbnail có thể chưa tạo/xóa).
  if (el.dataset.fallback !== '1') {
    el.dataset.fallback = '1';
    // Chỉ fallback nếu originalUrl KHÁC với src hiện tại (tránh loop vô hạn).
    if (originalUrl && el.src !== originalUrl && !el.src.includes(originalUrl)) {
      el.src = originalUrl;
      return;
    }
  }
  // Lần lỗi thứ 2 (ảnh gốc cũng lỗi): placeholder SVG.
  el.src = '/images/placeholder.svg';
}

export function useStudioThumb() {
  return { thumbUrl, onThumbError };
}
