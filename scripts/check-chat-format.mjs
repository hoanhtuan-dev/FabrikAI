#!/usr/bin/env node
/**
 * Self-check cho BỘ ĐỊNH DẠNG CHỮ CỦA TRỢ LÝ — resources/js/studio/chatFormat.js (2026-09-26).
 *
 * [VÌ SAO CÓ FILE NÀY]
 * Repo không có JS test runner (chỉ vite build), nhưng phần DỄ SAI NHẤT của việc "hiện câu trả lời cho
 * đọc được" là BỘ NHẬN DẠNG: đậm · nghiêng · mã · link · URL trần · gạch đầu dòng · tiêu đề. Sai một
 * chỗ ở đó thì người dùng đọc thấy ký tự của máy, hoặc TỆ HƠN: một chuỗi không phải địa chỉ web bị
 * biến thành chỗ bấm được. Vì vậy bộ đó được tách thành MODULE THUẦN (không DOM) và kiểm ở đây bằng
 * Node thuần — chạy cả trong CI lẫn tay:
 *     node scripts/check-chat-format.mjs        (exit != 0 nghĩa là HỎNG)
 * Cùng lối với scripts/check-session-store.mjs (được tests/Feature/SharedSessionTest.php gọi), và được
 * tests/Feature/StudioHeaderAndPromptTest.php gọi ở bài "chat format".
 */
import { formatAssistantText, assistantPlainText, formatRuns } from '../resources/js/studio/chatFormat.js';

const failed = [];
let passed = 0;
const check = (name, ok) => {
  console.log((ok ? 'ok   ' : 'FAIL ') + name);
  if (ok) passed += 1;
  else failed.push(name);
};

const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const kinds = (text) => formatAssistantText(text).map((b) => b.kind + (b.kind === 'li' ? (b.ordered ? ':ol' : ':ul') : ''));
const runTexts = (text) => formatRuns(text).map((r) => r.text);

// ── 1. ĐẬM · NGHIÊNG · MÃ: thẻ thật, và ký tự định dạng KHÔNG còn trong chữ hiển thị ──
const bold = formatRuns('Chất liệu **linen** co nhẹ');
check('**đậm** thành mảnh chữ đậm, dấu sao bị bỏ',
  eq(bold, [{ text: 'Chất liệu ' }, { text: 'linen', bold: true }, { text: ' co nhẹ' }]));

check('*nghiêng* thành mảnh chữ nghiêng',
  eq(formatRuns('vải *co giãn* nhẹ'), [{ text: 'vải ' }, { text: 'co giãn', italic: true }, { text: ' nhẹ' }]));

const code = formatRuns('gõ `npm run build` là xong');
check('mã trong backtick giữ NGUYÊN nội dung (không diễn giải tiếp bên trong)',
  eq(code, [{ text: 'gõ ' }, { text: 'npm run build', code: true }, { text: ' là xong' }]));

check('đậm LỒNG trong mã không bị tách (mã là chữ thô)',
  eq(formatRuns('`**a**`'), [{ text: '**a**', code: true }]));

check('dấu sao CHƯA ĐÓNG (chữ đang chảy) vẫn là chữ thường, không nuốt mất phần còn lại',
  eq(formatRuns('đang trả lời **dở'), [{ text: 'đang trả lời **dở' }]));

// ── 2. LINK: chỉ http/https, và CHỈ khi có đủ cặp ngoặc ──
const link = formatRuns('xem [nguồn này](https://bao.example/tweed) nhé');
check('link markdown thành mảnh chữ có địa chỉ',
  eq(link, [{ text: 'xem ' }, { text: 'nguồn này', href: 'https://bao.example/tweed' }, { text: ' nhé' }]));

check('địa chỉ KHÔNG phải http/https KHÔNG trở thành chỗ bấm được',
  formatRuns('[bấm đi](javascript:alert(1))').every((r) => ! r.href)
  && formatRuns('[bấm đi](javascript:alert(1))')[0].text === '[bấm đi](javascript:alert(1))');

check('dấu ngoặc lồng trong CHỮ của link không làm hỏng địa chỉ',
  eq(formatRuns('[giá (tham khảo)](https://bao.example/gia)'),
     [{ text: 'giá (tham khảo)', href: 'https://bao.example/gia' }]));

// ── 3. URL TRẦN: nhận được, và KHÔNG nuốt dấu câu của CÂU ──
const bare = formatRuns('nguồn ở https://bao.example/tweed.');
check('URL trần thành mảnh chữ có địa chỉ', bare.some((r) => r.href === 'https://bao.example/tweed'));
check('dấu chấm CUỐI CÂU không bị kéo vào địa chỉ',
  bare.some((r) => r.text === '.') && bare.filter((r) => r.href).every((r) => ! r.href.endsWith('.')));
check('ngoặc đóng của CÂU không bị kéo vào địa chỉ',
  formatRuns('(xem https://bao.example/tweed)').some((r) => r.href === 'https://bao.example/tweed'));

// ── 4. KHỐI: đoạn · gạch đầu dòng · danh sách số · tiêu đề ──
check('gạch đầu dòng "- " và "* " thành mục danh sách KHÔNG đánh số',
  eq(kinds('- mục một\n* mục hai'), ['li:ul', 'li:ul']));
