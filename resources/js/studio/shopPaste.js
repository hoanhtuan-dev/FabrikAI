/**
 * ĐỌC BẢNG DÁN TỪ EXCEL/GOOGLE SHEETS — tách ra module riêng để KIỂM ĐƯỢC BẰNG MÁY (2026-09-23).
 *
 * Vì sao tách: đây là chỗ từng làm hỏng tầng tiền mà không ai thấy. Hàm cũ bỏ hết dấu rồi gọi Number():
 *   · "520.000"  (Excel định dạng vi-VN)  → 520     (sai 1000 lần)
 *   · "1.200.000"                          → NaN → 0 (mất giá)
 *   · "1.234"    (số bán)                  → NaN → 0 hoặc 1
 * Giá bán 0 ⇒ dải giá bằng 0 ở brief ⇒ kế hoạch sản xuất ra giá bán 0 và CẢ BA kịch bản bán biến mất.
 *
 * Quy tắc đọc số (giống bộ đọc giá ở máy chủ — MarketSignalService::pricesIn, để hai đầu không lệch nhau):
 *   · "1.200.000" · "1,200,000" · "1 200 000" ⇒ dấu NGĂN NGHÌN
 *   · "1,5" · "1.5"                            ⇒ dấu THẬP PHÂN (đuôi không phải 3 chữ số)
 *   · "1.234,5" · "1,234.5"                    ⇒ dấu đứng SAU CÙNG là dấu thập phân
 */

/** Đọc một ô số của bảng tính. Không đọc được ⇒ 0 (và tầng gọi phải CẢNH BÁO, không im lặng). */
export function parseNumberCell(value) {
  let text = String(value ?? '').trim().replace(/[^\d.,-]/g, '');
  if (!text) return 0;
  const negative = text.startsWith('-');
  text = text.replace(/-/g, '');
  const hasDot = text.includes('.');
  const hasComma = text.includes(',');
  if (hasDot && hasComma) {
    const decimal = text.lastIndexOf('.') > text.lastIndexOf(',') ? '.' : ',';
    text = text.split(decimal === '.' ? ',' : '.').join('');
    text = text.replace(decimal, '.');
  } else if (hasDot || hasComma) {
    const separator = hasDot ? '.' : ',';
    const parts = text.split(separator);
    const tail = parts[parts.length - 1];
    text = (parts.length > 1 && tail.length === 3) ? parts.join('') : parts.join('.');
  }
  const n = Number(text);
  if (!Number.isFinite(n)) return 0;
  const rounded = Math.max(0, Math.round(negative ? -n : n));

  return rounded;
}

/**
 * Dán từ Excel: chấp nhận Tab, dấu phẩy hoặc dấu chấm phẩy; bỏ dòng tiêu đề nếu có.
 * Thứ tự cột: Tên, Nhóm, Đã bán, Tồn, Đổi trả, Giá bán.
 */
export function parseShopRows(text) {
  const lines = String(text || '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
  const rows = [];
  for (const line of lines) {
    const cells = line.split(/\t|;|,(?=(?:[^"]*"[^"]*")*[^"]*$)/).map((c) => c.trim().replace(/^"|"$/g, ''));
    const name = String(cells[0] || '').trim();
    if (!name) continue;
    if (rows.length === 0 && /^(t[eê]n|name|s[aả]n ph[aẩ]m)\b/i.test(name)) continue;
    rows.push({
      name: name.slice(0, 160),
      category: String(cells[1] || '').trim().slice(0, 80),
      units_sold: parseNumberCell(cells[2]),
      stock_on_hand: parseNumberCell(cells[3]),
      returns: parseNumberCell(cells[4]),
      price_vnd: parseNumberCell(cells[5]),
      period_days: 30,
      source: 'paste',
    });
  }

  return rows;
}
