<script setup>
/**
 * BƯỚC 5 — HỎI ĐÁP THEO LUỒNG (2026-09-26).
 *
 * Vì sao có bước này: bốn bước trên SẢN XUẤT (ra bộ sưu tập → ra ảnh), nhưng câu hỏi thật của chủ shop
 * ("vải này có co không", "mùa này khách chuộng màu gì") không có chỗ nào để hỏi. Họ phải mở tab khác
 * tra rồi tự ghép câu trả lời với dữ liệu của mình — mất đúng lúc cần quyết.
 *
 * Ba thứ khung này CỐ Ý làm theo một cách:
 *   · CHỮ CHẢY TỪNG MẢNH — nối đúng thứ tự máy chủ gửi, và khi máy chủ báo lượt đó không chảy dần thì
 *     NÓI THẬT thay vì để người dùng tưởng mọi lượt đều hiện dần;
 *   · CHỮ CỦA TRỢ LÝ HIỆN DƯỚI DẠNG VĂN BẢN ĐÃ TRANG TRÍ, không phải markdown thô — đi qua
 *     components/ChatMessageText.vue + chatFormat.js, ĐÚNG bộ mà modal trợ lý ở /studio đang dùng
 *     (một cách hiển thị, hai khung dùng: hai bản sao là hai chỗ để lệch nhau);
 *   · SỐ ĐO LẤY TỪ MÁY CHỦ (thời gian · số nguồn). Khung này không tự bấm giờ, không tự đếm nguồn.
 *
 * [2026-09-26 · ĐỔI CHÍNH SÁCH — KHỐI "NGUỒN ĐỂ BẠN TỰ KIỂM" ĐÃ GỠ HẲN KHỎI DOM]
 * Yêu cầu chủ dự án: khối nguồn làm rối khung chat ⇒ ẩn VĨNH VIỄN khỏi người dùng. Cách làm: XOÁ HẲN
 * khối khỏi template — KHÔNG ẩn bằng CSS và KHÔNG để lại nút mở lại, vì một khối còn trong DOM thì
 * trình đọc màn hình vẫn đọc, Ctrl+F vẫn tìm thấy, và nó tự quay lại khi ai đó gỡ một class.
 * DỮ LIỆU KHÔNG BỊ XOÁ: row.citations vẫn nguyên trong kho dữ liệu dùng chung
 * (store/actions/agentChat.js) — chỉ không hiển thị. Muốn trả lại thì phải là hành động NGƯỜI DÙNG
 * CHỦ ĐỘNG (bấm mới hiện), KHÔNG tự hiện như trước.
 * Cùng đợt, khung này nhận: nút COPY cho TỪNG tin nhắn (cả hai vai) và nút XUỐNG DÒNG trong ô hỏi.
 *
 * KHÔNG hiển thị tên nhà cung cấp / tên model ở bất kỳ đâu (§6.1 luật 2): sự kiện `provider` của luồng
 * bị kho dữ liệu BỎ HẲN, không đi vào state hiển thị (xem store/actions/agentChat.js).
 *
 * Dùng <LoadingSpinner> dùng chung cho chỉ báo đang trả lời — không tự vẽ bộ chấm (§3, quy ước
 * SuggestCard.vue).
 */
import { computed, inject, nextTick, onMounted, ref, watch } from 'vue';
import { useStudioStore } from '../../store.js';
// Chữ của trợ lý: ĐỊNH DẠNG ở chatFormat.js (module thuần), HIỆN ở ChatMessageText.vue — CÙNG hai file
// mà modal trợ lý ở /studio dùng, không có bản thứ hai (xem khối chú thích đầu file).
import { assistantPlainText } from '../../chatFormat.js';
// Copy vào bộ nhớ tạm: MỘT bản dùng chung (clipboard + đường dự phòng cho trình duyệt chặn clipboard).
import { copyPlainText } from '../../chatCopy.js';
import ChatMessageText from '../ChatMessageText.vue';
import StudioIcon from '../StudioIcon.vue';
import LoadingSpinner from '../LoadingSpinner.vue';

