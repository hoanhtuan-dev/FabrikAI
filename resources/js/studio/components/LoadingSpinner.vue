<script setup>
import { computed } from 'vue';

const props = defineProps({
  text: { type: String, default: 'AI đang xử lý…' },
  subtext: { type: String, default: '' },
  progress: { type: Number, default: null }, // 0-100, null = indeterminate
  size: { type: String, default: 'md' }, // 'sm' | 'md' | 'lg'
  center: { type: Boolean, default: false }, // căn giữa màn hình
});

const sizeClasses = computed(() => ({
  sm: { wrapper: 'gap-2', dot: 'h-2 w-2', text: 'text-body', sub: 'text-tiny', bar: 'h-0.5' },
  md: { wrapper: 'gap-3', dot: 'h-2.5 w-2.5', text: 'text-xs', sub: 'text-label', bar: 'h-1' },
  lg: { wrapper: 'gap-4', dot: 'h-3 w-3', text: 'text-sm', sub: 'text-body', bar: 'h-1.5' },
}[props.size] || { wrapper: 'gap-3', dot: 'h-2.5 w-2.5', text: 'text-xs', sub: 'text-label', bar: 'h-1' }));

const hasProgress = computed(() => props.progress != null);
</script>

<template>
  <div :class="['flex select-none flex-col items-center', center ? 'absolute inset-0 justify-center' : 'py-4', sizeClasses.wrapper]">
    <!-- Dot-pulse: 3 dots với animation delay khác nhau -->
    <div class="flex items-center gap-1.5">
      <!-- §5.2: animation TRƯỚC ĐÂY khai trong inline style trỏ tới keyframe ở <style scoped>.
           Vue chỉ rewrite TÊN keyframe trong CSS declaration (postcss walkDecls), không đụng inline
           style -> tên không khớp -> animation KHÔNG chạy. Dùng class (được rewrite đồng bộ), chỉ
           để animationDelay inline. -->
      <span v-for="i in 3" :key="i" :class="[sizeClasses.dot]"
            class="dot-pulse rounded-full bg-brand-400"
            :style="{ animationDelay: (i - 1) * 0.2 + 's' }"></span>
    </div>

    <!-- Text chính với shimmer effect -->
    <p :class="[sizeClasses.text, 'font-semibold text-cream-100']">{{ text }}</p>

    <!-- Subtext (tiến độ, thời gian, v.v.) -->
    <p v-if="subtext" :class="[sizeClasses.sub, 'text-cream-400']">{{ subtext }}</p>

    <!-- Progress bar -->
    <div v-if="hasProgress" class="w-full max-w-xs overflow-hidden rounded-full bg-cream-50/10">
      <div :class="[sizeClasses.bar]"
           class="animate-pulse rounded-full bg-gradient-to-r from-brand-500 via-brand-400 to-brand-500 motion-ui motion-ui--size duration-slow"
           :style="{ width: Math.min(100, Math.max(0, progress)) + '%' }"></div>
    </div>
  </div>
</template>

<style scoped>
.dot-pulse {
  animation: loadingPulse 1.4s ease-in-out infinite;
}

@keyframes loadingPulse {
  0%, 80%, 100% { opacity: 0.3; transform: scale(0.7); }
  40% { opacity: 1; transform: scale(1); }
}
</style>