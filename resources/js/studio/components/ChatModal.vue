<script setup>
/**
 * MODAL TRỢ LÝ THIẾT KẾ — chat hỏi đáp BẤT KỲ LÚC NÀO trong /studio, VÀ LÀ CHỖ TẠO ẢNH (2026-09-26 · đợt 38).
 *
 * [VÌ SAO CÓ FILE NÀY — ĐỌC KHỐI NÀY TRƯỚC KHI SỬA]
 * Trước đây chat là một TAB nằm trong màn hình canvas trống (CanvasEmptyState.vue). Cách đó có hai hệ
 * quả THẬT, không phải chuyện thẩm mỹ:
 *   · hỏi được trợ lý CHỈ KHI canvas trống — vừa có ảnh trên canvas thì khung chat biến mất, mà đó
 *     lại đúng lúc cần hỏi nhất («chất liệu này có co không?», «màu này hợp bộ Thu Đông chứ?»);
 *   · hai việc khác hẳn nhau (tạo ảnh · hỏi đáp) tranh nhau MỘT chỗ, kèm một thanh tab và một trạng
 *     thái đang-mở-tab phải nhớ trong localStorage — bấm nhầm tab là mất chỗ đang gõ dở.
 * Nay chat tách hẳn thành MODAL, mở từ bất kỳ đâu qua StudioApp.vue → openChat(). Lối vào CHÍNH là
 * NÚT NỔI ở góc dưới–phải vùng canvas (components/ChatFab.vue, [2026-09-26 · lần 2]): nút icon trong
 * cụm công cụ header và mục «Trợ lý» trong menu mobile đã GỠ vì cả hai đều là lối vào phụ thuộc bề
 * rộng màn hình, còn nút nổi thì hiện ở mọi bề rộng. Bảng lệnh (Ctrl+K) vẫn giữ lệnh mở trợ lý —
 * đó là đường dành cho bàn phím.
 *
 * KHUNG: dùng BaseModal dùng chung — KHÔNG tự dựng lớp phủ. Lý do rất cụ thể: BaseModal đã có FOCUS
 * TRAP + Esc + lớp phủ + header 56px, và đó là những thứ một bản tự viết sẽ thiếu (bàn phím Tab đi
 * xuyên ra sau lớp phủ, Esc không đóng, lớp phủ nuốt cú bấm). Prop height giữ header cố định và cho
 * danh sách tin nhắn tự cuộn — đúng thứ một khung chat cần.
 *
 * HỘI THOẠI KHÔNG SỐNG Ở ĐÂY: nó nằm ở KHO DỮ LIỆU DÙNG CHUNG (store.agentChatMessages cùng các
 * action trong store/actions/agentChat.js) — ĐÚNG chỗ bước «Hỏi đáp» của Agent Studio đang dùng
 * (components/agents/AgentChatStep.vue). MỘT trợ lý, MỘT hội thoại: hai màn cùng đọc/ghi một mảng
 * tin nhắn thì không thể có hai lịch sử lệch nhau. Cách HIỂN THỊ cũng theo đúng khung đó (một quy
 * ước, hai nơi hiển thị — không phải hai bản sao): chữ chảy từng mảnh · chữ của trợ lý đi qua
 * components/ChatMessageText.vue (một cách trang trí, hai khung dùng) · số đo lấy từ MÁY CHỦ (giao
 * diện KHÔNG tự bấm giờ, KHÔNG tự đếm nguồn).
 *
 * [2026-09-26 · ĐỔI CHÍNH SÁCH — KHỐI "NGUỒN ĐỂ BẠN TỰ KIỂM" ĐÃ GỠ HẲN KHỎI DOM]
 * Yêu cầu của chủ dự án: khối nguồn làm rối khung chat ⇒ ẩn VĨNH VIỄN khỏi người dùng. Cách làm ở
 * đây là XOÁ HẲN khối khỏi template, KHÔNG phải ẩn bằng CSS và KHÔNG để lại một nút nào mở lại: một
 * khối "ẩn" vẫn còn trong DOM thì vẫn đọc được bằng trình đọc màn hình, vẫn tìm thấy bằng Ctrl+F và
 * vẫn quay lại nguyên trạng khi ai đó gỡ một class — đó không phải "ẩn vĩnh viễn".
 * DỮ LIỆU THÌ KHÔNG BỊ XOÁ: m.citations vẫn nằm nguyên trong kho dữ liệu dùng chung
 * (store/actions/agentChat.js) — chỉ không hiển thị nữa. Nếu sau này cần trả lại, nó phải là một hành
 * động NGƯỜI DÙNG CHỦ ĐỘNG (bấm mới hiện), KHÔNG được tự hiện lại như trước.
 * Cùng lúc đó khung này nhận ba việc của cùng đợt: nút COPY cho từng tin nhắn · chữ của trợ lý hiện
 * dưới dạng VĂN BẢN ĐÃ TRANG TRÍ (không còn nhìn thấy ký tự định dạng) · nút XUỐNG DÒNG trong ô nhập.
 *
 * [2026-09-26 · ĐỔI CHÍNH SÁCH LẦN 4 — CHAT NHẬN THÊM BA VIỆC: TẠO ẢNH · ĐIỀU PHỐI · KHAI KHOÁ TÌM KIẾM]
 * Bốn yêu cầu mới của chủ dự án, và lý do THẬT của từng cái:
 *   1. LỜI CHÀO NGẮN LẠI — câu cũ dài và cứng ("...tự tra internet khi cần dữ kiện, rồi trả lời...").
 *      Lời chào chỉ cần nói khung này làm được gì, bằng giọng người nói với người.
 *   2. Ô TẠO ẢNH NGAY TRONG CHAT — màn hình canvas trống đã BỊ GỠ ô mô tả tạo ảnh (xem
 *      components/CanvasEmptyState.vue), nên việc tạo ảnh phải có chỗ viết mô tả. Chỗ đó là ĐÂY, và
 *      nó gọi ĐÚNG MỘT đường đang có: kho dữ liệu generation với trường imagePromptEn cùng hàm
 *      generateImage() — KHÔNG dựng đường tạo ảnh thứ hai (đường thứ hai nghĩa là hai nơi để lệch
 *      nhau về tỉ lệ · độ phân giải · phom dáng · prefix/negative · dự án đang áp dụng).
 *   3. THẺ CHỨC NĂNG — chat thành chỗ ĐIỀU PHỐI: mỗi thẻ là một nút điều hướng THẬT (không phải chữ
 *      trang trí). Điều hướng TẤT ĐỊNH, không gọi model để quyết định — chat phải NHANH.
 *   4. MỤC KHAI API KEY TÌM KIẾM WEB — người dùng phải biết tìm khoá ở đâu và chạy lệnh gì, ngay
 *      trong sản phẩm, thay vì phải hỏi lại người viết mã.
 *
 * BỐN NGUYÊN TẮC CỦA PHẦN MỚI (đọc trước khi sửa):
 *   · MỘT ĐƯỜNG TẠO ẢNH: chỉ generateImage() của kho dữ liệu. Không fetch mới, không gọi /api/generate
 *     ở đây, không tự dựng payload.
 *   · KHÔNG TỰ BỊA TIẾN TRÌNH: trạng thái tạo ảnh đọc THẲNG từ kho dữ liệu (đang gửi · đang xếp hàng ·
 *     đang tạo · xong · lỗi) và phần trăm là con số CỦA KHO DỮ LIỆU. Giao diện không tự đếm, không tự
 *     chạy hoạt ảnh phần trăm.
 *   · MỘT NGUỒN CHO ĐIỀU HƯỚNG: thẻ «Gợi ý từ ảnh» đi qua store.requestActivity('concept') — ĐÚNG
 *     kênh mà OutputModule đang dùng, do StudioApp.vue tiêu thụ (activeActivity là biến CỤC BỘ ở đó).
 *     Ở đây KHÔNG sao chép luồng phân tích ảnh (không đụng tới /api/suggest/stream).
 *   · LỆNH CLI LÀ CHỮ CHO CHỦ SHOP, KHÔNG PHẢI NHÃN GIAO DIỆN: mục khai khoá in ra ĐÚNG câu lệnh phải
 *     chạy trên máy chủ. Đây là NGOẠI LỆ CÓ Ý THỨC của §6.1 (giao diện không rò chi tiết kỹ thuật): mục
 *     này tồn tại CHỈ ĐỂ chủ shop tự khai khoá, và không có cách nào nói việc đó mà giấu câu lệnh đi.
 *     Khoá thật KHÔNG bao giờ hiện ở đây — chỉ dạng mẫu tvly-… · AIza… và khoá đã lưu là khoá ĐÃ MÃ HOÁ.
 *
 * TÊN NHÀ CUNG CẤP AI / TÊN MODEL KHÔNG ĐƯỢC XUẤT HIỆN Ở BẤT KỲ ĐÂU (§6.1 luật 2): sự kiện provider
 * của luồng bị kho dữ liệu BỎ HẲN nên nó không chảy vào state hiển thị nào. (Mục khai khoá có nhắc tên
 * hai DỊCH VỤ TÌM KIẾM — Tavily và Google Programmable Search: đó là nơi người dùng phải tới để lấy
 * khoá, không phải nhà cung cấp model, nên luật trên không áp dụng.)
 *
 * PHONG CÁCH: Material + tối giản — MỘT bề mặt phẳng, không viền lồng nhau. Tin của NGƯỜI DÙNG là bong
 * bóng đặc bên phải; tin của TRỢ LÝ là chữ trần bên trái (không bong bóng) để câu trả lời dài đọc như
 * một đoạn văn, không bị nhốt trong khung. Không có <style scoped>: chỉ class tiện dụng + token sẵn có.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
// Gợi ý + cảnh báo + dòng số đo: HÀM/HẰNG DÙNG CHUNG với bước «Hỏi đáp» của Agent Studio, không chép lại.
import { agentChatMetaLine, agentChatNotes, CHAT_SUGGESTIONS } from '../store/actions/agentChat.js';
// Chữ của trợ lý: ĐỊNH DẠNG ở module thuần chatFormat.js, HIỆN ở components/ChatMessageText.vue.
// Cả hai khung chat dùng CHUNG hai file đó — hai bản sao là hai chỗ để lệch nhau.
import { assistantPlainText } from '../chatFormat.js';
// Copy vào bộ nhớ tạm: MỘT bản dùng chung (clipboard + đường dự phòng cho trình duyệt chặn clipboard).
import { copyPlainText } from '../chatCopy.js';
import BaseModal from './BaseModal.vue';
import ChatMessageText from './ChatMessageText.vue';
// Chỉ báo đang chờ của phần tạo ảnh: dùng <LoadingSpinner> dùng chung (§3) — KHÔNG tự vẽ thanh/chấm.
import LoadingSpinner from './LoadingSpinner.vue';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();
const logEl = ref(null);
const inputEl = ref(null);

// Bề mặt hội thoại: TẤT CẢ đọc từ store. Component KHÔNG giữ bản sao — hai bản sao là hai lịch sử.
const messages = computed(() => store.agentChatMessages || []);
const streaming = computed(() => !! store.agentChatStreaming);
const phaseLabel = computed(() => store.agentChatPhaseLabel || '');
const toolLine = computed(() => store.agentChatToolLine || '');
const error = computed(() => store.agentChatError || '');
const lastMeta = computed(() => store.agentChatLastMeta || null);
/** Cảnh báo + số đo của lượt vừa rồi: HÀM DÙNG CHUNG — xem khối chú thích ngay trên. */
const notes = computed(() => agentChatNotes(lastMeta.value));
const metaLine = computed(() => agentChatMetaLine(lastMeta.value));

