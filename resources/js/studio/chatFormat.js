/**
 * ĐỊNH DẠNG VĂN BẢN CỦA TRỢ LÝ — module THUẦN, test được bằng Node (2026-09-26).
 *
 * [VÌ SAO CÓ FILE NÀY — ĐỌC TRƯỚC KHI SỬA]
 * Hai khung chat (components/ChatModal.vue ở /studio và components/agents/AgentChatStep.vue của Agent
 * Studio) TRƯỚC ĐÂY in NGUYÊN VĂN `m.text`, nên người dùng đọc thấy ký tự định dạng của MÁY:
 * `**đậm**` · `- gạch đầu dòng` · `[chữ](url)` · dấu backtick quanh mã. Trợ lý viết bằng thứ tiếng
 * của máy, và người dùng phải tự dịch trong đầu — đó là lỗi HIỂN THỊ, không phải lỗi của câu trả lời.
 *
 * [VÌ SAO KHÔNG DÙNG THƯ VIỆN NGOÀI (marked · markdown-it · remarkable)]
 *   · Bề mặt cần dựng nhỏ và ĐÃ CHỐT: đoạn · gạch đầu dòng · tiêu đề · đậm · nghiêng · mã · link.
 *     Một thư viện markdown đầy đủ kéo theo bảng · ảnh · HTML thô — đúng những thứ KHÔNG được phép
 *     xuất hiện trong khung chat.
 *   · Thư viện markdown trả về CHUỖI HTML, mà muốn hiện thì phải có một sink HTML thô — repo đang
 *     CẤM điều đó (tests/Feature/StudioXssSinksTest.php). Cách duy nhất an toàn là trả về DỮ LIỆU có
 *     cấu trúc rồi để Vue render bằng thẻ thật.
 *
 * [VÌ SAO TRẢ VỀ DỮ LIỆU CÓ CẤU TRÚC, KHÔNG TRẢ VỀ HTML]
 * Dữ liệu ở đây đi thẳng vào vòng lặp của components/ChatMessageText.vue: chữ in đậm là thẻ <strong>
 * THẬT, nghiêng là <em>, mã là <code>, link là <a> — không có bước "dựng chuỗi HTML rồi chèn vào
 * trang". Nhờ vậy văn bản do máy trả lời KHÔNG BAO GIỜ trở thành mã chạy được, kể cả khi câu trả lời
 * chứa thẻ script hay thuộc tính sự kiện: chúng chỉ là CHỮ, vì Vue escape mọi giá trị nội suy.
 *
 * [VÌ SAO LÀ MODULE RIÊNG, KHÔNG VIẾT TRONG COMPONENT]
 *   · HAI khung chat dùng CHUNG một cách hiển thị. Hai bản sao là hai chỗ để lệch nhau: sửa một bên
 *     thì bên kia vẫn hiện kiểu cũ, và người dùng thấy cùng một câu trả lời ở hai dạng.
 *   · Module này KHÔNG chạm DOM, KHÔNG đọc biến toàn cục, KHÔNG gọi mạng ⇒ chạy được bằng Node thuần
 *     (scripts/check-chat-format.mjs, ghép vào PHPUnit qua StudioHeaderAndPromptTest). Phần dễ sai
 *     nhất của việc này là BỘ NHẬN DẠNG, mà bộ đó chỉ kiểm được nếu nó tách khỏi giao diện.
 *
 * GHI CHÚ VỀ ĐỘ TRUNG THỰC: hàm ở đây KHÔNG "làm đẹp" câu trả lời — nó chỉ BỎ KÝ TỰ ĐỊNH DẠNG và
 * dựng đúng cấu trúc người viết đã đặt. Số thứ tự danh sách giữ NGUYÊN như người viết (tự đánh số lại
 * là sửa lời người khác), dấu định dạng CHƯA ĐÓNG vẫn còn nguyên trong bản copy — chép đúng những gì
 * đang thấy tốt hơn là im lặng bỏ đi một ký tự người dùng có thể đang chờ.
 */

/** Bỏ dấu xuống dòng kiểu Windows/Mac cũ để phần còn lại chỉ phải xử lý một loại xuống dòng. */
function toLines(text) {
  return String(text == null ? '' : text).replace(/\r\n?/g, '\n').split('\n');
}

