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
  <div class="motion-fade-in mb-4" :key="stepId + '-' + sub">
    <!-- Màn rộng: pill tiến trình, thấy hết đường đi -->
    <div class="hidden flex-wrap items-center gap-1 lg:flex" role="tablist" aria-label="Các việc trong bước này">
      <button
        v-for="(item, i) in list"
        :key="item.id"
        type="button"
        role="tab"
        class="substep-chip state-layer"
        :class="{ 'is-active': item.id === sub, 'is-done': i < index }"
        :aria-selected="item.id === sub"
        :title="item.hint"
        @click="setSub(item.id)"
      >
        <span class="substep-chip__num">
          <StudioIcon v-if="i < index" name="check" size="h-3 w-3" />
          <span v-else>{{ i + 1 }}</span>
        </span>
        <span class="truncate">{{ item.label }}</span>
      </button>
    </div>

    <!-- Màn hẹp: một việc, một dòng, kèm chấm tiến trình bấm được -->
    <div class="lg:hidden">
      <div class="flex items-center gap-2">
        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-600 text-tiny font-bold text-primary-content">{{ index + 1 }}</span>
        <p class="min-w-0 flex-1">
          <span class="block truncate text-body font-semibold text-cream-100">{{ current?.label }}</span>
          <span class="block truncate text-tiny text-cream-400">Việc {{ index + 1 }}/{{ list.length }} · {{ current?.hint }}</span>
        </p>
        <button
          type="button"
          class="icon-btn h-7 w-7"
          :disabled="index === 0"
          aria-label="Việc trước"
          @click="setSub(list[index - 1].id)"
        >
          <StudioIcon name="chevronLeft" size="h-4 w-4" />
        </button>
        <button
          type="button"
          class="icon-btn h-7 w-7"
          :disabled="index >= list.length - 1"
          aria-label="Việc kế tiếp"
          @click="setSub(list[index + 1].id)"
        >
          <StudioIcon name="chevronRight" size="h-4 w-4" />
        </button>
      </div>
      <!-- Chấm tiến trình: vừa là chỉ báo, vừa là lối nhảy thẳng (vùng chạm 28px) -->
      <div class="mt-1.5 flex items-center gap-0.5">
        <button
          v-for="(item, i) in list"
          :key="item.id"
          type="button"
          class="grid h-7 flex-1 place-items-center"
          :aria-label="'Tới việc ' + (i + 1) + ': ' + item.label"
          :aria-current="item.id === sub ? 'step' : undefined"
          @click="setSub(item.id)"
        >
          <span class="h-1 w-full rounded-full transition-colors" :class="i < index ? 'bg-success' : (i === index ? 'bg-brand-500' : 'bg-ink-700')"></span>
        </button>
      </div>
    </div>
  </div>
</template>