/** Câu hỏi đang gõ — nháp thuộc về RIÊNG khung này, nên nó mới được giữ khi đóng/mở modal. */
const question = ref('');

/**
 * Khu vực hỏi: modal mở từ bất kỳ màn nào của Studio nên KHÔNG có bộ chọn khu vực ở đây (bộ đó nằm ở
 * trang Agent Studio). "all" = toàn bộ thị trường shop đang theo dõi — mặc định của hợp đồng máy chủ.
 */
const REGION = 'all';

/** Trần ký tự của MỘT lượt — chép đúng hợp đồng máy chủ (MAX_TURN_CHARS) để không gửi đi rồi bị 422. */
const MAX_TURN_CHARS = 4000;

/**
 * ĐƯỜNG DẪN TRANG AGENT STUDIO — MỘT chỗ khai trong file này.
 * Thẻ «Tín hiệu & Định hướng» là ĐIỀU HƯỚNG THẬT sang một TRANG riêng (không phải popup): trang đó đã
 * tồn tại từ 2026-09-25 và cũng là đích của mục 'stylist' trên thanh công cụ (StudioApp.vue). Đây
 * KHÔNG phải lối vào thứ hai cho cùng một việc — StudioApp giữ lối vào trên thanh công cụ, còn thẻ ở
 * đây là đường dẫn theo NGỮ CẢNH (người dùng đang hỏi trợ lý về tín hiệu thị trường).
 */
const AGENT_STUDIO_URL = '/agent-studio';

/** MỘT nguồn cho cả việc khoá nút Gửi lẫn câu nói vì sao khoá (§4.4 của docs/DESIGN_SYSTEM.md). */
const blockReason = computed(() => {
  if (streaming.value) return '';
  return String(question.value || '').trim() ? '' : 'Gõ câu hỏi rồi bấm nút gửi — ví dụ: «chất liệu nào đang lên?»';
});
const canAsk = computed(() => ! streaming.value && ! blockReason.value);

function scrollToEnd() {
  const el = logEl.value;
  if (el) el.scrollTop = el.scrollHeight;
}

