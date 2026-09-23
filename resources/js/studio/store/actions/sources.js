// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — miền: nguồn ảnh · batch progress · gợi ý phong cách (stream).
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt file gốc.
import { apiError, safeMessage, userFacingError, CSRF } from '../helpers.js';
export const sourcesActions = {
    setBatch(ids) { this.lastBatch = (ids || []).filter(Boolean); this.showBatch = this.lastBatch.length > 1; },
    hideBatch() { this.showBatch = false; },
    /**
     * [Đợt 0.2] Tiến trình THẬT cho lô đang chạy — suy ra từ trạng thái THẬT của từng generation
     * trong lô, KHÔNG phải hoạt ảnh. Được gọi mỗi khi trạng thái một ảnh trong lô đổi
     * (pollGeneration) và ngay sau khi tạo lô.
     *
     * Quy ước hiển thị:
     *   queued   — đã gửi, backend còn 'pending' (chưa tới lượt xử lý)
     *   rendering— có ít nhất một ảnh đang 'processing'
     *   done     — MỌI ảnh đã 'completed'
     *   failed   — lô kết thúc mà tất cả đều lỗi/bị huỷ
     * % = số ảnh ĐÃ KẾT THÚC (completed + failed/cancelled) / tổng số ảnh của lô.
     */
    syncBatchProgress() {
      const ids = (this.lastBatch || []).map(Number).filter(Boolean);
      if (!ids.length) return;
      const items = ids.map((id) => this.generations.find((g) => Number(g.id) === id)).filter(Boolean);
      if (!items.length) return;

      const total = items.length;
      const done = items.filter((g) => g.status === 'completed').length;
      const dead = items.filter((g) => g.status === 'failed' || g.status === 'cancelled').length;
      const processing = items.filter((g) => g.status === 'processing').length;
      const settled = done + dead;

      this.generateProgress = Math.round((settled / total) * 100);
      this.generatedCount = done;

      if (settled === total && done === 0) this.generateStage = 'failed';
      else if (settled === total) this.generateStage = 'done';
      else if (processing) this.generateStage = 'rendering';
      else this.generateStage = 'queued';
    },
    setSource(url, name) {
      this.editSource = { url, name: name || 'Ảnh nguồn' };
      // 5.1: ảnh nguồn cũng là "ảnh đang làm việc" — cùng một khái niệm, khác nguồn gốc.
      this.setWorkingImage({ name: name || 'Ảnh nguồn', media_url: url }, 'source');
      // Bỏ layer 'source' CŨ trước khi thêm ảnh mới — nếu không pushCanvasLayer bị chặn
      // do trùng id 'source' → ảnh mới không vào được canvas khi canvas vẫn còn ảnh cũ.
      this.canvasLayers = this.canvasLayers.filter((l) => l.id !== 'source');
      this.pushCanvasLayer('source', 'source', this.editSource.name, url);
      this.setActiveLayer('source');
      this.toast('Đã chọn ảnh nguồn.');
    },
    async uploadRef(file, select = true) {
      if (!file) { this.toast('Chọn file ảnh.', 'error'); return; }
      const fd = new FormData(); fd.append('image', file);
      const res = await fetch('/api/upload-ref', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' }, body: fd });
      const d = await res.json().catch(() => ({}));
      if (!res.ok) { this.toast(d.message || 'Lỗi tải ảnh.', 'error'); return; }
      if (select) this.setSource(d.url, file.name);
      return d;
    },
    pickFromProduct(p) { this.setSource(p.url, p.name); },
    pickFromResult(g) { this.setSource(g.media_url, 'Ảnh kết quả #' + g.id); },
    // Thêm NHIỀU ảnh vào canvas — KHÔNG xóa ảnh/layer cũ (mỗi ảnh 1 layer nguồn riêng).
    addImagesToCanvas(items) {
      const list = (items || []).filter((it) => it && it.url);
      if (!list.length) return;
      const ts = Date.now();
      let lastId = '';
      list.forEach((img, i) => {
        const id = 'src-' + ts + '-' + i;
        if (this.canvasLayers.some((l) => l.id === id)) return;
        this.pushCanvasLayer(id, 'source', img.name || 'Ảnh nguồn', img.url);
        lastId = id;
      });
      if (lastId) this.setActiveLayer(lastId);
      this.saveLayerLayout();
      this.toast('Đã thêm ' + list.length + ' ảnh vào canvas.');
    },
    async translate(promptTo) {
      if (!promptTo) { this.toast('Nhập prompt.', 'error'); return; }
      this.suggestResult = this.suggestResult || {};
      try {
        const d = await this.api('/api/translate', { text: promptTo, direction: 'vi' });
        // §5.2: trước đây 'd.text || d' — thiếu d.text thì lưu NGUYÊN OBJECT vào ô văn bản, hiển thị
        // ra "[object Object]". Nay luôn là chuỗi.
        const text = typeof d?.text === 'string' ? d.text : (typeof d === 'string' ? d : '');
        if (!text) { this.toast('Máy dịch không trả nội dung — thử lại.', 'error'); return; }
        this.suggestResult.prompt_vi = text;
        this.toast('Đã dịch sang tiếng Việt.');
      } catch (e) { this.failToast(e, 'Lỗi dịch.'); }
    },
    async suggestStyle(image) {
      if (!this.suggestEnabled) { this.toast('Tính năng "Gợi ý từ ảnh" đang bị tắt trong cài đặt.', 'error'); return; }
      if (!image) { this.toast('Chọn ảnh nguồn để gợi ý.', 'error'); return; }
      this.suggesting = true;
      try {
        const payload = { reference_url: image, creative_level: this.creativeLevel };
        // Gửi độ bám/chi tiết để backend ép bám ảnh gốc (0 = tự theo creative).
        if (this.suggestAdherence) payload.adherence = this.suggestAdherence;
        if (this.suggestDetailLevel) payload.detail_level = this.suggestDetailLevel;
        // Cờ bỏ qua phân tích (mặc định false → AI phân tích bình thường).
        payload.skip_hair = this.suggestSkipHair ? 1 : 0;
        payload.skip_logo = this.suggestSkipLogo ? 1 : 0;
        payload.skip_background = this.suggestSkipBackground ? 1 : 0;
        const d = await this.api('/api/suggest', payload);
        this.suggestResult = d;
        this.pushSuggestLocal(d);
        const styles = (d.styles || []).join(', ');
        const extras = [styles, d.garment_type, d.background].filter(Boolean).join(' · ');
        this.toast(extras ? 'Đã gợi ý: ' + extras : 'Đã gợi ý.');
      }
      catch(e){ this.failToast(e, 'Lỗi gợi ý.'); }
      finally { this.suggesting = false; }
    },
    /** Payload chung cho cả đường JSON và đường stream của "Gợi ý từ ảnh". */
    _suggestPayload(image) {
      const payload = { reference_url: image, creative_level: this.creativeLevel };
      // Gửi độ bám/chi tiết để backend ép bám ảnh gốc (0 = tự theo creative).
      if (this.suggestAdherence) payload.adherence = this.suggestAdherence;
      if (this.suggestDetailLevel) payload.detail_level = this.suggestDetailLevel;
      // Cờ bỏ qua phân tích (mặc định false → AI phân tích bình thường).
      payload.skip_hair = this.suggestSkipHair ? 1 : 0;
      payload.skip_logo = this.suggestSkipLogo ? 1 : 0;
      payload.skip_background = this.suggestSkipBackground ? 1 : 0;
      return payload;
    },

    /**
     * "Gợi ý từ ảnh" có TIẾN TRÌNH THẬT: đọc NDJSON từ /api/suggest/stream và cập nhật
     * suggestPhase/suggestProvider ngay khi server gửi — người dùng thấy AI nào đang suy luận
     * thay vì một nút "Đang phân tích…" bất động suốt 10-90 giây.
     * Trình duyệt/proxy không hỗ trợ stream ⇒ tự rơi về đường JSON cũ (suggestStyle).
     */
    async suggestStyleStream(image) {
      if (!this.suggestEnabled) { this.toast('Tính năng "Gợi ý từ ảnh" đang bị tắt trong cài đặt.', 'error'); return; }
      if (!image) { this.toast('Chọn ảnh nguồn để gợi ý.', 'error'); return; }

      this.suggesting = true;
      this.suggestError = '';
      this.suggestPhase = 'prepare';
      this.suggestPhaseLabel = 'Đang chuẩn bị ảnh nguồn…';
      this.suggestProvider = '';
      this.suggestModel = '';
      this.suggestLastMeta = null;
      this.suggestStartedAt = Date.now();

      const payload = this._suggestPayload(image);

      try {
        const res = await fetch('/api/suggest/stream', {
          method: 'POST',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/x-ndjson' },
          body: JSON.stringify(payload),
        });

        if (res.status === 401 || res.redirected) { this.setAuthStatus(401); throw new Error('Phiên đăng nhập đã hết — tải lại trang.'); }

        if (!res.ok || !res.body || !res.body.getReader) {
          // Lỗi trước khi stream (422 ảnh không đọc được, tính năng tắt…) hoặc không có ReadableStream.
          const d = await res.clone?.().json?.().catch(() => ({})) ?? {};
          if (!res.ok && d && d.message) throw apiError(d, 'Lỗi hệ thống, vui lòng thử lại.');
          return await this.suggestStyle(image); // đường JSON cũ
        }

        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buf = '';
        for (;;) {
          const { value, done } = await reader.read();
          buf += decoder.decode(value || new Uint8Array(), { stream: !done });
          const lines = buf.split('\n');
          buf = lines.pop();
          for (const line of lines) this._handleSuggestEvent(line);
          if (done) break;
        }
        if (buf.trim()) this._handleSuggestEvent(buf);

        if (this.suggestError) return;

        if (this.suggestResult) {
          this.pushSuggestLocal(this.suggestResult);   // vào danh sách 'gần đây' ngay, không cần Lưu
          const d = this.suggestResult;
          const extras = [(d.styles || []).join(', '), d.garment_type, d.background].filter(Boolean).join(' · ');
          this.toast(extras ? 'Đã gợi ý: ' + extras : 'Đã gợi ý.');
        }
      } catch (e) {
        this.suggestError = userFacingError(e, 'Không gợi ý được. Vui lòng thử lại.');
        this.toast(this.suggestError, 'error');
      } finally {
        this.suggesting = false;
      }
    },

    /** Một dòng NDJSON từ /api/suggest/stream → cập nhật state cho card. */
    _handleSuggestEvent(line) {
      const raw = String(line || '').trim();
      if (!raw) return;
      let ev;
      try { ev = JSON.parse(raw); } catch { return; }

      if (ev.type === 'phase') {
        this.suggestPhase = ev.key || this.suggestPhase;
        // Nhãn này HIỂN THỊ cho người dùng ⇒ lọc ở biên, không tin nội dung server gửi.
        this.suggestPhaseLabel = safeMessage(ev.label, '') || this.suggestPhaseLabel;
      } else if (ev.type === 'provider') {
        this.suggestProvider = ev.provider || '';
        this.suggestModel = ev.model || '';
      } else if (ev.type === 'result') {
        this.suggestResult = ev.data || null;
        this.suggestLastMeta = (ev.data && ev.data._meta) || null;
        this.suggestPhase = 'done';
        this.suggestPhaseLabel = 'Hoàn tất';
      } else if (ev.type === 'error') {
        this.suggestError = safeMessage(ev.message, 'Không phân tích được ảnh này. Bạn thử lại sau ít phút, hoặc đổi sang ảnh rõ hơn.');
        this.toast(this.suggestError, 'error');
      }
    },

    /** 10 gợi ý từ ảnh MỚI NHẤT của chính người dùng (cho danh sách "gần đây" trong card). */
    async loadSuggestRecent() {
      if (this.suggestRecentLoading) return;
      this.suggestRecentLoading = true;
      // Hiện ngay lịch sử của trình duyệt (nếu có) trước khi chờ server — danh sách không trống trơn lúc tải.
      if (! (this.suggestLocalRecent || []).length) this._restoreSuggestLocal();
      this.mergeSuggestRecent();
      try {
        const res = await fetch('/api/suggest/recent?limit=10', { headers: { Accept: 'application/json' } });
        if (res.status === 401 || res.redirected) { this.setAuthStatus(401); return; }
        const d = await res.json().catch(() => ({}));
        this.suggestRecentServer = Array.isArray(d.items) ? d.items : [];
        this.suggestRecentTotal = Number(d.total || 0);
        this.mergeSuggestRecent();
      } catch (e) {
        // Danh sách gần đây là tiện ích — lỗi không được phá luồng chính.
        this.suggestRecentServer = [];
        this.mergeSuggestRecent();
      } finally {
        this.suggestRecentLoading = false;
      }
    },

    /** Đẩy một kết quả vừa phân tích vào danh sách "gần đây" phía client (chưa cần Lưu). */
    pushSuggestLocal(item) {
      if (!item || !item.image_prompt_en) return;
      const row = {
        id: 'local-' + Date.now(),
        _local: true,
        reference_url: this.upscaleSrc || '',
        garment_type: item.garment_type || '',
        styles: item.styles || [],
        background: item.background || '',
        pose: item.pose || '',
        fabric: item.fabric || '',
        silhouette: item.silhouette || '',
        camera: item.camera || '',
        embellishment: item.embellishment || '',
        detail_notes: item.detail_notes || '',
        color_palette: item.color_palette || [],
        keywords: item.keywords || [],
        image_prompt_en: item.image_prompt_en || '',
        prompt_vi: item.prompt_vi || '',
        video_prompt_en: item.video_prompt_en || '',
        creative_level: item.creative_level ?? this.creativeLevel,
        adherence: item.adherence ?? 0,
        detail_level: item.detail_level ?? 8,
        apply_count: 0,
        ago: 'vừa xong',
        _meta: item._meta || null,
      };
      const rest = (this.suggestLocalRecent || []).filter((x) => x.image_prompt_en !== row.image_prompt_en);
      this.suggestLocalRecent = [row, ...rest].slice(0, 10);
      this._persistSuggestLocal();
      this.mergeSuggestRecent();
    },

    _persistSuggestLocal() {
      try { localStorage.setItem('fabrikai.suggestRecent', JSON.stringify(this.suggestLocalRecent || [])); } catch { /* chế độ riêng tư */ }
    },

    _restoreSuggestLocal() {
      try {
        const raw = localStorage.getItem('fabrikai.suggestRecent');
        const arr = raw ? JSON.parse(raw) : [];
        this.suggestLocalRecent = Array.isArray(arr) ? arr.slice(0, 10) : [];
      } catch { this.suggestLocalRecent = []; }
    },

    /** Gộp kết quả ĐÃ LƯU (server) với lịch sử vừa phân tích (client) → 10 mục mới nhất. */
    mergeSuggestRecent() {
      const saved = this.suggestRecentServer || [];
      const savedPrompts = new Set(saved.map((x) => x.image_prompt_en));
      const local = (this.suggestLocalRecent || []).filter((x) => !savedPrompts.has(x.image_prompt_en));
      this.suggestRecent = [...local, ...saved].slice(0, 10);
    },

    /** Khôi phục một gợi ý cũ vào card (đủ trường nên không cần gọi lại AI). */
    applyRecentSuggest(item) {
      if (!item) return;
      this.suggestResult = {
        styles: item.styles || [],
        background: item.background || '',
        pose: item.pose || '',
        fabric: item.fabric || '',
        silhouette: item.silhouette || '',
        camera: item.camera || '',
        garment_type: item.garment_type || '',
        embellishment: item.embellishment || '',
        detail_notes: item.detail_notes || '',
        color_palette: item.color_palette || [],
        keywords: item.keywords || [],
        image_prompt_en: item.image_prompt_en || '',
        prompt_vi: item.prompt_vi || '',
        video_prompt_en: item.video_prompt_en || '',
        creative_level: item.creative_level ?? this.creativeLevel,
        adherence: item.adherence ?? 0,
        detail_level: item.detail_level ?? 8,
        _restored_from: item.id || null,
        _reference_url: item.reference_url || '',   // ảnh GỐC của gợi ý này (khác ảnh đang chọn)
      };
      this.suggestLastMeta = null;
      this.suggestError = '';
      this.toast('Đã nạp lại gợi ý' + (item.garment_type ? ': ' + item.garment_type : '') + '.');
    },

    statusLabel(s) { return { pending: 'Đang chờ', processing: 'Đang xử lý', completed: 'Hoàn tất', failed: 'Lỗi', cancelled: 'Đã hủy' }[s] || s || ''; },
};
