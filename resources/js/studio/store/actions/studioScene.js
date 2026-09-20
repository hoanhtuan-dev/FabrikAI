// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — miền: STUDIO ghép cảnh · scene catalog · palette · video scene · nhóm công việc.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt file gốc.
import { safeMessage, userFacingError } from '../helpers.js';
export const studioSceneActions = {
    // ── STUDIO: dựng khung hình từ ẢNH NGƯỜI MẪU + BỐI CẢNH + PROMPT + CHIP NHANH ───────────
    // Chip nhanh đọc từ PRESET trong "Cài đặt của tôi" (backend ghép bản dùng chung ⊕ bản riêng của
    // tài khoản) nên chỉ có MỘT nguồn chân lý: người dùng sửa preset ở Cài đặt là Studio đổi theo.
    async loadSceneCatalog(force = false) {
      if (this.sceneCatalog && !force) return this.sceneCatalog;
      try {
        this.sceneCatalog = await this.api('/api/studio/shoot/catalog', {});
        this.sceneError = '';
      } catch (e) {
        this.sceneError = userFacingError(e, 'Không tải được chip nhanh từ Cài đặt của tôi.');
      }
      return this.sceneCatalog;
    },
    /** Đổi prompt/chip/biến thể ⇒ prompt dựng sẵn không còn đúng ⇒ bỏ để dựng lại. */
    setSceneSetup(patch = {}) {
      this.sceneSetup = { ...this.sceneSetup, ...patch };
      this.scenePlan = null;
    },
    toggleSceneChip(id) {
      const sid = String(id);
      const list = Array.isArray(this.sceneSetup.chips) ? this.sceneSetup.chips : [];
      const next = list.includes(sid) ? list.filter((x) => x !== sid) : [...list, sid];
      this.setSceneSetup({ chips: next });
    },
    clearSceneChips() { this.setSceneSetup({ chips: [] }); },
    /** Dựng prompt cuối (TẤT ĐỊNH, không gọi model, không tốn credit). */
    async planScene(imageCount = 0) {
      this.sceneLoading = true;
      this.sceneError = '';
      try {
        const data = await this.api('/api/studio/shoot/plan', {
          prompt: this.sceneSetup.prompt || '',
          chips: this.sceneSetup.chips || [],
          variants: Number(this.sceneSetup.variants) || 1,
          ratio: this.sceneSetup.ratio || '',
          image_count: Math.max(0, Math.min(3, Number(imageCount) || 0)),
        });
        this.scenePlan = data || null;
        this.sceneEditedPrompt = '';   // bản sửa tay của lần trước không áp sang prompt mới
        return data;
      } catch (e) {
        this.sceneError = userFacingError(e, 'Không dựng được prompt.');
        this.scenePlan = null;
        throw e;
      } finally {
        this.sceneLoading = false;
      }
    },
    /** Prompt sẽ gửi: bản người dùng sửa tay nếu có, không thì prompt do backend dựng. */
    scenePrompt() {
      const edited = String(this.sceneEditedPrompt || '').trim();
      if (edited !== '') return edited;
      return String((this.scenePlan && this.scenePlan.prompt) || '').trim();
    },
    /**
     * CHẠY: gửi đúng prompt của Studio qua pipeline /api/compose sẵn có (base = ảnh 1 người mẫu,
     * refs = ảnh bối cảnh/tham chiếu). Một ảnh cũng chạy được (backend đã cho phép min 1).
     */
    async runScene(images) {
      const list = Array.isArray(images) ? images.filter(Boolean) : [];
      if (list.length < 1) {
        this.toast('Cần ảnh 1: ảnh người mẫu mặc trang phục.', 'error');
        return null;
      }
      const prompt = this.scenePrompt();
      if (!prompt) {
        this.toast('Chưa có prompt — nhập mô tả hoặc chọn chip nhanh.', 'error');
        return null;
      }

      this.composeStage = 'send';
      this.composeError = '';
      this.composeStartTs = Date.now();
      this.composeGenIds = [];
      try {
        const variants = Math.max(1, Math.min(4, Number(this.sceneSetup.variants) || 1));
        const d = await this.api('/api/compose', {
          images: list,
          prompt,
          final_prompt: prompt,      // prompt của Studio đã hoàn chỉnh ⇒ gửi nguyên văn
          variants,
          mode: 'compose',
          ...this.projectField(),
        });
        const items = Array.isArray(d.items) ? d.items : (d.generation_id ? [d] : []);
        items.forEach((it) => this.addGen({
          id: it.generation_id, type: 'image', status: it.status, model: it.model, provider: it.provider,
          media_url: it.media_url, error: it.error, credits_cost: it.credits_cost ?? 1, created_at: 'Studio',
        }));
        const ids = items.map((it) => it.generation_id).filter(Boolean);
        this.composeGenIds = ids;
        this.composeStage = 'processing';
        if (ids.length) this.setBatch(ids);
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        ids.forEach((id) => this.pollGeneration(id));
        return ids;
      } catch (e) {
        this.composeError = userFacingError(e, 'Studio không chạy được.');
        this.composeStage = 'error';
        this.toast(this.composeError, 'error');
        return null;
      }
    },
    // (Đã gỡ action xóa nền AI cùng nút của nó — xem ghi chú ở bảng Lớp.)
    // i2i — Ghép 2–3 ảnh thành 1 (Compose / Blend).
    async compose(images, prompt, variants = 1, mode = 'compose', creativeLevel = 6, style = '', ornamentLevel = 3, overridePrompt = '') {
      if (!Array.isArray(images) || images.length < 2 || !(prompt || '').trim()) { this.toast('Chọn ít nhất 2 ảnh + nhập mô tả.', 'error'); return null; }
      this.composeStage = 'send';
      this.composeError = '';
      this.composeStartTs = Date.now();
      const n = Number(variants) || 1;
      try {
        const payload = { images, prompt, variants: n, mode, creative_level: Number(creativeLevel) || 6, style: style || '', ornament_level: Number(ornamentLevel) ?? 3, ...this.projectField() };
        if (overridePrompt && String(overridePrompt).trim()) payload.final_prompt = String(overridePrompt).trim();
        const d = await this.api('/api/compose', payload);
        const items = Array.isArray(d.items) ? d.items : (d.generation_id ? [d] : []);
        items.forEach((it) => this.addGen({ id: it.generation_id, type: 'image', status: it.status, model: it.model, provider: it.provider, media_url: it.media_url, error: it.error, credits_cost: it.credits_cost ?? 1, created_at: 'Vừa ghép ảnh' }));
        const ids = items.map(it => it.generation_id).filter(Boolean);
        this.composeGenIds = ids;
        this.composeStage = 'processing';
        if (items.length) this.setBatch(ids);
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        ids.forEach((id) => this.pollGeneration(id));
        return items;
      } catch (e) {
        this.composeError = userFacingError(e, 'Không ghép được ảnh. Vui lòng thử lại.');
        this.composeStage = 'error';
        this.toast(this.composeError, 'error');
        return null;
      }
    },
    // Kiểm tra khi mọi biến thể compose đã về trạng thái cuối → chuyển stage done/error.
    _checkComposeDone() {
      if (!this.composeGenIds.length || this.composeStage !== 'processing') return;
      const gens = this.composeGenIds.map(id => this.generations.find(g => g.id === Number(id))).filter(Boolean);
      if (gens.length < this.composeGenIds.length) return; // chưa đủ thông tin
      if (!gens.every(g => ['completed', 'failed', 'cancelled'].includes(g.status))) return;
      const done = gens.filter(g => g.status === 'completed').length;
      const failed = gens.filter(g => g.status === 'failed').length;
      if (done > 0) {
        this.composeStage = 'done';
        const warn = failed > 0 ? ' — ' + failed + ' bản thất bại.' : '';
        this.toast('Đã ghép xong ' + done + ' biến thể.' + warn);
      } else {
        this.composeStage = 'error';
        this.composeError = safeMessage(gens.find((g) => g.error)?.error, 'Ghép ảnh thất bại. Vui lòng thử lại.');
        this.toast(this.composeError, 'error');
      }
    },
    async cancelCompose() {
      for (const id of this.composeGenIds) {
        try { await this.api('/api/generations/' + id + '/cancel', {}); } catch (e) { console.error('studio operation failed', e); }
      }
      this.composeStage = 'cancelled';
      this.toast('Đã hủy ghép ảnh.');
    },
    clearComposeStatus() { this.composeStage = ''; this.composeError = ''; this.composeGenIds = []; this.composeStartTs = 0; },
    async loadPalette(id) {
      if (!id) { this.palette = []; return; }
      try { const res = await fetch('/api/generations/' + id + '/palette', { headers: { Accept: 'application/json' } }); if (!res.ok) throw new Error('HTTP ' + res.status); const d = await res.json(); this.palette = d.colors || []; }
      catch (e) { this.palette = []; }
    },
    // Palette màu cho ẢNH BẤT KỲ (source/uploaded/product/result/dataURL) — trích màu NGAY TRÊN TRÌNH DUYỆT.
    async loadPaletteFromImage(url) {
      if (!url) { this.palette = []; return; }
      try { this.palette = await this._extractColors(url); }
      catch (e) { this.palette = []; }
    },
    _extractColors(url) {
      return new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => {
          try {
            const W = 64;
            const H = Math.max(1, Math.round((img.naturalHeight || 1) * (W / (img.naturalWidth || 1))));
            const c = document.createElement('canvas'); c.width = W; c.height = H;
            const ctx = c.getContext('2d');
            ctx.drawImage(img, 0, 0, W, H);
            const d = ctx.getImageData(0, 0, W, H).data;
            // Bucket màu giống backend: quantize /32, cộng dồn trung bình, lấy 8 màu nhiều nhất.
            const buckets = new Map();
            for (let i = 0; i < d.length; i += 4) {
              const a = d[i + 3];
              if (a < 128) continue; // bỏ pixel trong suốt
              const r = d[i], g = d[i + 1], b = d[i + 2];
              const key = ((r / 32) | 0) + ',' + ((g / 32) | 0) + ',' + ((b / 32) | 0);
              let bk = buckets.get(key);
              if (!bk) { bk = { n: 0, r: 0, g: 0, b: 0 }; buckets.set(key, bk); }
              bk.n++; bk.r += r; bk.g += g; bk.b += b;
            }
            const sorted = [...buckets.values()].sort((a, b) => b.n - a.n).slice(0, 8);
            const colors = sorted.map((bk) => {
              const rr = Math.round(bk.r / bk.n), gg = Math.round(bk.g / bk.n), bb = Math.round(bk.b / bk.n);
              return '#' + [rr, gg, bb].map((v) => v.toString(16).padStart(2, '0')).join('');
            });
            resolve(colors);
          } catch (e) { resolve([]); }
        };
        img.onerror = () => resolve([]);
        img.src = url;
      });
    },
    async renderVideo() {
      const srcImage = this.upscaleSrc || '';
      if (!this.videoPromptEn && !srcImage) { this.toast('Nhập prompt video hoặc chọn ảnh nguồn.', 'error'); return; }
      if (this.videoBusy) return;
      this.videoBusy = true;
      try {
        // Prompt video: ưu tiên người dùng nhập, nếu trống thì ghép từ prompt ảnh đang có,
        // cuối cùng rơi về mô tả catwalk mặc định (giống luồng Alpine cũ tại index.blade.php).
        let prompt = this.videoPromptEn;
        if (!prompt && this.imagePromptEn) {
          prompt = 'Cinematic fashion catwalk: ' + this.imagePromptEn + ', dynamic fabric motion, professional fashion video.';
        }
        if (!prompt) {
          prompt = 'a fashion model walking on a runway, cinematic fashion catwalk, dynamic fabric motion, professional fashion video';
        }
        const d = await this.api('/api/video', {
          prompt,
          camera: this.videoSceneCamera(),
          base_image: srcImage,
          // Model do người dùng chọn trên card Kịch bản quay; '' = default nhóm video (Cài đặt →).
          ...(this.selectedTaskModel('video') ? { provider: this.selectedTaskModel('video').provider, model: this.selectedTaskModel('video').model } : {}),
          duration: this.videoDuration,
          resolution: this.videoRes,
          project_id: this.appliedProjectId(),
        });
        this.addGen({ id: d.generation_id, type: 'video', status: d.status || 'processing', model: d.model || this.videoModel, provider: d.provider || 'video', media_url: d.media_url || null, error: null, credits_cost: d.credits_cost || 10, created_at: 'Vừa gửi' });
      } catch (e) { this.failToast(e, 'Lỗi render video.'); }
      finally { this.videoBusy = false; }
    },
    // Đọc preset "Kịch bản quay" (video_scene) đang chọn từ danh sách nạp ở Cài đặt → Prompt Templates.
    videoSceneCamera() {
      const sc = this.videoScenes.find(s => String(s.id) === String(this.videoScene));
      return sc ? sc.prompt : '';
    },
    // ── Task groups: model theo nhóm công việc (Cài đặt →  Nhóm công việc) ──
    // Danh sách model của một nhóm + default đang dùng — selector trên từng card.
    taskGroupModels(group) { return (this.taskGroups[group] || {}).models || []; },
    taskGroupDefault(group) { return (this.taskGroups[group] || {}).default || ''; },
    // [provider, model] đang chọn cho một nhóm: selector trên card > default nhóm.
    selectedTaskModel(group) {
      const sel = group === 'video' ? this.videoModelSel : this.imageModelSel;
      if (sel && sel.includes(':')) { const [p, m] = sel.split(':'); return { provider: p, model: m }; }
      const d = this.taskGroupDefault(group);
      if (d && d.includes(':')) { const [p, m] = d.split(':'); return { provider: p, model: m }; }
      const first = this.taskGroupModels(group)[0];
      return first ? { provider: first.provider, model: first.model } : null;
    },
};