/**
 * Tự cuộn xuống cuối khi chữ đang chảy. Theo dõi ĐỘ DÀI của lượt cuối, không chỉ SỐ tin nhắn: chữ về
 * từng mảnh nên nếu chỉ đếm số tin thì câu trả lời dài sẽ chảy xuống dưới tầm nhìn mà màn hình đứng im.
 */
watch(
  () => {
    const list = messages.value;
    const last = list[list.length - 1];
    const citations = (last && last.citations && last.citations.length) || 0;
    return list.length + ':' + String((last && last.text) || '').length + ':' + citations;
  },
  () => nextTick(scrollToEnd),
);

// Mở modal là ĐƯA CON TRỎ vào ô hỏi: người dùng vừa bấm nút «Trợ lý» để hỏi, bắt họ bấm thêm một lần
// vào ô nhập là một bước thừa. (BaseModal đã lo focus trap và Esc.)
watch(() => store.chatOpen, (open) => { if (open) nextTick(() => { scrollToEnd(); if (inputEl.value) inputEl.value.focus(); }); });
onMounted(() => nextTick(scrollToEnd));

/**
 * Gửi một câu hỏi. Không truyền gì ⇒ lấy chữ đang gõ (nút gửi · phím Enter); truyền câu gợi ý ⇒ gửi
 * luôn câu đó (gợi ý là câu hỏi HOÀN CHỈNH, không phải mẫu để sửa).
 *
 * Ô nhập CHỈ bị xoá khi câu hỏi ĐÃ ĐI: action trả về false khi CHƯA gửi được (hết phiên · thiếu gói ·
 * mất mạng) — giữ lại chữ người dùng vừa gõ là cách duy nhất để họ không phải gõ lại từ đầu.
 */
async function ask(text) {
  const value = String(text == null ? question.value : text).trim();
  if (! value || streaming.value) return;
  const sent = await store.agentChatAsk(value, REGION);
  if (sent) question.value = '';
  nextTick(scrollToEnd);
}

/** Dừng giữa lượt — phần chữ ĐÃ NHẬN được giữ lại (kho dữ liệu lo việc đó, xem agentChatStop). */
function stopAsking() { return store.agentChatStop(); }

/** Hội thoại mới — chỉ xoá ở màn hình; dữ liệu shop và những nguồn đã tra không bị đụng tới. */
function resetChat() { store.agentChatReset(); nextTick(scrollToEnd); }

/**
 * COPY MỘT TIN NHẮN (yêu cầu chủ dự án 2026-09-26) — nút Copy có ở TỪNG tin, cả hai vai.
 *
 * Vì sao hai vai copy HAI KIỂU chữ khác nhau: câu hỏi của người dùng là chữ của CHÍNH HỌ nên chép
 * nguyên văn; còn câu trả lời của trợ lý thì chép bản CHỮ SẠCH (assistantPlainText) — người dùng dán
 * vào tài liệu · email · ô mô tả ảnh, dán kèm dấu sao và backtick là mang ký tự của máy sang chỗ khác.
 *
 * Vì sao thất bại phải thành CÂU CHỮ: trình duyệt chặn clipboard khi trang không phải HTTPS hoặc khi
 * cú bấm không phải thao tác trực tiếp. Nuốt lỗi vào console thì người dùng chỉ thấy nút KHÔNG LÀM GÌ
 * — im lặng là kiểu nói dối tệ nhất của một nút bấm.
 */
async function copyMessage(message) {
  const isUser = message.role === 'user';
  const text = isUser ? String(message.text || '') : assistantPlainText(message.text);
  const ok = await copyPlainText(text);
  if (ok) {
    store.toast(isUser ? 'Đã copy câu hỏi của bạn vào bộ nhớ tạm.' : 'Đã copy câu trả lời vào bộ nhớ tạm.');
    return;
  }
  store.toast('Trình duyệt chặn việc copy — bạn bôi đen chữ rồi copy tay giúp.', 'error');
}

/** Nhãn nút Copy nói rõ COPY CÁI GÌ — khung này có hai loại tin nhắn nên "Copy" trống là nhập nhằng. */
function copyTitle(message) {
  return message.role === 'user' ? 'Copy câu hỏi này' : 'Copy câu trả lời này';
}

/**
 * Thêm một dấu xuống dòng tại ĐÚNG VỊ TRÍ CON TRỎ (yêu cầu chủ dự án 2026-09-26).
 *
 * Vì sao cần nút này: trên ĐIỆN THOẠI, Enter là GỬI (không có phím Shift), nên người đang gõ giữa câu
 * không có cách nào xuống dòng. Quy ước được chép ĐÚNG sang bước «Hỏi đáp» của Agent Studio
 * (components/agents/AgentChatStep.vue): cùng icon cornerDownLeft, cùng lối xử lý con trỏ. Lý do phải
 * giống nhau: hai ô nhập trong CÙNG một sản phẩm mà hành xử khác nhau thì người dùng học một lần rồi
 * bấm sai ở ô kia. (Bản gốc của quy ước này từng là ô mô tả tạo ảnh ở màn hình canvas trống — ô đó
 * đã gỡ 2026-09-26, xem components/CanvasEmptyState.vue.)
 *
 * Con trỏ được đặt LẠI ngay SAU ký tự vừa chèn (start + 1), không nhảy về cuối: người đang sửa giữa
 * câu mà bị đẩy về cuối là mất chỗ đang gõ.
 */
function insertNewline() {
  const el = inputEl.value;
  if (! el) return;
  const start = el.selectionStart ?? question.value.length;
  const end = el.selectionEnd ?? start;
  question.value = question.value.slice(0, start) + '\n' + question.value.slice(end);
  nextTick(() => {
    el.selectionStart = el.selectionEnd = start + 1;
    el.focus();
  });
}

// ═════════════════════════════════════════════════════════════════════════════
// 1) Ô TẠO ẢNH NGAY TRONG CHAT
// ═════════════════════════════════════════════════════════════════════════════
/** Ô mô tả có đang mở trong chat không. Nháp + trạng thái gửi thuộc RIÊNG khung này. */
const imageOpen = ref(false);
const imageDraft = ref('');
/** Đã bấm gửi yêu cầu tạo ảnh trong phiên này ⇒ hiện THẺ KẾT QUẢ + nút «Về canvas». */
const imageSent = ref(false);
/** Mô tả ĐÃ GỬI (khác nháp đang gõ) — thẻ kết quả phải nói đúng thứ đã đi, không phải thứ đang gõ dở. */
const imageSentPrompt = ref('');
const imageBox = ref(null);

function focusImageBox() {
  nextTick(() => { if (imageBox.value) imageBox.value.focus(); });
}

/**
 * Mở ô mô tả trong chat. Không truyền gì ⇒ nạp sẵn mô tả ĐANG có ở kho dữ liệu (trường imagePromptEn):
 * người dùng có thể đã gõ nó ở bảng Prompt Tạo Ảnh đầy đủ hoặc nhận từ nút «Đưa vào mô tả ảnh» — mở ra
 * một ô TRỐNG trong khi mô tả cũ vẫn nằm đó là để họ tưởng mình chưa viết gì.
 */
