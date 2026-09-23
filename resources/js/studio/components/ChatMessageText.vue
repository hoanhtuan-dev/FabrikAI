<script setup>
/**
 * VĂN BẢN CỦA TRỢ LÝ — hiện dưới dạng VĂN BẢN ĐÃ TRANG TRÍ, KHÔNG hiện markdown thô (2026-09-26).
 *
 * [VÌ SAO CÓ COMPONENT NÀY]
 * Hai khung chat cùng đọc MỘT hội thoại (components/ChatModal.vue ở /studio và
 * components/agents/AgentChatStep.vue của Agent Studio) nên chúng phải hiện câu trả lời GIỐNG HỆT
 * nhau. Trước đây mỗi khung tự in `m.text` nên người dùng đọc thấy ký tự định dạng của máy:
 * `**đậm**` · `- gạch đầu dòng` · `[chữ](url)`. Nay cả hai khung đưa chữ của trợ lý qua ĐÚNG
 * component này — một cách hiển thị, hai nơi dùng.
 *
 * [VÌ SAO KHÔNG CHÈN HTML THÔ Ở ĐÂY]
 * Chữ trong khung chat là VĂN BẢN DO MÁY TRẢ LỜI — không phải mã của mình. Cách duy nhất để nó không
 * bao giờ trở thành mã chạy được là render bằng THẺ THẬT qua vòng lặp, để Vue tự escape mọi giá trị
 * nội suy. Repo có rào chắn riêng cho việc này (tests/Feature/StudioXssSinksTest.php) và rào chắn đó
 * phải giữ nguyên: ở đây KHÔNG có sink HTML thô, không có thư viện markdown, không có chuỗi HTML nào
 * được dựng rồi chèn vào trang.
 *
 * Dữ liệu vào component là mảng KHỐI của chatFormat.js (module thuần, tự kiểm bằng Node qua
 * scripts/check-chat-format.mjs): đoạn · gạch đầu dòng · tiêu đề, mỗi khối là các MẢNH chữ có thuộc
 * tính (đậm · nghiêng · mã · địa chỉ). Component KHÔNG tự phân tích chuỗi — nhờ vậy bộ nhận dạng chỉ
 * có MỘT bản và kiểm được bằng máy.
 */
import { computed } from 'vue';
import { formatAssistantText } from '../chatFormat.js';

const props = defineProps({
  /** Chữ NGUYÊN VĂN của trợ lý (state m.citations vẫn giữ nguyên ở kho dữ liệu — xem ghi chú ở khung chat). */
  text: { type: String, default: '' },
});

const blocks = computed(() => formatAssistantText(props.text));

/** Cỡ chữ theo cấp tiêu đề — dùng token sẵn có (KHÔNG viết số px, xem docs/DESIGN_SYSTEM.md §5). */
const HEADING_CLASS = {
  1: 'text-base font-semibold text-cream-50',
  2: 'text-sm font-semibold text-cream-50',
};

function headingClass(level) {
  return HEADING_CLASS[Number(level)] || 'text-body font-semibold text-cream-100';
}

/**
 * Gộp các mục danh sách LIỀN NHAU thành MỘT danh sách: ba việc liên tiếp phải là MỘT <ul> ba dòng,
 * không phải ba <ul> một dòng — trình đọc màn hình đọc "danh sách 1 mục" ba lần và bản in ra bị lệch.
 * Mỗi nhóm mang sẵn TÊN THẺ của khung (ul · ol · div) để template chỉ còn MỘT vòng lặp.
 */
const groups = computed(() => {
  const out = [];

  for (const block of blocks.value) {
    const last = out[out.length - 1];

    if (block.kind === 'li') {
      const item = { tag: 'li', runs: block.runs, class: null };
      if (last && last.kind === 'list' && last.ordered === block.ordered) {
        last.items.push(item);
        continue;
      }
      out.push({
        kind: 'list',
        ordered: block.ordered,
        container: block.ordered ? 'ol' : 'ul',
        class: block.ordered ? 'ml-4 list-decimal space-y-0.5' : 'ml-4 list-disc space-y-0.5',
        items: [item],
      });
      continue;
    }

    // Tiêu đề và đoạn dùng CÙNG một khung <div> bọc một <p>: như vậy phần render mảnh chữ chỉ có MỘT
    // bản trong file này, không phải hai bản chép tay.
    out.push({
      kind: block.kind,
      ordered: false,
      container: 'div',
      class: null,
      items: [{
        tag: 'p',
        runs: block.runs,
        class: block.kind === 'h' ? headingClass(block.level) : null,
      }],
    });
  }

  return out;
});

/** Một MẢNH chữ → thẻ thật. Link LUÔN mở tab mới kèm rel="noopener" (trang nguồn không nắm cửa sổ của mình). */
function runTag(run) {
  if (run.href) return 'a';
  if (run.code) return 'code';
  if (run.bold) return 'strong';
  if (run.italic) return 'em';
  return 'span';
}
</script>

<template>
  <div class="space-y-1.5 text-body leading-relaxed text-cream-100">
    <component :is="group.container" v-for="(group, gi) in groups" :key="gi" :class="group.class">
      <!-- MỘT vòng lặp cho mọi mảnh chữ: thẻ do runTag() quyết định, và ở đây KHÔNG có chuỗi HTML nào
           được dựng rồi chèn vào trang — mọi giá trị đều đi qua nội suy của Vue (tự escape). -->
      <component :is="item.tag" v-for="(item, ii) in group.items" :key="ii" :class="item.class">
        <template v-for="(run, ri) in item.runs" :key="ri">
          <a v-if="run.href" :href="run.href" target="_blank" rel="noopener"
             class="break-words text-brand-200 underline decoration-dotted transition-colors hover:text-cream-50"
             :class="run.bold ? 'font-semibold' : ''">{{ run.text }}</a>
          <code v-else-if="run.code" class="rounded bg-ink-700/70 px-1 py-0.5 font-mono text-label text-cream-50">{{ run.text }}</code>
          <strong v-else-if="run.bold" class="font-semibold text-cream-50">{{ run.text }}</strong>
          <em v-else-if="run.italic" class="italic">{{ run.text }}</em>
          <span v-else>{{ run.text }}</span>
        </template>
      </component>
    </component>
  </div>
</template>