/**
 * Bộ nhận dạng TRONG MỘT DÒNG. Regex gộp (không phải bốn vòng lặp) vì regex tự tìm ra mảnh KHỚP TRÁI
 * NHẤT, và khi hai mảnh cùng bắt đầu ở một vị trí thì THỨ TỰ NHÓM quyết định — đúng thứ tự ưu tiên ta
 * cần: mã (nội dung trong backtick là CHỮ THÔ, không được diễn giải tiếp) → link → đậm → nghiêng → URL.
 *
 * Nhóm bắt: 1 mã · 2 chữ của link · 3 địa chỉ của link · 4 chữ in đậm · 5 chữ in nghiêng · 6 URL trần.
 *
 * VÌ SAO ĐẬM/NGHIÊNG ĐÒI HỎI KÝ TỰ ĐẦU VÀ CUỐI KHÔNG PHẢI KHOẢNG TRẮNG (luật của markdown chuẩn):
 * thiếu luật đó thì câu SỐ HỌC bị ăn mất — "2 * 3 = 6 và 4 * 5 = 20" thành một mảnh in nghiêng
 * "3 = 6 và 4", và người dùng đọc một câu sai. Có luật này, hai dấu sao đứng rời nhau vẫn là CHỮ.
 */
const INLINE = /`([^`\n]+)`|\[([^\]\n]*)\]\((https?:\/\/[^\s)]+)\)|\*\*([^*\s](?:[^*\n]*[^*\s])?)\*\*|\*([^*\s](?:[^*\n]*[^*\s])?)\*|(https?:\/\/[^\s<>"'`]+)/g;

/**
 * Cắt dấu câu dính ở CUỐI một URL trần: "xem https://a.example/b." — dấu chấm là của CÂU, không phải
 * của địa chỉ. Ngoặc đóng chỉ bị cắt khi nó KHÔNG có ngoặc mở tương ứng trong chính URL (địa chỉ thật
 * có thể chứa ngoặc, ví dụ đường dẫn Wikipedia).
 */
function trimUrl(url) {
  let out = String(url || '');
  for (;;) {
    const last = out.slice(-1);
    if ('.,;:!?'.includes(last)) { out = out.slice(0, -1); continue; }
    if (last === ')' && (out.match(/\(/g) || []).length < (out.match(/\)/g) || []).length) { out = out.slice(0, -1); continue; }
    if (last === ']' && (out.match(/\[/g) || []).length < (out.match(/\]/g) || []).length) { out = out.slice(0, -1); continue; }
    break;
  }
  return out;
}

/** Gộp hai mảnh chữ thuần liền nhau — nếu không, mỗi dấu câu sẽ thành một mảnh riêng. */
function pushRun(runs, run) {
  const last = runs[runs.length - 1];
  const bare = (r) => ! r.bold && ! r.italic && ! r.code && ! r.href;
  if (last && bare(last) && bare(run)) {
    last.text += run.text;
    return;
  }
  if (run.text === '') return;
  runs.push(run);
}

/**
 * Một DÒNG → danh sách mảnh chữ: mảng `{text, bold?, italic?, code?, href?}`.
 * URL chỉ được nhận khi bắt đầu bằng http:// hoặc https:// — nghĩa là địa chỉ kiểu javascript: và
 * data: KHÔNG BAO GIỜ trở thành chỗ bấm được (đây là hàng rào bổ sung sau khi Vue escape, không phải
 * hàng rào duy nhất).
 */
export function formatRuns(line) {
  const runs = [];
  const src = String(line == null ? '' : line);
  let cursor = 0;
  let plain = '';

  const flushPlain = () => { if (plain) { pushRun(runs, { text: plain }); plain = ''; } };

  // Địa chỉ bị cắt bớt dấu câu thì phần bị cắt PHẢI trả lại dòng chữ — nuốt nó là làm mất một dấu
  // người dùng đang nhìn thấy (dấu chấm cuối câu là chữ của CÂU, không phải của địa chỉ).
  const pushUrl = (raw) => {
    const url = trimUrl(raw);
    if (! url) return raw;
    pushRun(runs, { text: url, href: url });
    return raw.slice(url.length);
  };

  INLINE.lastIndex = 0;
  let match;
  while ((match = INLINE.exec(src)) !== null) {
    if (match.index > cursor) plain += src.slice(cursor, match.index);
    cursor = match.index + match[0].length;

    if (match[1] != null) {                       // mã trong backtick
      flushPlain();
      pushRun(runs, { text: match[1], code: true });
    } else if (match[3] != null) {                // link có chữ: [chữ](https://…)
      flushPlain();
      pushRun(runs, { text: match[2] || match[3], href: trimUrl(match[3]) });
      plain = match[3].slice(trimUrl(match[3]).length);
    } else if (match[4] != null) {                // **đậm**
      flushPlain();
      pushRun(runs, { text: match[4], bold: true });
    } else if (match[5] != null) {                // *nghiêng*
      flushPlain();
      pushRun(runs, { text: match[5], italic: true });
    } else {                                      // URL trần
      flushPlain();
      plain = pushUrl(match[6]);
    }
  }

  if (cursor < src.length) plain += src.slice(cursor);
  flushPlain();

  return runs;
}