function openImageComposer(prefill) {
  imageOpen.value = true;
  imageSent.value = false;
  imageDraft.value = String(prefill == null ? (store.imagePromptEn || '') : prefill);
  focusImageBox();
}

/** MỘT nguồn cho cả việc khoá nút «Tạo ảnh» lẫn câu nói vì sao khoá (§4.4 của docs/DESIGN_SYSTEM.md). */
const imageBlockReason = computed(() => (
  String(imageDraft.value || '').trim()
    ? ''
    : 'Chưa có mô tả — gõ vài chữ tả tấm ảnh bạn muốn rồi bấm Tạo ảnh.'
));
const canSendImage = computed(() => ! imageBlockReason.value && ! store.generating);

/**
 * CÂU TRẢ LỜI MỚI NHẤT CỦA TRỢ LÝ, ở dạng CHỮ SẠCH — dùng cho nút «Lấy từ câu trả lời».
 * Vì sao CHỮ SẠCH: mô tả ảnh đi thẳng vào máy tạo ảnh, nên dấu sao và backtick là rác — máy đọc nó
 * như chữ thật. Cùng lý do với nút «Đưa vào mô tả ảnh» cũ.
 */
const lastAnswerText = computed(() => {
  const list = messages.value;
  for (let i = list.length - 1; i >= 0; i -= 1) {
    const m = list[i];
    if (m.role !== 'user' && ! m.failed && String(m.text || '').trim()) return assistantPlainText(m.text).trim();
  }
  return '';
});

/** Chèn câu trả lời mới nhất vào ô mô tả — NỐI THÊM, không đè lên chữ người dùng đã gõ. */
function fillFromAnswer() {
  const line = lastAnswerText.value;
  if (! line) return;
  const cur = String(imageDraft.value || '').trim();
  imageDraft.value = cur ? (cur + ' ' + line) : line;
  focusImageBox();
}

/**
 * GỬI YÊU CẦU TẠO ẢNH — ĐÚNG MỘT ĐƯỜNG: ghi mô tả vào trường imagePromptEn rồi gọi hàm generateImage()
 * của kho dữ liệu (store/actions/generation.js). Đó cũng chính là đường mà ô mô tả ở màn hình canvas
 * trống (trước 2026-09-26) và card «Tạo ảnh» đang gọi.
 *
 * Vì sao KHÔNG tự gọi /api/generate ở đây: payload tạo ảnh gồm mười mấy trường (tỉ lệ · độ phân giải ·
 * biến thể · phom dáng · tóc · prefix/suffix/negative · model đang chọn · bộ sưu tập đang áp dụng ·
 * seed). Dựng lại payload ở khung chat là bản sao thứ hai, và nó sẽ lệch ngay lần đầu ai đó thêm một
 * trường mới — người dùng bấm cùng một chữ «Tạo ảnh» mà nhận hai cấu hình khác nhau.
 */
function sendImageRequest() {
  const prompt = String(imageDraft.value || '').trim();
  if (! prompt || store.generating) return;
  store.imagePromptEn = prompt;
  imageSentPrompt.value = prompt;
  imageSent.value = true;
  store.generateImage();
  nextTick(scrollToEnd);
}

/**
 * TRẠNG THÁI THẬT của lần gửi VỪA RỒI — đọc thẳng từ kho dữ liệu, KHÔNG suy diễn, KHÔNG tự bấm giờ.
 *
 * Bốn nhãn, mỗi nhãn là một trạng thái THẬT của kho dữ liệu (generateStage · generateProgress ·
 * generatedCount · lastBatch — xem store/actions/generation.js và actions/sources.js):
 *   đang gửi  → store.generating (yêu cầu đang đi)
 *   lỗi       → generateStage === 'failed'
 *   xong      → generateStage === 'done'
 *   còn lại   → có lô ảnh đang chờ/tạo: hiện ĐÚNG số phần trăm của kho dữ liệu
 * Chưa có lô nào (lastBatch rỗng) thì nói thẳng là CHƯA GỬI ĐƯỢC — im lặng hoặc hiện 0% đều là nói dối.
 */
const imageProgress = computed(() => {
  const total = (store.lastBatch || []).length;
  const done = Number(store.generatedCount) || 0;
  const stage = String(store.generateStage || '');
  if (store.generating) return { busy: true, warn: false, percent: null, text: 'Đang gửi yêu cầu tạo ảnh…' };
  if (stage === 'failed' && total) return { busy: false, warn: true, percent: null, text: 'Máy tạo ảnh báo lỗi cho lô này — bạn thử lại giúp.' };
  if (stage === 'done' && total) return { busy: false, warn: false, percent: null, text: 'Đã tạo xong ' + done + '/' + total + ' ảnh — ảnh đã nằm trên canvas.' };
  if (total > 0) {
    const percent = Math.max(0, Math.min(100, Number(store.generateProgress) || 0));
    return { busy: true, warn: false, percent, text: stage === 'rendering' ? 'Đang tạo ảnh…' : 'Đã xếp hàng — đang chờ máy tạo ảnh…' };
  }
  return { busy: false, warn: true, percent: null, text: 'Chưa gửi được yêu cầu tạo ảnh — bạn thử lại giúp.' };
});

// ═════════════════════════════════════════════════════════════════════════════
// 2) THẺ CHỨC NĂNG — ĐIỀU PHỐI TẤT ĐỊNH, KHÔNG GỌI MODEL
// ═════════════════════════════════════════════════════════════════════════════
/**
 * Mỗi thẻ là MỘT NÚT ĐIỀU HƯỚNG THẬT. Ba luật khi thêm thẻ mới:
 *   · chỉ dùng lối vào ĐANG CÓ trong mã — thẻ nào không có đường thật thì BỎ, không dựng nút chết;
 *   · điều hướng TẤT ĐỊNH (mở cờ · gọi hàm), KHÔNG gọi model để quyết định — chat phải mở là dùng được;
 *   · thẻ nào mở ra một lớp phủ/trang khác thì phải ĐÓNG CHAT, nếu không lớp phủ của chat che đúng thứ
 *     vừa mở (người dùng bấm mà không thấy gì xảy ra).
 */