// Cần store để hiện toast xác nhận copy — cùng lối các bước khác của Agent Studio
// (AgentDnaStep · AgentRadarStep… đều useStudioStore()), và toast là CỬA CHẶN câu chữ ở biên (§6).
const store = useStudioStore();

const chatText = inject('chatText');
const chatMessages = inject('chatMessages');
const chatStreaming = inject('chatStreaming');
const chatPhaseLabel = inject('chatPhaseLabel');
const chatToolLine = inject('chatToolLine');
const chatError = inject('chatError');
const chatNotes = inject('chatNotes');
const chatMetaLine = inject('chatMetaLine');
const CHAT_SUGGESTIONS = inject('CHAT_SUGGESTIONS');
const askChat = inject('askChat');
const stopChat = inject('stopChat');
const resetChat = inject('resetChat');
const useChatSuggestion = inject('useChatSuggestion');
const regionName = inject('regionName');

const thread = ref(null);
/** Ô hỏi — cần THAM CHIẾU tới phần tử để chèn dấu xuống dòng đúng vị trí con trỏ (insertNewline). */
const inputEl = ref(null);

/**
 * Nút «Hỏi» chỉ bật khi có chữ VÀ không đang chảy — nút bị khoá thì ngay dưới phải có dòng ↳ nói vì sao
 * (§4 luật 4), nên lý do nằm ở một computed chứ không rải trong template.
 */
const canAsk = computed(() => !chatStreaming.value && String(chatText.value || '').trim() !== '');
const blockReason = computed(() => {
  if (chatStreaming.value) return '';
  return String(chatText.value || '').trim() === ''
    ? 'Nhập câu hỏi ở ô trên rồi bấm «Hỏi» — hoặc chọn một gợi ý ở trên.'
    : '';
});

function scrollToEnd() {
  const el = thread.value;
  if (el) el.scrollTop = el.scrollHeight;
}

/**
 * Tự cuộn xuống cuối. Theo dõi ĐỘ DÀI của lượt cuối, không chỉ số lượng tin nhắn: chữ chảy từng mảnh nên
 * chỉ đếm số tin là câu trả lời dài sẽ chảy xuống dưới tầm nhìn mà màn hình đứng im.
 */
watch(
  () => {
    const list = chatMessages.value;
    const last = list[list.length - 1];
    return list.length + ':' + String(last?.text || '').length + ':' + (last?.citations?.length || 0);
  },
  () => nextTick(scrollToEnd),
);
// Vào lại bước này (đổi bước rồi quay lại) là thấy ĐÚNG chỗ đang đọc, không phải đầu cuộc trò chuyện.
onMounted(() => nextTick(scrollToEnd));

async function send() {
  const sent = await askChat();
  if (sent) nextTick(scrollToEnd);
}

function onKeydown(event) {
  // Enter GỬI · Shift+Enter XUỐNG DÒNG — quy ước của mọi ô chat.
  // isComposing PHẢI được tôn trọng: bộ gõ tiếng Việt dùng Enter để CHỐT DẤU, chặn nó là gõ dấu là gửi.
  if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
  event.preventDefault();
  send();
}

/**
 * COPY MỘT TIN NHẮN (yêu cầu chủ dự án 2026-09-26) — nút Copy có ở TỪNG tin, cả hai vai.
 *
 * Hai vai copy HAI KIỂU chữ: câu hỏi của người dùng chép NGUYÊN VĂN (chữ của chính họ); câu trả lời
 * của trợ lý chép bản CHỮ SẠCH (assistantPlainText) — người dùng dán vào tài liệu · email, dán kèm
 * dấu sao và backtick là mang ký tự của máy sang chỗ khác.
 *
 * Thất bại phải thành CÂU CHỮ cho người dùng đọc (trình duyệt chặn clipboard khi trang không phải
 * HTTPS hoặc khi cú bấm không phải thao tác trực tiếp) — không ném ra console.
 */
async function copyRow(row) {
  const isUser = row.role === 'user';
  const text = isUser ? String(row.text || '') : assistantPlainText(row.text);
  const ok = await copyPlainText(text);
  if (ok) {
    store.toast(isUser ? 'Đã copy câu hỏi của bạn vào bộ nhớ tạm.' : 'Đã copy câu trả lời vào bộ nhớ tạm.');
    return;
  }
  store.toast('Trình duyệt chặn việc copy — bạn bôi đen chữ rồi copy tay giúp.', 'error');
}

