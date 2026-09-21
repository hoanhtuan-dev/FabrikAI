<script setup>
import BaseModal from '../BaseModal.vue';
import StudioIcon from '../StudioIcon.vue';
/**
 * Xác nhận thay cho window.confirm().
 *
 * [Lý do 2026-09-20] Bản cũ gọi confirm() gốc: hộp thoại của trình duyệt hiện ra với giao diện hệ điều
 * hành, khoá cứng tab, không đọc được bằng trình đọc màn hình theo ngôn ngữ app, và trên một số trình
 * duyệt bị chặn hoàn toàn. Nay dùng BaseModal — đã có sẵn Esc + bẫy tiêu điểm + aria-modal của repo.
 */
defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: 'Xác nhận' },
  message: { type: String, default: '' },
  detail: { type: String, default: '' },
  confirmLabel: { type: String, default: 'Xác nhận' },
  cancelLabel: { type: String, default: 'Huỷ' },
  danger: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'confirm']);
</script>
<template>
  <BaseModal :model-value="modelValue" :title="title" @update:model-value="emit('update:modelValue', $event)">
    <div class="flex items-start gap-3">
      <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full"
            :class="danger ? 'bg-danger/15 text-danger' : 'bg-brand-500/15 text-brand-300'">
        <StudioIcon :name="danger ? 'alertTriangle' : 'info'" size="h-4.5 w-4.5" />
      </span>
      <div class="min-w-0 flex-1">
        <p class="text-sm leading-relaxed text-cream-100">{{ message }}</p>
        <p v-if="detail" class="mt-1.5 text-xs leading-relaxed text-cream-300">{{ detail }}</p>
      </div>
    </div>
    <div class="mt-5 flex items-center justify-end gap-2">
      <button class="btn-outline btn-sm" :disabled="busy" @click="emit('update:modelValue', false)">{{ cancelLabel }}</button>
      <button class="btn-sm rounded-md px-3 py-1.5 text-xs font-semibold transition disabled:opacity-50"
              :class="danger ? 'bg-danger text-danger-content hover:bg-danger' : 'bg-brand-600 text-primary-content hover:bg-brand-500'"
              :disabled="busy" @click="emit('confirm')">
        {{ busy ? 'Đang xử lý…' : confirmLabel }}
      </button>
    </div>
  </BaseModal>
</template>
