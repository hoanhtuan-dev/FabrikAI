/**
 * HÀNH ĐỘNG TRÊN MỘT ẢNH — MỘT chỗ duy nhất (đợt 60 · 2026-09-26).
 *
 * VÌ SAO TÁCH RA: bốn hành động này (biến thể · nâng cấp · đổi khung · tải/chia sẻ/xoá) giờ có HAI lối
 * vào trên điện thoại — sheet «Tác vụ ảnh» (Options → Action) và hàng chip ngay trên màn Studio (theo
 * prototype: Upscale 4K · Tải xuống · Chia sẻ · Tech pack). Chép logic sang lối thứ hai là hai bản sao
 * của cùng một lời gọi API: sửa endpoint ở một nơi, nơi kia lặng lẽ gọi đường cũ.
 *
 * Luật giữ nguyên từ bản trong PhoneActions.vue:
 *   · mọi kết quả mới đi qua `store.addGen()` ⇒ hiện NGAY ở rail Kết quả gần đây;
 *   · lỗi đi qua `store.failToast()` (có mã tra cứu), không ném ra ngoài;
 *   · ảnh gốc KHÔNG bị sửa — mỗi thao tác sinh một kết quả riêng.
 */
import { ref } from 'vue';
import { useStudioStore } from '../store.js';
import { haptic } from './useHaptics.js';

export function useImageActions() {
  const store = useStudioStore();
  const busyVariant = ref(false);
  const busyUpscale = ref(false);
  const busyReframe = ref(false);

  const hasImage = () => !!store.upscaleSrc;

  /** Biến thể (POST /api/refgen) — tham số: mức giữ nét gốc + số biến thể. */
  async function runVariant(sim = 70, variants = 2) {
    if (!hasImage() || busyVariant.value) return false;
    busyVariant.value = true;
    haptic(12);
    try {
      await store.refgen(store.upscaleSrc, store.imagePromptEn || '', sim, variants);
      return true;
    } finally { busyVariant.value = false; }
  }

  /** Nâng cấp (POST /api/upscale) — cùng endpoint, cùng cờ với UpscaleCard ở màn rộng. */
  async function runUpscale(scale = 2) {
    if (!hasImage() || busyUpscale.value) return false;
    busyUpscale.value = true;
    haptic(12);
    try {
      const d = await store.api('/api/upscale', {
        image: store.upscaleSrc,
        scale: Number(scale) || 2,
        refine: Number(store.upscaleRefine) || 0,
        vibrance: Number(store.vibrance) || 0,
        project_id: store.appliedProjectId(),
      });
      store.addGen({
        id: d.generation_id, type: 'image', status: d.status || 'completed',
        model: d.model || 'upscale', provider: d.provider || 'upscale',
        media_url: d.media_url, error: d.error || null,
        credits_cost: Number(d.credits_cost ?? 0), created_at: 'Vừa nâng cấp',
      });
      store.toast('Đã nâng cấp ảnh (' + scale + '×).');
      return true;
    } catch (e) { store.failToast(e, 'Lỗi nâng cấp ảnh.'); return false; }
    finally { busyUpscale.value = false; }
  }

  /** Đổi khung hình (POST /api/reframe — cắt tâm theo tỉ lệ, đồng bộ, miễn phí). */
  async function runReframe(ratio = '3:4') {
    if (!hasImage() || busyReframe.value) return false;
    busyReframe.value = true;
    haptic(12);
    try {
      const d = await store.api('/api/reframe', { image: store.upscaleSrc, ratio, project_id: store.appliedProjectId() });
      store.addGen({
        id: d.generation_id, type: 'image', status: 'completed',
        model: 'reframe', provider: 'reframe', media_url: d.media_url,
        credits_cost: 0, created_at: 'Vừa đổi khung',
      });
      store.toast('Đã đổi khung ' + ratio + ' — xem ở Kết quả gần đây.');
      return true;
    } catch (e) { store.failToast(e, 'Lỗi đổi khung hình.'); return false; }
    finally { busyReframe.value = false; }
  }

  /** Tải ảnh gốc — đi qua route của app (không dùng `media_url` trần). */
  function download() {
    const g = store.preview;
    if (!g || !g.id) { store.toast('Chưa có ảnh để tải.', 'error'); return; }
    window.location.href = '/api/generations/' + g.id + '/download';
  }

  /** Chia sẻ: Web Share API nếu có, không thì chép liên kết. */
  async function share() {
    const url = store.upscaleSrc;
    if (!url) { store.toast('Chưa có ảnh để chia sẻ.', 'error'); return; }
    try {
      if (navigator.share) await navigator.share({ title: 'FabrikAI', url });
      else { await navigator.clipboard.writeText(url); store.toast('Đã chép liên kết ảnh.', 'success'); }
    } catch (e) { /* người dùng tự huỷ hộp chia sẻ của hệ điều hành */ }
  }

  /** Xoá ảnh đang xem — đi qua ĐÚNG action mà trình xem/lưới đang dùng. */
  async function remove() {
    const g = store.preview;
    if (!g || !g.id) return;
    await store.deleteGen(g);
  }

  return { hasImage, runVariant, runUpscale, runReframe, download, share, remove, busyVariant, busyUpscale, busyReframe };
}
