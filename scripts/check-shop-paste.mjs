#!/usr/bin/env node
/**
 * [Đợt 31 — 2026-09-23] Self-check cho phần ĐỌC BẢNG DÁN TỪ EXCEL của Agent Studio.
 *
 * Vì sao có file này: đây là chỗ từng làm sai số TIỀN mà không ai thấy. Hàm cũ bỏ hết dấu rồi Number():
 * "520.000" → 520 và "1.200.000" → 0. Giá 0 kéo theo dải giá 0, kế hoạch sản xuất ra giá bán 0 và cả ba
 * kịch bản bán biến mất. Repo không có JS test runner nên kiểm bằng Node thuần (nạp thẳng module nguồn
 * qua data-URL, không cần bundler) — cùng cách với scripts/check-local-catalog.mjs.
 *
 * Chạy: node scripts/check-shop-paste.mjs   (exit != 0 nghĩa là HỎNG)
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '..', 'resources/js/studio/shopPaste.js'), 'utf8');
const mod = await import('data:text/javascript;base64,' + Buffer.from(src).toString('base64'));
const { parseNumberCell, parseShopRows } = mod;

let failed = 0;
const check = (name, actual, expected) => {
  const ok = JSON.stringify(actual) === JSON.stringify(expected);
  if (!ok) failed++;
  console.log((ok ? 'ok   ' : 'FAIL ') + name + (ok ? '' : ' → nhận ' + JSON.stringify(actual) + ', mong đợi ' + JSON.stringify(expected)));
};

// ── Số kiểu Việt Nam (định dạng Excel mặc định) ─────────────────────────────
check('520.000 = 520 nghìn', parseNumberCell('520.000'), 520000);
check('1.200.000 = 1,2 triệu', parseNumberCell('1.200.000'), 1200000);
check('1,200,000 kiểu Anh', parseNumberCell('1,200,000'), 1200000);
check('1 200 000 (có dấu cách)', parseNumberCell('1 200 000'), 1200000);
check('499 giữ nguyên', parseNumberCell('499'), 499);
check('1.234 (số bán) = 1234', parseNumberCell('1.234'), 1234);
check('1,5 = 2 (làm tròn)', parseNumberCell('1,5'), 2);
check('1.234,5 kiểu Việt', parseNumberCell('1.234,5'), 1235);
check('899.000đ (có chữ)', parseNumberCell('899.000đ'), 899000);
check('ô trống = 0', parseNumberCell(''), 0);
check('chữ vô nghĩa = 0', parseNumberCell('không rõ'), 0);
check('số âm bị kẹp về 0', parseNumberCell('-500'), 0);

// ── Cả bảng: tiêu đề bị bỏ, cột đọc đúng thứ tự ─────────────────────────────
const sheet = [
  'Tên\tNhóm\tĐã bán\tTồn\tĐổi trả\tGiá bán',
  'Đầm linen\tVáy\t120\t30\t5\t520.000',
  'Áo sơ mi trắng\tÁo\t1.200\t80\t12\t1.200.000',
].join('\n');
const rows = parseShopRows(sheet);
check('bỏ dòng tiêu đề', rows.length, 2);
check('dòng 1 giá 520.000', rows[0].price_vnd, 520000);
check('dòng 2 số bán 1.200', rows[1].units_sold, 1200);
check('dòng 2 giá 1.200.000', rows[1].price_vnd, 1200000);
check('giữ tên sản phẩm', rows[0].name, 'Đầm linen');
check('đánh dấu nguồn là dán', rows[0].source, 'paste');

console.log(failed === 0 ? '\nTất cả phép kiểm ĐẠT.' : '\n' + failed + ' phép kiểm HỎNG.');
process.exit(failed === 0 ? 0 : 1);