/**
 * Văn bản của trợ lý → mảng KHỐI để giao diện render:
 *   `{kind: 'p'|'li'|'h', ordered: boolean, runs: [...]}`
 * kèm hai trường phụ chỉ có khi cần: `level` (1…6, của tiêu đề) và `marker` (số thứ tự đã viết).
 *
 * MỘT DÒNG = MỘT KHỐI. Vì sao không gộp các dòng liền nhau thành một đoạn như markdown chuẩn: bản
 * trước hiển thị bằng whitespace-pre-wrap nên MỌI dấu xuống dòng người viết đặt đều nhìn thấy được;
 * gộp lại là âm thầm bỏ đi những chỗ ngắt dòng đó.
 */
export function formatAssistantText(text) {
  const blocks = [];

  for (const raw of toLines(text)) {
    const line = raw.trim();
    if (! line) continue;                         // dòng trống chỉ để NGĂN khối, không phải một khối

    const heading = /^(#{1,6})\s+(.*)$/.exec(line);
    if (heading) {
      blocks.push({ kind: 'h', ordered: false, level: heading[1].length, runs: formatRuns(heading[2]) });
      continue;
    }

    // "1. " · "2) " — dấu cách sau số là BẮT BUỘC, nếu không thì "3.5 mét vải" thành gạch đầu dòng.
    const ordered = /^(\d{1,3})[.)]\s+(.*)$/.exec(line);
    if (ordered) {
      blocks.push({ kind: 'li', ordered: true, marker: ordered[1], runs: formatRuns(ordered[2]) });
      continue;
    }

    // "- " · "* " · "• " — cũng bắt buộc có dấu cách, nhờ vậy "*nghiêng*" không bị nhận nhầm.
    const bullet = /^[-*\u2022]\s+(.*)$/.exec(line);
    if (bullet) {
      blocks.push({ kind: 'li', ordered: false, marker: '', runs: formatRuns(bullet[1]) });
      continue;
    }

    blocks.push({ kind: 'p', ordered: false, runs: formatRuns(line) });
  }

  return blocks;
}

/** Một dòng mảnh chữ → CHỮ SẠCH: link thành "chữ (địa chỉ)", mọi ký tự định dạng bị bỏ. */
function runsToPlain(runs) {
  return runs.map((run) => {
    // URL trần giữ nguyên một lần; link có chữ thì kèm địa chỉ trong ngoặc để bản copy vẫn kiểm được.
    if (run.href && run.text !== run.href) return run.text + ' (' + run.href + ')';
    return run.text;
  }).join('');
}

/**
 * Văn bản của trợ lý → CHỮ SẠCH để copy (nút Copy của CẢ HAI khung chat gọi hàm này).
 *
 * Vì sao copy bản sạch chứ không copy nguyên văn: người dùng dán câu trả lời vào tài liệu · email ·
 * ô mô tả ảnh. Dán kèm dấu sao và backtick là mang ký tự của máy sang chỗ khác.
 * Vì sao GIỮ dấu gạch đầu dòng và số thứ tự: bản copy phải đọc ra được cấu trúc danh sách; bỏ dấu đầu
 * dòng thì ba việc khác nhau dính thành một câu.
 */
export function assistantPlainText(text) {
  const out = [];

  for (const raw of toLines(text)) {
    const line = raw.trim();
    if (! line) { out.push(''); continue; }

    const heading = /^(#{1,6})\s+(.*)$/.exec(line);
    if (heading) { out.push(runsToPlain(formatRuns(heading[2]))); continue; }

    const ordered = /^(\d{1,3})[.)]\s+(.*)$/.exec(line);
    if (ordered) { out.push(ordered[1] + '. ' + runsToPlain(formatRuns(ordered[2]))); continue; }

    const bullet = /^[-*\u2022]\s+(.*)$/.exec(line);
    if (bullet) { out.push('- ' + runsToPlain(formatRuns(bullet[1]))); continue; }

    out.push(runsToPlain(formatRuns(line)));
  }

  // Bỏ dòng trống ở hai ĐẦU (chúng chỉ là khoảng đệm của khung chat), giữ nguyên ở giữa.
  return out.join('\n').replace(/^\n+/, '').replace(/\n+$/, '');
}
