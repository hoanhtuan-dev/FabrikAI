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
 *   · NGUỒN LÀ LINK THẬT (target="_blank" + rel="noopener"): cả bước này tồn tại để người dùng KIỂM
 *     CHỨNG, mà một trích dẫn không bấm được thì không kiểm chứng được gì;
 *   · SỐ ĐO LẤY TỪ MÁY CHỦ (thời gian · số nguồn). Khung này không tự bấm giờ, không tự đếm nguồn.
 *
 * KHÔNG hiển thị tên nhà cung cấp / tên model ở bất kỳ đâu (§6.1 luật 2): sự kiện `provider` của luồng
 * bị kho dữ liệu BỎ HẲN, không đi vào state hiển thị (xem store/actions/agentChat.js).
 *
 * Dùng <LoadingSpinner> dùng chung cho chỉ báo đang trả lời — không tự vẽ bộ chấm (§3, quy ước
 * SuggestCard.vue).
 */
import { computed, inject, nextTick, onMounted, ref, watch } from 'vue';
import StudioIcon from '../StudioIcon.vue';
import LoadingSpinner from '../LoadingSpinner.vue';

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
          <span class="block truncate text-tiny text-cream-400">{{ regionName }} · câu trả lời kèm nguồn để bạn tự kiểm</span>
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
          Câu trả lời hiện dần theo từng mảnh và đi kèm danh sách nguồn bấm được — bạn tự kiểm chứng thay vì tin suông.
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
            <p v-if="row.text" class="whitespace-pre-wrap break-words text-body leading-5 text-cream-100">{{ row.text }}</p>
            <!-- Dòng trạng thái của CHÍNH LƯỢT ĐÓ: đang soạn · bạn đã dừng · chưa trả lời được.
                 Ghi chú của lượt bị dừng nằm ở ĐÂY chứ không nhét vào chữ, vì chữ đó còn được gửi lại
                 làm lịch sử ở câu hỏi sau. -->
            <p v-if="row.streaming && !row.text" class="text-label text-cream-400">Đang soạn câu trả lời…</p>
            <p v-else-if="row.stopped" class="mt-1 text-tiny text-cream-400">Bạn đã dừng lượt này.</p>
            <p v-else-if="row.failed" class="mt-1 text-tiny text-warn">Lượt này chưa trả lời được — bạn hỏi lại giúp.</p>

            <!-- NGUỒN: link THẬT, mở tab mới, rel="noopener" (không cho trang nguồn nắm cửa sổ của mình). -->
            <div v-if="row.citations && row.citations.length" class="mt-2 space-y-1.5 border-t border-ink-700 pt-2">
              <p class="text-tiny font-semibold uppercase tracking-[0.14em] text-cream-400">Nguồn để bạn tự kiểm</p>
              <ul class="space-y-1.5">
                <li v-for="source in row.citations" :key="source.ref || source.url">
                  <a
                    :href="source.url"
                    target="_blank"
                    rel="noopener"
                    class="state-layer flex items-start gap-1.5 rounded-lg bg-ink-900 px-2.5 py-2 text-label text-brand-200"
                    :title="source.url"
                  >
                    <StudioIcon name="link" size="h-3.5 w-3.5" class="mt-0.5 shrink-0" />
                    <span class="min-w-0">
                      <span class="block truncate font-semibold">{{ source.title || source.source_name || 'Nguồn tham khảo' }}</span>
                      <span class="block truncate text-tiny text-cream-400">
                        <span v-if="source.source_name">{{ source.source_name }}</span>
                        <span v-if="source.published_at">{{ source.source_name ? ' · ' : '' }}{{ source.published_at }}</span>
                        <span v-if="source.reused">{{ (source.source_name || source.published_at) ? ' · ' : '' }}nguồn đã tra trước đó</span>
                      </span>
                    </span>
                  </a>
                </li>
              </ul>
            </div>
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