check('"1. " thành mục danh sách CÓ đánh số và giữ đúng số người viết',
  formatAssistantText('3. bước ba').map((b) => b.kind + ':' + b.ordered + ':' + b.marker).join() === 'li:true:3');
check('# … thành TIÊU ĐỀ, không phải đoạn',
  eq(kinds('# Tweed mùa thu'), ['h']) && formatAssistantText('# Tweed mùa thu')[0].level === 1);
check('dòng trống chỉ NGĂN khối, không sinh khối rỗng',
  eq(kinds('đoạn một\n\nđoạn hai'), ['p', 'p']));
check('chữ rỗng / null không sinh khối nào',
  formatAssistantText('').length === 0 && formatAssistantText(null).length === 0 && formatAssistantText('   \n  ').length === 0);
check('"3.5 mét vải" KHÔNG bị nhận nhầm thành danh sách',
  eq(kinds('3.5 mét vải'), ['p']));

// ── 5. VĂN BẢN SẠCH ĐỂ COPY: không còn ký tự định dạng, link thành "chữ (địa chỉ)" ──
check('bản copy bỏ hết dấu định dạng',
  assistantPlainText('**Đậm** và *nghiêng* và `mã`') === 'Đậm và nghiêng và mã');
check('bản copy đổi link thành "chữ (địa chỉ)"',
  assistantPlainText('xem [nguồn](https://bao.example/a) nhé') === 'xem nguồn (https://bao.example/a) nhé');
check('bản copy giữ URL trần ĐÚNG MỘT LẦN (không lặp địa chỉ)',
  assistantPlainText('xem https://bao.example/a') === 'xem https://bao.example/a');
check('bản copy giữ cấu trúc danh sách (dấu đầu dòng · số thứ tự)',
  assistantPlainText('- một\n- hai\n1. ba') === '- một\n- hai\n1. ba');
check('bản copy bỏ dấu # của tiêu đề, giữ chữ',
  assistantPlainText('## Tweed mùa thu') === 'Tweed mùa thu');
check('bản copy giữ dòng trống ở GIỮA, bỏ ở hai đầu',
  assistantPlainText('\n\na\n\nb\n\n') === 'a\n\nb');
check('chữ rỗng / null cho bản copy rỗng',
  assistantPlainText(null) === '' && assistantPlainText('   ') === '');

// ── 6. KÝ TỰ LẠ · MÃ ĐỘC: ở lại là CHỮ, không thành thẻ, không thành chỗ bấm ──
const nasty = '<script>alert(1)</script> và <img src=x onerror=alert(2)>';
check('mã độc trong câu trả lời chỉ là CHỮ (không mảnh nào có địa chỉ)',
  formatRuns(nasty).every((r) => ! r.href) && formatRuns(nasty).map((r) => r.text).join('') === nasty);
check('bản copy của câu chứa mã độc giữ nguyên chữ',
  assistantPlainText(nasty) === nasty);
check('emoticon và ký tự lạ không làm mất chữ',
  assistantPlainText('giá 250.000đ — ổn 😊 (chốt)') === 'giá 250.000đ — ổn 😊 (chốt)');
check('hai dấu sao rời nhau không nuốt cả dòng',
  eq(runTexts('2 * 3 = 6 và 4 * 5 = 20'), ['2 * 3 = 6 và 4 * 5 = 20']));

// ── 7. NHẤT QUÁN GIỮA HAI HÀM: mọi mảnh chữ của bản ĐỊNH DẠNG đều xuất hiện trong bản COPY ──
const sample = [
  '# Tweed mùa thu',
  'Vải **tweed** dày, *hơi co*, may mặc `mùa lạnh`.',
  '- giữ phom dài',
  '- phối cùng [quần âu](https://bao.example/quan-au)',
  '1. chọn chất liệu',
  'Tra thêm ở https://bao.example/tweed.',
].join('\n');
const blocks = formatAssistantText(sample);
const plain = assistantPlainText(sample);
const words = blocks.flatMap((b) => b.runs.map((r) => r.text))
  .flatMap((t) => t.split(/\s+/))
  .filter((w) => w.length >= 4 && /^[\p{L}]+$/u.test(w));
check('không mất chữ nào khi đi từ bản định dạng sang bản copy',
  words.every((w) => plain.includes(w)) && words.length >= 8);
// Dấu backtick dựng bằng mã ký tự: viết thẳng nó vào chuỗi ở đây là tự làm hỏng cú pháp file này.
const TICK = String.fromCharCode(96);
check('bản copy không còn dấu sao định dạng hay dấu backtick',
  ! plain.includes('**') && ! plain.includes(TICK));
check('câu trả lời thật: 6 khối đúng thứ tự',
  eq(blocks.map((b) => b.kind), ['h', 'p', 'li', 'li', 'li', 'p']));

if (failed.length) {
  console.error('\nHỎNG: ' + failed.length + ' mục — ' + failed.join(' · '));
  process.exit(1);
}
console.log('\nTất cả mục OK (' + passed + ' mục).');