const actionCards = computed(() => [
  {
    id: 'image', icon: 'image', label: 'Tạo ảnh',
    title: 'Viết mô tả ngay trong khung chat này rồi gửi yêu cầu tạo ảnh',
    run: () => openImageComposer(),
  },
  {
    id: 'concept', icon: 'lightbulb', label: 'Gợi ý từ ảnh',
    title: 'Đưa bạn tới card «Gợi ý từ ảnh»: đọc một ảnh mẫu rồi gợi ý prompt. Khung chat sẽ đóng lại.',
    run: goConcept,
  },
  {
    id: 'trend', icon: 'target', label: 'Tín hiệu & Định hướng',
    title: 'Mở trang Tín hiệu & Định hướng — tín hiệu thị trường và định hướng bộ sưu tập',
    run: goAgentStudio,
  },
  {
    id: 'library', icon: 'library', label: 'Thư viện',
    title: 'Mở Thư viện ảnh — ảnh đã tạo và file bạn tải lên. Khung chat sẽ đóng lại.',
    run: goLibrary,
  },
  {
    id: 'projects', icon: 'folderOpen', label: 'Bộ sưu tập',
    title: 'Mở bảng Bộ sưu tập & dự án. Khung chat sẽ đóng lại.',
    run: goProjects,
  },
  {
    id: 'prompt', icon: 'sliders', label: 'Bảng prompt',
    title: 'Mở bảng Prompt Tạo Ảnh đầy đủ: prefix, negative, phom dáng, mẫu việc. Khung chat sẽ đóng lại.',
    run: goPromptPanel,
  },
]);

/**
 * «Gợi ý từ ảnh» — TRỎ tới card đang có, KHÔNG chép luồng phân tích.
 * Card đó nằm ở nhóm công cụ 'concept' (StudioApp.vue: concept: [SuggestCard]), mà activeActivity là
 * biến CỤC BỘ của StudioApp — nên đường đi là kênh yêu cầu có sẵn trong kho dữ liệu:
 * requestActivity('concept') (store/actions/projects.js). StudioApp theo dõi kênh đó và mở đúng nhóm.
 */
function goConcept() {
  store.requestActivity('concept');
  store.chatOpen = false;
  store.toast('Đã mở «Gợi ý từ ảnh» — chọn một ảnh rồi bấm phân tích.');
}

/** «Tín hiệu & Định hướng» — TRANG riêng, điều hướng thật (không phải popup trong /studio). */
function goAgentStudio() { window.location.href = AGENT_STUDIO_URL; }

/** «Thư viện» — đi qua hàm DUY NHẤT của kho dữ liệu (nó thoát công cụ canvas rồi đổi cờ studioView). */
function goLibrary() { store.openLibrary(); store.chatOpen = false; }

/** «Bộ sưu tập» — kênh yêu cầu có sẵn: StudioApp mở bảng Bộ sưu tập khi bộ đếm này tăng. */
function goProjects() { store.requestWorkspace(); store.chatOpen = false; }

/** «Bảng prompt» — popup Prompt Tạo Ảnh đầy đủ, cùng cờ mà nút ở canvas trống đang dùng. */
function goPromptPanel() { store.promptOpen = true; store.chatOpen = false; }

// ═════════════════════════════════════════════════════════════════════════════
// 3) MỤC «CÁCH ĐĂNG KÝ API KEY TÌM KIẾM WEB» — gấp lại, MẶC ĐỊNH ĐÓNG
// ═════════════════════════════════════════════════════════════════════════════
const apiKeyOpen = ref(false);

/**
 * HAI câu lệnh, chép NGUYÊN từ app/Console/Commands/WebSearchSetupCommand.php (signature của lệnh):
 *   · Tavily  — --provider=tavily, chạy được NGAY cả khi không có khoá (chế độ keyless của Tavily);
 *   · Google  — cần --key (API key) và --cx (Search engine ID).
 * Khoá ở đây chỉ là DẠNG MẪU (tvly-… · AIza…) để người dùng biết nó trông thế nào — KHÔNG có khoá thật
 * nào trong mã nguồn, và khoá đã lưu thì nằm ĐÃ MÃ HOÁ trong bảng khoá của máy chủ.
 */
const TAVILY_COMMAND = 'php artisan studio:web-search-setup --provider=tavily --key=tvly-…';
const GOOGLE_COMMAND = 'php artisan studio:web-search-setup --key=AIza… --cx=…';

/** Copy một câu lệnh cho người dùng dán vào terminal — dùng ĐƯỜNG COPY DÙNG CHUNG (chatCopy.js). */
async function copySnippet(text, what) {
  const ok = await copyPlainText(text);
  if (ok) { store.toast('Đã copy ' + what + ' vào bộ nhớ tạm.'); return; }
  store.toast('Trình duyệt chặn việc copy — bạn bôi đen chữ rồi copy tay giúp.', 'error');
}

/**
 * CẦU NỐI "tìm hiểu → làm": đưa CÂU TRẢ LỜI của trợ lý vào Ô MÔ TẢ ẢNH trong chat (không còn nhảy ra
 * canvas trống — ô mô tả ở đó đã gỡ 2026-09-26).
 *
 * [ĐỔI CHÍNH SÁCH 2026-09-26] Trước đây nút này ghi thẳng vào trường imagePromptEn rồi ĐÓNG modal, và
 * người dùng phải tự đi tìm ô mô tả ở canvas. Nay nó mở ô mô tả NGAY TRONG chat với câu trả lời đã
 * chèn sẵn — người dùng sửa lại cho vừa ý rồi bấm «Tạo ảnh» tại chỗ. Một đường, không hai.
 *
 * Vì sao NỐI THÊM chứ không ghi đè: mô tả đang gõ là việc của người dùng, đè lên là xoá mất nó.
 */
function useAnswer(text) {
  const line = assistantPlainText(text).trim();
  if (! line) return;
  openImageComposer(line);
  store.toast('Đã đưa câu trả lời vào ô mô tả ảnh — bạn sửa lại cho vừa ý rồi bấm «Tạo ảnh».');
}

function onKeydown(event) {
  // Enter GỬI · Shift+Enter XUỐNG DÒNG — quy ước của mọi ô chat.
  // isComposing PHẢI được tôn trọng: bộ gõ tiếng Việt dùng Enter để CHỐT DẤU, chặn nó là gõ dấu là gửi.
  if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
  event.preventDefault();
  ask();
}
</script>

