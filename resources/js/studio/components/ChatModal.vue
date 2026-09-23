<script setup>
/**
 * MODAL TRỢ LÝ THIẾT KẾ — chat hỏi đáp BẤT KỲ LÚC NÀO trong /studio (2026-09-26 · đợt 37).
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
 * đó là đường dành cho bàn phím. Canvas trống chỉ còn đúng việc tạo ảnh.
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
 * TÊN NHÀ CUNG CẤP / TÊN MODEL KHÔNG ĐƯỢC XUẤT HIỆN Ở BẤT KỲ ĐÂU (§6.1 luật 2): sự kiện provider của
 * luồng bị kho dữ liệu BỎ HẲN nên nó không chảy vào state hiển thị nào.
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
 * không có cách nào xuống dòng. Cách làm ở đây chép ĐÚNG nút data-prompt-newline của ô mô tả tạo ảnh
 * (components/CanvasEmptyState.vue — insertNewline): cùng icon cornerDownLeft, cùng lối xử lý con trỏ.
 * Lý do phải giống nhau: hai ô nhập trong CÙNG một sản phẩm mà hành xử khác nhau thì người dùng học
 * một lần rồi bấm sai ở ô kia.
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

/**
 * CẦU NỐI "tìm hiểu → làm": đưa CÂU TRẢ LỜI của trợ lý vào ô mô tả tạo ảnh của Studio (store.imagePromptEn
 * — cũng chính là trường ô mô tả ở canvas trống bind vào).
 *
 * Vì sao NỐI THÊM chứ không ghi đè: mô tả đang gõ là việc của người dùng, đè lên là xoá mất nó. Vì sao
 * ĐÓNG modal sau khi đưa: ô mô tả nằm ở canvas — để modal che lên thì cú bấm không có phản hồi nào nhìn
 * thấy được; đóng lại + một dòng xác nhận là cách nói thật rằng việc đó đã xảy ra.
 */
function useAnswer(text) {
  // CHỮ SẠCH, không phải nguyên văn: câu trả lời đi thẳng vào ô mô tả tạo ảnh, mà ký tự định dạng
  // (** · backtick) trong một câu lệnh tạo ảnh là rác — máy tạo ảnh đọc nó như chữ thật.
  const line = assistantPlainText(text).trim();
  if (! line) return;
  store.imagePromptEn = (store.imagePromptEn ? store.imagePromptEn + ' ' : '') + line;
  store.chatOpen = false;
  store.toast('Đã đưa câu trả lời vào ô mô tả ảnh — bấm «Tạo ảnh» khi bạn đã sửa lại cho vừa ý.', 'success');
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
        <!-- TRẠNG THÁI RỖNG: nói thật khung này làm được gì + ba câu gợi ý BẤM ĐƯỢC (mỗi câu một việc). -->
        <div v-if="!messages.length" class="py-6">
          <p class="text-sm font-semibold text-cream-100">Hỏi thẳng về bộ sưu tập bạn đang làm</p>
          <p class="mt-1 max-w-xl text-body leading-relaxed text-cream-300">
            Trợ lý đọc hồ sơ thương hiệu và quy tắc làm việc bạn đã khai, tự tra internet khi cần dữ kiện,
            rồi trả lời. Chưa có dữ liệu thì nói thẳng là chưa có — không bịa.
          </p>
          <div class="mt-3 flex flex-wrap gap-1.5">
            <button v-for="item in CHAT_SUGGESTIONS" :key="item.text" type="button" data-chat-suggestion
                    class="rounded-full border border-ink-600 bg-ink-800 px-3 py-1.5 text-label font-semibold text-cream-200 transition-colors hover:border-brand-400 hover:text-cream-50"
                    :title="item.why" @click="ask(item.text)">{{ item.text }}</button>
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

                  <!-- CẦU NỐI "tìm hiểu → làm": câu trả lời đi thẳng vào ô mô tả tạo ảnh của Studio. -->
                  <button v-if="! m.streaming" type="button" class="tool-btn !px-2 !py-1 !text-tiny" data-use-answer
                          title="Đưa câu trả lời này vào ô mô tả tạo ảnh" @click="useAnswer(m.text)">
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
      </div>

      <!-- SỐ ĐO + cảnh báo của lượt vừa rồi: nguyên văn từ máy chủ, không tự bấm giờ ở trình duyệt.
           Đặt NGAY TRÊN thanh soạn tin (không nằm trong vùng cuộn) để câu cảnh báo không trôi mất. -->
      <div v-if="!streaming && (metaLine || notes.length)" class="space-y-1 px-4 pb-1.5">
        <p v-if="metaLine" class="text-tiny text-cream-400">{{ metaLine }}</p>
        <p v-for="note in notes" :key="note" class="text-tiny text-warn">↳ {{ note }}</p>
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
               data-prompt-newline của ô mô tả tạo ảnh (components/CanvasEmptyState.vue).
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
