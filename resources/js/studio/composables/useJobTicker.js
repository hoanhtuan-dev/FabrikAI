import { ref, watch, onBeforeUnmount } from 'vue';

/**
 * Đồng hồ bấm giờ CHỈ chạy khi có việc đang chạy.
 *
 * Trước đây (2026-09-24) StudioCard / InpaintCard / OutfitComposeCard mỗi card tự mở một
 * `setInterval(1s)` ngay từ `onMounted` và giữ tới `onBeforeUnmount` — tức là re-render mỗi
 * giây cả khi card nhàn rỗi, chỉ để phục vụ đồng hồ "mm:ss" lúc job chạy. Nay: ticker bật khi
 * `active` chuyển true, DỪNG HẲN khi xong việc; `now` không đổi ⇒ không re-render khi nhàn rỗi.
 *
 * @param {import('vue').Ref<boolean>} active  true trong khi job đang chạy (running/busy)
 * @returns {{ now: import('vue').Ref<number> }} mốc thời gian hiện tại (chỉ nhảy khi active)
 */
export function useJobTicker(active) {
  const now = ref(Date.now());
  let timer = null;
  function stop() {
    if (timer) { clearInterval(timer); timer = null; }
  }
  watch(active, (isActive) => {
    if (isActive) {
      // Chốt mốc ngay lúc bắt đầu để giây đầu tiên hiển thị đúng, không chờ nhịp interval đầu.
      now.value = Date.now();
      if (!timer) timer = setInterval(() => { now.value = Date.now(); }, 1000);
    } else {
      stop();
    }
  }, { immediate: true });
  onBeforeUnmount(stop);
  return { now };
}