/** Nhãn nút Copy nói rõ COPY CÁI GÌ — khung có hai loại tin nhắn nên "Copy" trống là nhập nhằng. */
function copyTitle(row) {
  return row.role === 'user' ? 'Copy câu hỏi này' : 'Copy câu trả lời này';
}

/**
 * Thêm một dấu xuống dòng tại ĐÚNG VỊ TRÍ CON TRỎ (yêu cầu chủ dự án 2026-09-26).
 *
 * Vì sao cần: trên ĐIỆN THOẠI, Enter là GỬI (không có phím Shift), nên người đang gõ giữa câu không có
 * cách nào xuống dòng. Cách làm chép ĐÚNG nút data-prompt-newline của ô mô tả tạo ảnh
 * (components/CanvasEmptyState.vue): cùng icon cornerDownLeft, cùng lối xử lý con trỏ — hai ô nhập
 * trong cùng một sản phẩm không được hành xử khác nhau.
 * Con trỏ đặt LẠI ngay SAU ký tự vừa chèn (start + 1), không nhảy về cuối: người đang sửa giữa câu mà
 * bị đẩy về cuối là mất chỗ đang gõ.
 */
function insertNewline() {
  const el = inputEl.value;
  if (! el) return;
  const start = el.selectionStart ?? String(chatText.value || '').length;
  const end = el.selectionEnd ?? start;
  const value = String(chatText.value || '');
  chatText.value = value.slice(0, start) + '\n' + value.slice(end);
  nextTick(() => {
    el.selectionStart = el.selectionEnd = start + 1;
    el.focus();
  });
}
</script>