<template>
  <BaseModal
    :model-value="store.chatOpen"
    title="Trợ lý thiết kế"
    wide
    height="min(80vh, 720px)"
    @update:model-value="store.chatOpen = $event"
  >
    <!-- MỘT bề mặt phẳng: thân modal chia ba tầng — dải đầu · danh sách tin (cuộn) · thanh soạn tin. -->
    <div class="flex h-full flex-col">
      <div class="flex items-center gap-2 px-4 pb-2 pt-3">
        <!-- [2026-09-26] Câu này TRƯỚC ĐÂY hứa "câu trả lời kèm nguồn bấm được để bạn tự kiểm" — khối
             nguồn đã gỡ hẳn khỏi giao diện theo yêu cầu chủ dự án, nên giữ nguyên lời hứa đó là nói sai
             với người dùng. Nay câu chỉ còn nói việc trợ lý THẬT SỰ làm. -->
        <p class="min-w-0 flex-1 text-tiny leading-4 text-cream-400">
          Trợ lý đọc hồ sơ thương hiệu của shop và tự tra internet khi cần để trả lời.
        </p>
        <button v-if="messages.length" type="button" data-chat-reset
                class="tool-btn !px-2 !py-1 shrink-0 !text-tiny"
                title="Bắt đầu hội thoại mới (không ảnh hưởng dữ liệu shop của bạn)" @click="resetChat">
          <StudioIcon name="refresh" size="h-3 w-3" /> Hội thoại mới
        </button>
      </div>

      <div ref="logEl" class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 pb-3" data-chat-log role="log" aria-live="polite">
        <!-- TRẠNG THÁI RỖNG: nói thật khung này làm được gì + ba câu gợi ý BẤM ĐƯỢC (mỗi câu một việc).
             [2026-09-26] Lời chào VIẾT LẠI cho ngắn và mềm: câu cũ dài, cứng và nhiều chữ kỹ thuật. Ba
             sự thật vẫn phải còn ĐỦ — (a) trợ lý đọc hồ sơ/quy tắc của shop, (b) tự tra web khi cần,
             (c) không có dữ liệu thì nói thật là chưa có. -->
        <div v-if="!messages.length" class="py-6">
          <p class="text-sm font-semibold text-cream-100">Cùng xem bộ sưu tập bạn đang làm nhé</p>
          <p class="mt-1 max-w-xl text-body leading-relaxed text-cream-300">
            Mình đọc hồ sơ và quy tắc bạn đã khai, cần thì tra thêm trên web. Chưa có dữ liệu thì mình nói thật là chưa có.
          </p>
          <div class="mt-3 flex flex-wrap gap-1.5">
            <button v-for="item in CHAT_SUGGESTIONS" :key="item.text" type="button" data-chat-suggestion
                    class="rounded-full border border-ink-600 bg-ink-800 px-3 py-1.5 text-label font-semibold text-cream-200 transition-colors hover:border-brand-400 hover:text-cream-50"
                    :title="item.why" @click="ask(item.text)">{{ item.text }}</button>
          </div>

          <!-- ── CÁCH ĐĂNG KÝ API KEY TÌM KIẾM WEB — GẤP LẠI, MẶC ĐỊNH ĐÓNG ──
               Vì sao để trong chat: đây là việc của CHỦ SHOP trên máy chủ, không phải việc hằng ngày —
               mở sẵn ra là chiếm chỗ của câu hỏi. Vì sao vẫn phải có: tìm kiếm web đang chạy ở chế độ
               KHÔNG CẦN KHOÁ, nên người dùng cần biết (a) nó đã chạy, (b) muốn hạn mức riêng thì lấy
               khoá ở đâu và chạy lệnh gì. Nội dung ở đây KHỚP với HUONG_DAN_TINH_NANG_MOI.md §11.6 và
               signature của app/Console/Commands/WebSearchSetupCommand.php — sửa một chỗ thì sửa cả ba. -->
          <div class="mt-5 rounded-xl border border-ink-700 bg-ink-900/60">
            <button type="button" data-chat-apikey class="flex w-full items-center gap-2 px-3 py-2 text-left"
                    :aria-expanded="apiKeyOpen ? 'true' : 'false'" @click="apiKeyOpen = ! apiKeyOpen">
              <StudioIcon name="key" size="h-3.5 w-3.5" class="shrink-0 text-brand-300" />
              <span class="min-w-0 flex-1 text-label font-semibold text-cream-200">Cách đăng ký API key tìm kiếm web</span>
              <StudioIcon :name="apiKeyOpen ? 'chevronUp' : 'chevronDown'" size="h-3.5 w-3.5" class="shrink-0 text-cream-400" />
            </button>

            <div v-if="apiKeyOpen" class="space-y-3 border-t border-ink-700 px-3 py-3">
              <!-- (1) TAVILY — đường đang chạy, KHÔNG cần khoá -->
              <div>
                <p class="text-label font-semibold text-cream-100">Tavily — khuyên dùng, đang chạy</p>
                <p class="mt-1 text-tiny leading-5 text-cream-300">
                  Hiện đã bật chế độ KHÔNG CẦN KHOÁ nên tìm kiếm web đã chạy. Muốn hạn mức riêng thì vào
                  app.tavily.com → đăng nhập → copy khoá dạng tvly-… (miễn phí 1.000 credit/tháng, không
                  cần thẻ) → chạy trên máy chủ:
                </p>
                <div class="mt-1.5 flex items-start gap-2 rounded-lg bg-ink-950/70 px-2.5 py-2">
                  <code class="min-w-0 flex-1 select-all break-all font-mono text-tiny text-cream-200">{{ TAVILY_COMMAND }}</code>
                  <button type="button" data-chat-apikey-copy class="tool-btn !px-2 !py-1 !text-tiny shrink-0"
                          title="Copy câu lệnh Tavily" @click="copySnippet(TAVILY_COMMAND, 'câu lệnh Tavily')">
                    <StudioIcon name="copy" size="h-3 w-3" /> Copy
                  </button>
                </div>
              </div>

              <!-- (2) GOOGLE PROGRAMMABLE SEARCH — đường thay thế, BẮT BUỘC có khoá -->
              <div>
                <p class="text-label font-semibold text-cream-100">Google Programmable Search — thay thế</p>
                <p class="mt-1 text-tiny leading-5 text-cream-300">
                  Tạo engine ở programmablesearchengine.google.com và BẬT "Search the entire web" → lấy mã
                  cx → bật Custom Search API trong console.cloud.google.com → tạo API key → chạy trên máy chủ:
                </p>
                <div class="mt-1.5 flex items-start gap-2 rounded-lg bg-ink-950/70 px-2.5 py-2">
                  <code class="min-w-0 flex-1 select-all break-all font-mono text-tiny text-cream-200">{{ GOOGLE_COMMAND }}</code>
                  <button type="button" data-chat-apikey-copy class="tool-btn !px-2 !py-1 !text-tiny shrink-0"
                          title="Copy câu lệnh Google" @click="copySnippet(GOOGLE_COMMAND, 'câu lệnh Google')">
                    <StudioIcon name="copy" size="h-3 w-3" /> Copy
                  </button>
                </div>
              </div>

              <!-- (3) HAI điều phải nói rõ, không hứa gì thêm -->
              <p class="text-tiny leading-5 text-cream-400">
                Khoá được lưu ở dạng ĐÃ MÃ HOÁ và không hiện lại trên màn hình. Bạn KHÔNG cần khoá nếu
                chấp nhận dùng chung hạn mức có sẵn.
              </p>
            </div>
          </div>
        </div>

        <div v-for="(m, i) in messages" :key="i">
          <!-- TIN CỦA NGƯỜI DÙNG: bong bóng ĐẶC, dồn phải — đọc ra ngay ai đang nói.
               Nút Copy đứng BÊN TRÁI bong bóng (không phải trên nó): đặt trên bong bóng là che mất chữ
               của chính tin nhắn đó, còn đặt bên phải là đẩy bong bóng lệch khỏi mép phải. -->
          <div v-if="m.role === 'user'" class="flex items-center justify-end gap-1.5">
            <button type="button" data-chat-copy class="icon-btn !h-7 !w-7 shrink-0"
                    :title="copyTitle(m)" :aria-label="copyTitle(m)" @click="copyMessage(m)">
              <StudioIcon name="copy" size="h-3 w-3" />
            </button>
            <!-- Chữ của NGƯỜI DÙNG hiện NGUYÊN VĂN (whitespace-pre-wrap): đây là chữ họ tự gõ. -->
            <div class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-brand-600 px-3.5 py-2 text-body text-primary-content">{{ m.text }}</div>
          </div>

          <!-- TIN CỦA TRỢ LÝ: chữ TRẦN, không bong bóng — câu trả lời dài phải đọc như một đoạn văn. -->
          <div v-else class="flex gap-2">
            <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-600/15 text-brand-300">
              <StudioIcon name="bot" size="h-3.5 w-3.5" />
            </span>
            <div class="min-w-0 flex-1">
              <!-- Lỗi của RIÊNG lượt này: câu hướng dẫn đã qua userFacingError ở kho dữ liệu (§6.1 luật 5). -->
              <p v-if="m.failed" class="text-body text-warn">Chưa trả lời được câu này. Bạn thử hỏi lại sau ít phút.</p>
              <template v-else>
                <!-- CHỮ CỦA TRỢ LÝ đi qua ChatMessageText dùng chung ⇒ đậm · nghiêng · mã · link là thẻ
                     THẬT, người dùng KHÔNG còn nhìn thấy dấu sao, dấu gạch đầu dòng hay [chữ](địa chỉ). -->
                <ChatMessageText v-if="m.text" :text="m.text" />
                <p v-else class="text-body text-cream-400">Đang trả lời…</p>

                <!-- NÓI THẬT khi người dùng bấm Dừng: phần chữ đã nhận được GIỮ LẠI, không xoá đi. -->
                <p v-if="m.stopped" class="mt-1 text-tiny text-cream-400">Bạn đã dừng lượt này — phần trả lời ở trên là phần đã nhận được.</p>

                <!-- [2026-09-26 · GỠ HẲN KHỐI "NGUỒN ĐỂ BẠN TỰ KIỂM" — ĐỌC TRƯỚC KHI ĐỊNH THÊM LẠI]
                     Chủ dự án yêu cầu ẩn VĨNH VIỄN khối này khỏi người dùng vì nó làm rối khung chat.
                     Cách làm: XOÁ HẲN khỏi template — KHÔNG ẩn bằng CSS, KHÔNG để lại nút mở lại. Lý do
                     phải xoá hẳn chứ không ẩn: khối còn trong DOM thì trình đọc màn hình vẫn đọc, Ctrl+F
                     vẫn tìm thấy, và nó tự quay lại ngay khi ai đó gỡ một class.
                     DỮ LIỆU KHÔNG BỊ XOÁ: m.citations vẫn nguyên trong kho dữ liệu dùng chung
                     (store/actions/agentChat.js) — chỉ không hiển thị. Nếu sau này cần trả lại thì phải
                     là hành động NGƯỜI DÙNG CHỦ ĐỘNG (bấm mới hiện), KHÔNG tự hiện như trước. -->

                <!-- COPY: dùng cho CẢ HAI vai, tin của trợ lý copy bản CHỮ SẠCH (copyMessage). -->
                <div v-if="m.text" class="mt-2 flex flex-wrap items-center gap-1.5">
                  <button type="button" data-chat-copy class="tool-btn !px-2 !py-1 !text-tiny"
                          :title="copyTitle(m)" :aria-label="copyTitle(m)" @click="copyMessage(m)">
                    <StudioIcon name="copy" size="h-3 w-3" /> Copy
                  </button>

                  <!-- CẦU NỐI "tìm hiểu → làm": câu trả lời đi vào Ô MÔ TẢ ẢNH trong chat (useAnswer). -->
                  <button v-if="! m.streaming" type="button" class="tool-btn !px-2 !py-1 !text-tiny" data-use-answer
                          title="Đưa câu trả lời này vào ô mô tả tạo ảnh trong khung chat" @click="useAnswer(m.text)">
                    <StudioIcon name="wand" size="h-3 w-3" /> Đưa vào mô tả ảnh
                  </button>
                </div>
              </template>
            </div>
          </div>
        </div>

        <!-- Tiến trình THẬT của lượt đang chạy: nhãn giai đoạn + đang tra gì (cả hai do máy chủ gửi). -->
        <p v-if="streaming" class="flex flex-wrap items-center gap-2 text-body text-cream-400">
          <StudioIcon name="refresh" size="h-3.5 w-3.5" class="animate-spin" /> {{ phaseLabel || 'Đang suy luận…' }}
          <span v-if="toolLine" class="text-cream-400">· {{ toolLine }}</span>
        </p>

        <!-- ── THẺ KẾT QUẢ của lần gửi yêu cầu tạo ảnh VỪA RỒI ──
             Nói rõ ĐÃ GỬI ĐI CÁI GÌ (mô tả đã gửi, không phải nháp đang gõ), TRẠNG THÁI THẬT lấy từ kho
             dữ liệu, và cho MỘT nút «Về canvas» để đóng chat mà xem ảnh. Không có nút «Về canvas» thì
             người dùng phải tự đoán là phải đóng khung chat mới thấy ảnh. -->
        <div v-if="imageSent" data-chat-image-status class="rounded-xl border border-brand-500/40 bg-ink-900/70 p-3">
          <p class="text-body font-semibold text-cream-100">Đã gửi yêu cầu tạo ảnh.</p>
          <p class="mt-1 break-words text-tiny text-cream-400">Mô tả đã gửi: {{ imageSentPrompt }}</p>
          <LoadingSpinner v-if="imageProgress.busy" size="sm" :text="imageProgress.text" :progress="imageProgress.percent" class="!py-2" />
          <p v-else class="mt-2 text-tiny" :class="imageProgress.warn ? 'text-warn' : 'text-cream-300'">{{ imageProgress.text }}</p>
          <button type="button" data-chat-back-canvas class="tool-btn mt-2 !px-2 !py-1 !text-tiny"
                  title="Đóng khung chat để xem canvas và ảnh đang tạo" @click="store.chatOpen = false">
            <StudioIcon name="image" size="h-3 w-3" /> Về canvas
          </button>
        </div>

        <!-- ── THẺ CHỨC NĂNG: chat là chỗ ĐIỀU PHỐI — mỗi thẻ mở ĐÚNG một việc đang có trong sản phẩm ──
             Một khối duy nhất, đặt ngay SAU danh sách tin: ở trạng thái rỗng nó nằm dưới lời chào, khi đã
             có trò chuyện nó nằm dưới câu trả lời mới nhất. Đặt hai bản (một ở trạng thái rỗng, một sau
             câu trả lời) là hai chỗ để lệch nhau — mỗi lần thêm thẻ phải sửa hai nơi. -->
        <div data-chat-actions class="border-t border-ink-700/70 pt-3">
          <p class="text-tiny text-cream-400">Chọn việc tiếp theo — thẻ nào mở chỗ khác thì khung chat sẽ đóng lại.</p>
          <div class="mt-2 flex flex-wrap gap-1.5">
            <button v-for="card in actionCards" :key="card.id" type="button" :data-chat-card="card.id"
                    class="tool-btn !px-2.5 !py-1.5 !text-tiny" :title="card.title" @click="card.run()">
              <StudioIcon :name="card.icon" size="h-3.5 w-3.5" /> {{ card.label }}
            </button>
          </div>
        </div>
      </div>

      <!-- SỐ ĐO + cảnh báo của lượt vừa rồi: nguyên văn từ máy chủ, không tự bấm giờ ở trình duyệt.
           Đặt NGAY TRÊN thanh soạn tin (không nằm trong vùng cuộn) để câu cảnh báo không trôi mất. -->
      <div v-if="!streaming && (metaLine || notes.length)" class="space-y-1 px-4 pb-1.5">
        <p v-if="metaLine" class="text-tiny text-cream-400">{{ metaLine }}</p>
        <p v-for="note in notes" :key="note" class="text-tiny text-warn">↳ {{ note }}</p>
      </div>

      <!-- ── Ô TẠO ẢNH TRONG CHAT (không phải modal thứ hai) ──
           Mở bằng thẻ «Tạo ảnh» hoặc bằng nút «Đưa vào mô tả ảnh» của một câu trả lời. Đóng lại thì mô
           tả vẫn nằm ở trường imagePromptEn của kho dữ liệu — mở bảng Prompt Tạo Ảnh đầy đủ là thấy lại. -->
      <div v-if="imageOpen" data-chat-image class="border-t border-ink-700 px-4 py-3">
        <div class="flex items-center gap-2">
          <StudioIcon name="image" size="h-3.5 w-3.5" class="shrink-0 text-brand-300" />
          <label for="chat-image-prompt" class="min-w-0 flex-1 text-label font-semibold text-cream-200">Mô tả ảnh cần tạo</label>
          <button type="button" data-chat-image-close class="tool-btn !px-2 !py-1 !text-tiny shrink-0"
                  title="Đóng ô này (mô tả vẫn được giữ)" @click="imageOpen = false">Đóng ô này</button>
        </div>

        <label for="chat-image-prompt" class="sr-only">Mô tả ảnh cần tạo</label>
        <textarea id="chat-image-prompt" ref="imageBox" v-model="imageDraft" rows="3"
                  class="input mt-1.5 max-h-[24vh] w-full resize-none overflow-y-auto !text-body"
                  placeholder="Ví dụ: Váy linen pastel dáng suông, nữ văn phòng, ánh sáng mềm, nền studio sáng…"></textarea>

        <div class="mt-1.5 flex flex-wrap items-center gap-2">
          <!-- CHÈN TỪ CÂU TRẢ LỜI: chỉ hiện khi THẬT SỰ có câu trả lời — nút bấm vào không có gì là nút chết. -->
          <button v-if="lastAnswerText" type="button" data-chat-image-from-answer class="tool-btn !px-2 !py-1 !text-tiny"
                  title="Chèn câu trả lời mới nhất của trợ lý vào ô mô tả (nối thêm, không đè chữ bạn đã gõ)"
                  @click="fillFromAnswer">
            <StudioIcon name="wand" size="h-3 w-3" /> Lấy từ câu trả lời
          </button>
          <button type="button" data-chat-image-send class="btn-brand btn-sm ml-auto flex items-center gap-1.5"
                  :disabled="!canSendImage" title="Gửi yêu cầu tạo ảnh với mô tả này" @click="sendImageRequest">
            <StudioIcon name="zap" size="h-3.5 w-3.5" /> Tạo ảnh
          </button>
        </div>
        <!-- Dòng lý do nằm NGAY DƯỚI nút chính — quy tắc §4.4 của docs/DESIGN_SYSTEM.md. -->
        <p v-if="imageBlockReason" class="mt-1.5 text-tiny text-cream-400">↳ {{ imageBlockReason }}</p>
      </div>

      <div class="border-t border-ink-700 px-4 py-3">
        <form class="flex items-end gap-2" @submit.prevent="ask()">
          <label for="chat-modal-input" class="sr-only">Câu hỏi cho trợ lý thiết kế</label>
          <!-- Ô nhập là <textarea> MỘT DÒNG chứ không phải <input>: quy ước «Shift+Enter xuống dòng» chỉ
               có nghĩa với textarea — hứa một phím tắt rồi không làm được là nói dối ngay trên giao diện. -->
          <textarea id="chat-modal-input" ref="inputEl" v-model="question" rows="1" :maxlength="MAX_TURN_CHARS"
                    class="input max-h-[28vh] min-w-0 flex-1 resize-none overflow-y-auto !rounded-full !py-2.5 !text-body"
                    placeholder="Hỏi: chất liệu nào đang lên? màu nào hợp bộ Thu Đông?"
                    @keydown="onKeydown"></textarea>

          <!-- XUỐNG DÒNG (yêu cầu chủ dự án 2026-09-26): trên ĐIỆN THOẠI Enter là GỬI nên không có
               cách nào xuống dòng giữa câu. Cùng icon cornerDownLeft và cùng lối xử lý con trỏ với nút
               của bước «Hỏi đáp» (components/agents/AgentChatStep.vue).
               type="button" là BẮT BUỘC: nút nằm trong <form>, thiếu type thì trình duyệt coi nó là nút
               GỬI và mỗi cú bấm xuống dòng lại gửi luôn câu đang gõ dở. -->
          <button type="button" data-chat-newline
                  class="icon-btn !h-10 !w-10 shrink-0"
                  title="Xuống dòng (thêm dòng mới)" aria-label="Xuống dòng" @click="insertNewline">
            <StudioIcon name="cornerDownLeft" size="h-4 w-4" />
          </button>

          <!-- Đang trả lời thì nút chính là DỪNG: người dùng phải luôn có đường thoát khỏi lượt đang chạy. -->
          <button v-if="streaming" type="button" data-chat-stop
                  class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-ink-700 text-cream-100 transition-colors hover:bg-danger hover:text-cream-50"
                  title="Dừng lượt trả lời — phần chữ đã nhận được giữ lại" aria-label="Dừng trả lời" @click="stopAsking">
            <StudioIcon name="ban" size="h-4 w-4" />
          </button>
          <button v-else type="submit" data-chat-send
                  class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-600 text-primary-content transition-colors hover:bg-brand-500 disabled:opacity-40"
                  :disabled="!canAsk" title="Gửi câu hỏi (Enter)" aria-label="Gửi câu hỏi">
            <StudioIcon name="cornerDownLeft" size="h-4 w-4" />
          </button>
        </form>
        <p v-if="blockReason" class="mt-1.5 text-tiny text-cream-400">↳ {{ blockReason }}</p>
        <p v-if="error" class="mt-1.5 text-tiny text-warn">↳ {{ error }}</p>
      </div>
    </div>
  </BaseModal>
</template>
