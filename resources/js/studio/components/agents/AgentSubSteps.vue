<script setup>
/**
 * DẢI BƯỚC CON — "đang ở việc thứ mấy trong mấy việc" (2026-09-25).
 *
 * Vì sao cần: mỗi bước chính nay gồm nhiều việc nhỏ (Định hướng có 7). Trên điện thoại, một màn hình
 * dài ba bốn cuộn thì người dùng không biết mình đang ở đâu và còn phải làm gì; bước con trả lời đúng
 * hai câu đó, và mỗi màn chỉ còn MỘT việc.
 *
 * Hình dạng theo bề ngang — cùng dữ liệu, khác cách bày:
 *   · màn hẹp: tiêu đề + "3/7" + chấm tiến trình (bấm được để nhảy) — không cuộn ngang;
 *   · màn rộng: dải chip đầy đủ, thấy hết đường đi trong một cái liếc.
 */
import { computed, inject } from 'vue';
import StudioIcon from '../StudioIcon.vue';

const props = defineProps({ stepId: { type: String, required: true } });

const SUBSTEPS = inject('SUBSTEPS');
const sub = inject('sub');
const setSub = inject('setSub');

const list = computed(() => SUBSTEPS[props.stepId] || []);
const index = computed(() => Math.max(0, list.value.findIndex((row) => row.id === sub.value)));
const current = computed(() => list.value[index.value] || null);
</script>

<template>
  <div class="mb-5">
    <!-- Màn rộng: thấy hết đường đi -->
    <div class="hidden flex-wrap items-center gap-1.5 lg:flex" role="tablist" aria-label="Các việc trong bước này">
      <button
        v-for="(item, i) in list"
        :key="item.id"
        type="button"
        role="tab"
        class="tool-btn state-layer"
        :class="{ 'is-active': item.id === sub }"
        :aria-selected="item.id === sub"
        :title="item.hint"
        @click="setSub(item.id)"
      >
        <span class="text-tiny font-bold opacity-70">{{ i + 1 }}</span>
        {{ item.label }}
      </button>
    </div>

    <!-- Màn hẹp: một việc, một dòng, kèm chấm tiến trình bấm được -->
    <div class="lg:hidden">
      <div class="flex items-center gap-2">
        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-600 text-tiny font-bold text-primary-content">{{ index + 1 }}</span>
        <p class="min-w-0 flex-1">
          <span class="block truncate text-body font-semibold text-cream-100">{{ current?.label }}</span>
          <span class="block truncate text-tiny text-cream-400">Việc {{ index + 1 }}/{{ list.length }} · {{ current?.hint }}</span>
        </p>
        <button
          type="button"
          class="tool-btn !px-2 !py-1.5"
          :disabled="index === 0"
          aria-label="Việc trước"
          @click="setSub(list[index - 1].id)"
        >
          <StudioIcon name="chevronLeft" size="h-3.5 w-3.5" />
        </button>
        <button
          type="button"
          class="tool-btn !px-2 !py-1.5"
          :disabled="index >= list.length - 1"
          aria-label="Việc kế tiếp"
          @click="setSub(list[index + 1].id)"
        >
          <StudioIcon name="chevronRight" size="h-3.5 w-3.5" />
        </button>
      </div>
      <!-- Chấm tiến trình: vừa là chỉ báo, vừa là lối nhảy thẳng (vùng chạm 24px) -->
      <div class="mt-2 flex items-center gap-1">
        <button
          v-for="(item, i) in list"
          :key="item.id"
          type="button"
          class="grid h-6 flex-1 place-items-center"
          :aria-label="'Tới việc ' + (i + 1) + ': ' + item.label"
          :aria-current="item.id === sub ? 'step' : undefined"
          @click="setSub(item.id)"
        >
          <span class="h-1 w-full rounded-full" :class="i <= index ? 'bg-brand-500' : 'bg-ink-700'"></span>
        </button>
      </div>
    </div>
  </div>
</template>