<template>
  <section id="agent-step-chat" class="space-y-3" role="region" aria-label="Hỏi đáp">
    <div class="card p-3 sm:p-4">
      <!-- ── 5.1 ĐẦU KHUNG: việc này để làm gì, hỏi trong phạm vi nào ── -->
      <div class="flex items-center gap-2 pb-3">
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-600/15 text-brand-300">
          <StudioIcon name="bot" size="h-4 w-4" />
        </span>
        <p class="min-w-0 flex-1">
          <span class="block text-body font-semibold text-cream-100">Hỏi về bộ sưu tập của bạn</span>
          <!-- [2026-09-26] Câu phụ TRƯỚC ĐÂY hứa "câu trả lời kèm nguồn để bạn tự kiểm" — khối nguồn đã
               gỡ hẳn khỏi giao diện theo yêu cầu chủ dự án, giữ nguyên lời hứa đó là nói sai với người dùng. -->
          <span class="block truncate text-tiny text-cream-400">{{ regionName }} · câu trả lời dựa trên dữ liệu shop và thông tin trợ lý tự tra</span>
        </p>
        <button
          v-if="chatMessages.length"
          type="button"
          class="btn-ghost btn-sm state-layer shrink-0"
          title="Bắt đầu hội thoại mới (không ảnh hưởng dữ liệu shop của bạn)"
          @click="resetChat"
        >
          <StudioIcon name="refresh" size="h-3.5 w-3.5" /><span class="hidden sm:inline"> Hội thoại mới</span>
        </button>
      </div>

      <!-- ── 5.2 TRẠNG THÁI RỖNG: ba câu gợi ý BẤM ĐƯỢC, mỗi câu một việc khác nhau ── -->
      <div v-if="!chatMessages.length" class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-5 text-center sm:p-7">
        <StudioIcon name="bot" size="h-8 w-8" class="mx-auto text-brand-300" />
        <p class="mt-3 text-sm font-semibold text-cream-100">Hỏi thẳng về bộ sưu tập bạn vừa dựng</p>
        <p class="mx-auto mt-1 max-w-xl text-xs leading-5 text-cream-400">
          Câu trả lời hiện dần theo từng mảnh, và COPY được từng câu để dán sang tài liệu · email · ô mô tả ảnh.
          Hỏi được cả thứ nằm ngoài dữ liệu shop: chất liệu, hướng màu đang lên, nhịp ra hàng của xưởng.
        </p>
        <div class="mx-auto mt-4 flex max-w-2xl flex-wrap items-center justify-center gap-1.5">
          <button
            v-for="item in CHAT_SUGGESTIONS"
            :key="item.text"
            type="button"
            class="tool-btn state-layer !px-2.5 !py-2 text-left !text-label normal-case"
            :title="item.why"
            @click="useChatSuggestion(item.text)"
          >
            {{ item.text }}
          </button>
        </div>
      </div>

      <!-- ── 5.3 HỘI THOẠI: người dùng bên PHẢI, trả lời bên TRÁI ── -->
      <div
        v-else
        ref="thread"
        role="log"
        aria-label="Nội dung hỏi đáp"
        class="max-h-[52vh] space-y-3 overflow-y-auto pr-1"
      >
        <div v-for="(row, index) in chatMessages" :key="index" class="flex" :class="row.role === 'user' ? 'justify-end' : 'justify-start'">
          <div
            class="min-w-0 max-w-[46rem] rounded-xl px-3 py-2"
            :class="row.role === 'user' ? 'bg-brand-600/20 state-layer' : 'bg-ink-800'"
          >
            <!-- CHỮ CỦA TRỢ LÝ: đi qua ChatMessageText dùng chung ⇒ đậm · nghiêng · mã · link là thẻ
                 THẬT, người dùng KHÔNG còn nhìn thấy dấu sao, dấu gạch đầu dòng hay [chữ](địa chỉ).
                 Chữ của NGƯỜI DÙNG vẫn hiện NGUYÊN VĂN (whitespace-pre-wrap) — đó là chữ họ tự gõ. -->
            <ChatMessageText v-if="row.role !== 'user' && row.text" :text="row.text" />
            <p v-else-if="row.text" class="whitespace-pre-wrap break-words text-body leading-5 text-cream-100">{{ row.text }}</p>
            <!-- Dòng trạng thái của CHÍNH LƯỢT ĐÓ: đang soạn · bạn đã dừng · chưa trả lời được.
                 Ghi chú của lượt bị dừng nằm ở ĐÂY chứ không nhét vào chữ, vì chữ đó còn được gửi lại
                 làm lịch sử ở câu hỏi sau. -->
            <p v-if="row.streaming && !row.text" class="text-label text-cream-400">Đang soạn câu trả lời…</p>
            <p v-else-if="row.stopped" class="mt-1 text-tiny text-cream-400">Bạn đã dừng lượt này.</p>
            <p v-else-if="row.failed" class="mt-1 text-tiny text-warn">Lượt này chưa trả lời được — bạn hỏi lại giúp.</p>

            <!-- [2026-09-26 · GỠ HẲN KHỐI "NGUỒN ĐỂ BẠN TỰ KIỂM" — ĐỌC TRƯỚC KHI ĐỊNH THÊM LẠI]
                 Chủ dự án yêu cầu ẩn VĨNH VIỄN khối này khỏi người dùng vì nó làm rối khung chat.
                 Cách làm: XOÁ HẲN khối khỏi template (cả tiêu đề lẫn danh sách trích dẫn) — KHÔNG ẩn
                 bằng CSS, KHÔNG để lại nút mở lại. Lý do phải xoá hẳn: khối còn trong DOM thì trình đọc
                 màn hình vẫn đọc, Ctrl+F vẫn tìm thấy, và nó quay lại nguyên trạng khi ai đó gỡ một class.
                 DỮ LIỆU KHÔNG BỊ XOÁ: row.citations vẫn nguyên trong kho dữ liệu dùng chung
                 (store/actions/agentChat.js) — chỉ không hiển thị. Nếu sau này cần trả lại thì phải là
                 hành động NGƯỜI DÙNG CHỦ ĐỘNG (bấm mới hiện), KHÔNG tự hiện như trước. -->

            <!-- COPY: có ở TỪNG tin, cả hai vai; tin của trợ lý copy bản CHỮ SẠCH (copyRow). -->
            <button
              v-if="row.text"
              type="button"
              data-chat-copy
              class="tool-btn state-layer mt-1.5 !px-2 !py-1 !text-tiny"
              :title="copyTitle(row)"
              :aria-label="copyTitle(row)"
              @click="copyRow(row)"
            >
              <StudioIcon name="copy" size="h-3 w-3" /> Copy
            </button>
          </div>
        </div>
      </div>

      <!-- ── 5.4 TIẾN TRÌNH THẬT: nhãn giai đoạn + dòng đang tra/đang đọc (cả hai do máy chủ gửi) ── -->
      <div v-if="chatStreaming" class="mt-3 flex items-center gap-3 rounded-lg bg-ink-900 px-2.5 py-2">
        <LoadingSpinner size="sm" :text="chatPhaseLabel || 'Đang trả lời…'" :subtext="chatToolLine" />
      </div>

      <!-- ── 5.5 SỐ ĐO + CẢNH BÁO của lượt VỪA RỒI (số của máy chủ; cảnh báo chỉ khi có chuyện thật) ── -->
      <div v-if="!chatStreaming && (chatMetaLine || chatNotes.length)" class="mt-3 space-y-1 rounded-lg bg-ink-900 px-2.5 py-2">
        <p v-if="chatMetaLine" class="text-tiny text-cream-400">{{ chatMetaLine }}</p>
        <p v-for="note in chatNotes" :key="note" class="flex items-start gap-1.5 text-tiny text-warn">
          <StudioIcon name="info" size="h-3.5 w-3.5" class="mt-0.5 shrink-0" />{{ note }}
        </p>
      </div>

      <p v-if="chatError" class="mt-3 rounded-lg bg-danger/10 px-2.5 py-2 text-label text-danger">{{ chatError }}</p>

      <!-- ── 5.6 Ô HỎI ── -->
      <div class="mt-3">
        <label for="agent-chat-input" class="sr-only">Câu hỏi của bạn</label>
        <textarea
          id="agent-chat-input"
          ref="inputEl"
          v-model="chatText"
          rows="3"
          :maxlength="4000"
          :disabled="chatStreaming"
          class="input w-full resize-y !text-body"
          placeholder="Hỏi về chất liệu, hướng màu, nhịp ra hàng, giá bán…"
          aria-describedby="agent-chat-help"
          @keydown="onKeydown"
        ></textarea>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <p id="agent-chat-help" class="text-tiny text-cream-400">Enter để gửi · Shift+Enter để xuống dòng</p>
          <div class="ml-auto flex items-center gap-1.5">
            <!-- XUỐNG DÒNG (yêu cầu chủ dự án 2026-09-26): trên ĐIỆN THOẠI Enter là GỬI nên không có
                 cách nào xuống dòng giữa câu. Cùng icon cornerDownLeft và cùng lối xử lý con trỏ với
                 nút data-prompt-newline của ô mô tả tạo ảnh (components/CanvasEmptyState.vue).
                 type="button" để nút KHÔNG bị hiểu là nút gửi của khung. -->
            <button
              type="button"
              data-chat-newline
              class="btn-ghost btn-sm state-layer"
              title="Xuống dòng (thêm dòng mới)"
              aria-label="Xuống dòng"
              @click="insertNewline"
            >
              <StudioIcon name="cornerDownLeft" size="h-3.5 w-3.5" /><span class="hidden sm:inline"> Xuống dòng</span>
            </button>
            <button
              v-if="chatStreaming"
              type="button"
              class="btn-ghost btn-sm state-layer"
              title="Dừng lượt trả lời — phần chữ đã nhận được giữ lại"
              @click="stopChat"
            >
              <StudioIcon name="ban" size="h-3.5 w-3.5" /> Dừng
            </button>
            <button
              v-else
              v-ripple
              type="button"
              class="btn-brand state-layer !px-4 !py-2 text-sm"
              :disabled="!canAsk"
              title="Gửi câu hỏi (Enter)"
              @click="send"
            >
              <StudioIcon name="cornerDownLeft" size="h-4 w-4" /> Hỏi
            </button>
          </div>
        </div>
        <p v-if="blockReason" class="mt-1 text-tiny text-cream-400">↳ {{ blockReason }}</p>
      </div>
    </div>
  </section>
</template>
