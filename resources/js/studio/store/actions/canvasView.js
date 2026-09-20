// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — miền: khung nhìn canvas: zoom · crop · pan · xoá generation.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt file gốc.
import { markRaw } from 'vue';
import { apiError, CSRF } from '../helpers.js';
export const canvasViewActions = {
    // Zoom theo điểm chuột (cx, cy = px so với TÂM khung) — điểm ảnh dưới con trỏ
    // không trôi khi phóng/thu (pan' = c*(1-k) + pan*k).
    zoomAt(cx, cy, factor) {
      const z0 = this.zoom;
      const z1 = Math.max(0.1, Math.min(8, z0 * factor));
      if (z1 === z0) return;
      const k = z1 / z0;
      this.zoom = z1;
      this.pan = { x: cx * (1 - k) + this.pan.x * k, y: cy * (1 - k) + this.pan.y * k };
    },
    wheelZoom(e) {
      // e có thể là event (kèm clientX/Y) hoặc số deltaY (tương thích cũ)
      let cx = 0, cy = 0, delta;
      if (typeof e === 'number') { delta = e; }
      else {
        delta = e.deltaY;
        const cont = this.canvasZoom;
        if (cont) {
          const r = cont.getBoundingClientRect();
          cx = e.clientX - r.left - r.width / 2;
          cy = e.clientY - r.top - r.height / 2;
        }
      }
      this.zoomAt(cx, cy, delta > 0 ? 1 / 1.15 : 1.15);
    },
    // ── Canvas refs (set by StudioApp template refs; markRaw so Vue never proxies DOM nodes) ──
    setCanvasRefs(img, zoom) { this.cvImg = img ? markRaw(img) : null; this.canvasZoom = zoom ? markRaw(zoom) : null; },
    setBrushCanvas(el) { this.brushOverlay = el ? markRaw(el) : null; },
    // ── Reframe / Crop ──
    _clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); },
    ratioAspect() { const p = (this.reframeRatio || '3:4').split(':').map(Number); return p[1] ? p[0] / p[1] : 0.75; },
    // CSS transform dùng chung cho layer (stack + isolate + overlay neo khung) — 1 công thức duy
    // nhất để overlay/preview bám CHÍNH XÁC theo <img> (kể cả xoay/lật/scale của layer).
    layerTransformStyle(l) {
      if (!l) return 'none';
      const s = Math.max(0.05, Math.min(8, Number(l.scale) || 1));
      return 'translate(-50%, -50%) translate(' + (Number(l.x) || 0) + 'px, ' + (Number(l.y) || 0) + 'px) rotate(' + (Number(l.rotation) || 0) + 'deg) scale(' + (s * (l.flipX ? -1 : 1)) + ', ' + (s * (l.flipY ? -1 : 1)) + ')';
    },
    // Đo bbox thật của <img> (dùng khi layer còn xoay/lật — crop/reframe) — nhánh DOM gốc.
    _domMetrics(img, cr) {
      const ir = img.getBoundingClientRect();
      if (!ir.width || !ir.height || !cr.width || !cr.height) return null;
      const iw = img.naturalWidth || 1, ih = img.naturalHeight || 1;
      const ia = iw / ih, box = ir.width / ir.height;
      let vw, vh;
      if (ia > box) { vw = ir.width; vh = ir.width / ia; } else { vh = ir.height; vw = ir.height * ia; }
      return { vw, vh, vx: ir.left - cr.left + (ir.width - vw) / 2, vy: ir.top - cr.top + (ir.height - vh) / 2, crW: cr.width, crH: cr.height, crLeft: cr.left, crTop: cr.top, ia, iw, ih };
    },
    // Geometry của vùng ảnh HIỂN THỊ trong pan container (toạ độ container px).
    // Layer đang "identity" (không xoay/lật — mọi công cụ sửa pixel đều flatten trước) → TÍNH
    // thuần bằng số liệu store (zoom/pan/x/y/scale/baseW/baseH) thay vì đo getBoundingClientRect
    // của <img>. Đo DOM khiến overlay/preview phụ thuộc thứ tự render giữa các component: khi
    // phóng to, một component có thể đọc rect lúc style zoom chưa được patch → preview bị TRÔI
    // khỏi vùng thật (thu nhỏ về thì hết). Vì hàm này chỉ đọc state reactive nên mọi computed
    // tự cập nhật đúng ngay trong cùng một flush — khớp 100% ở mọi mức zoom/pan.
    // (Layer còn xoay/lật hoặc ảnh chưa sẵn layout → fallback đo DOM như cũ.)
    canvasMetrics() {
      const img = this.cvImg, cont = this.canvasZoom;
      if (!img || !cont) return null;
      const cr = cont.getBoundingClientRect();
      if (!cr.width || !cr.height) return null;
      const l = this.activeLayer;
      const rot = l ? Math.abs((Number(l.rotation) || 0) % 360) : 0;
      if (!l || (rot > 0.5 && rot < 359.5) || l.flipX || l.flipY) return this._domMetrics(img, cr);
      const fl = this.frameLayout; // kích thước layout của <img> (baseW/baseH)
      if (!fl) return this._domMetrics(img, cr);
      const iw = img.naturalWidth || 1, ih = img.naturalHeight || 1;
      const z = Math.max(0.05, this.zoom || 1);
      const s = Math.max(0.05, Math.min(8, Number(l.scale) || 1));
      const f = s * z; // tỉ lệ hiển thị thực (base px → màn hình)
      const x = Number(l.x) || 0, y = Number(l.y) || 0;
      // Tâm ảnh cách tâm container: z*(x,y) + pan (pan ở screen px, x/y ở layout px).
      return {
        vw: fl.w * f,
        vh: fl.h * f,
        vx: cr.width / 2 + (this.pan ? this.pan.x : 0) + z * x - (fl.w * f) / 2,
        vy: cr.height / 2 + (this.pan ? this.pan.y : 0) + z * y - (fl.h * f) / 2,
        crW: cr.width, crH: cr.height, crLeft: cr.left, crTop: cr.top,
        ia: (iw > 1 && ih > 1) ? iw / ih : fl.w / fl.h, iw, ih,
      };
    },
    cropStyle() {
      const m = this.canvasMetrics(); if (!m) return { display: 'none' };
      const b = this.cropBox || { x: 0.15, y: 0.15, w: 0.7, h: 0.7 };
      return { left: ((m.vx + b.x * m.vw) / m.crW * 100) + '%', top: ((m.vy + b.y * m.vh) / m.crH * 100) + '%', width: (b.w * m.vw / m.crW * 100) + '%', height: (b.h * m.vh / m.crH * 100) + '%' };
    },
    cropSizeLabel() {
      const img = this.cvImg, b = this.cropBox;
      if (!img || !b) return this.reframeRatio;
      const w = Math.max(1, Math.round(b.w * (img.naturalWidth || 1)));
      const h = Math.max(1, Math.round(b.h * (img.naturalHeight || 1)));
      return this.reframeRatio + ' · ' + w + '×' + h;
    },
    // (Re)create the crop box: 70% tall, keeping the current ratio, centered on the image.
    initCropBox() {
      const m = this.canvasMetrics();
      const ia = m ? m.ia : (this.cropBox && this.cropBox.h ? this.cropBox.w / this.cropBox.h : 0.75);
      const r = this.ratioAspect();
      const ratioFrac = r / ia;
      let h = 0.7; if (h * ratioFrac > 1) h = 1 / ratioFrac;
      let w = h * ratioFrac;
      if (w > 1) { w = 1; h = w / ratioFrac; }
      this.cropBox = { x: (1 - w) / 2, y: (1 - h) / 2, w, h };
    },
    // Re-fit an existing crop box to a new ratio, keeping its center and height where possible.
    refitCropBox() {
      const m = this.canvasMetrics(); if (!m) return;
      const old = this.cropBox || { x: 0.15, y: 0.15, w: 0.7, h: 0.7 };
      const cx = old.x + old.w / 2, cy = old.y + old.h / 2;
      const ratioFrac = this.ratioAspect() / m.ia;
      let h = Math.max(0.2, Math.min(0.9, old.h));
      let w = h * ratioFrac;
      if (w > 1) { w = 1; h = w / ratioFrac; }
      if (h > 1) { h = 1; w = h * ratioFrac; }
      this.cropBox = { x: this._clamp(cx - w / 2, 0, 1 - w), y: this._clamp(cy - h / 2, 0, 1 - h), w, h };
    },
    toggleCrop() {
      this.cropMode = !this.cropMode;
      if (this.cropMode) {
        // Nếu mask đang active → lấy luôn vùng mask làm crop box
        if (this.inpaintMaskMode !== 'none' && (this.inpaintMaskBox.w || 0) >= 0.02) {
          this.cropBox = { ...this.inpaintMaskBox };
        } else {
          this.initCropBox();
        }
      } else {
        this._cropStop(null);
      }
    },
    onCanvasImgLoad() {
      this.imgTick++; // overlay đang bật cần đo lại sau khi ảnh decode xong (kích thước thật)
      if (this.cropMode) this.initCropBox();
      // Ảnh load xong có thể làm overlay erase/draw được gắn LÚC ẢNH CHƯA DECODE bị sai kích thước
      // (gắn với ratio mặc định vuông). Re-attach để khớp tỉ lệ thật của ảnh; nét vẽ trước đó sẽ
      // bị xoá nhưng trước khi ảnh hiển thị thì chưa thể vẽ gì có ý nghĩa — nên an toàn.
      if (this.eraseMode && this._eraseCanvas) this.attachEraseCanvas(this._eraseCanvas);
      if (this.drawMode && this._drawCanvas) this.attachDrawCanvas(this._drawCanvas);
    },
    cropStart(e, key) {
      if (!this.cropMode) return;
      // NOTE: no preventDefault() here — canceling pointerdown would also suppress the
      // compatibility dblclick used for "double-click to cancel". touch-action:none (CSS)
      // already blocks scroll/zoom and select-none blocks text selection.
      e.stopPropagation();
      // A previous drag may still be armed (e.g. a fast second press before the first release) — close it first so its window listeners are removed.
      if (this._cropDrag) this._cropStop(this._cropDrag.handlers);
      const handlers = { move: (ev) => this._cropQueue(ev), up: () => this._cropStop(handlers) };
      this._cropDrag = { key, sx: e.clientX, sy: e.clientY, box: { ...(this.cropBox || { x: 0.15, y: 0.15, w: 0.7, h: 0.7 }) }, handlers };
      window.addEventListener('pointermove', handlers.move);
      window.addEventListener('pointerup', handlers.up);
      window.addEventListener('pointercancel', handlers.up);
    },
    // Batch pointermoves through rAF so dragging never triggers a layout read per event.
    _cropQueue(e) {
      if (!this._cropDrag) return;
      this._cropPending = e;
      if (this._cropRaf) return;
      const flush = () => { this._cropRaf = null; const ev = this._cropPending; this._cropPending = null; if (ev && this._cropDrag) this.cropMove(ev); };
      if (typeof requestAnimationFrame === 'function') this._cropRaf = requestAnimationFrame(flush);
      else flush();
    },
    _cropStop(handlers) {
      this._cropDrag = null;
      this._cropPending = null;
      if (this._cropRaf != null) { if (typeof cancelAnimationFrame === 'function') cancelAnimationFrame(this._cropRaf); this._cropRaf = null; }
      if (handlers) {
        window.removeEventListener('pointermove', handlers.move);
        window.removeEventListener('pointerup', handlers.up);
        window.removeEventListener('pointercancel', handlers.up);
      }
    },
    cropMove(e) {
      const d = this._cropDrag; if (!d || !this.cropMode) return;
      const m = this.canvasMetrics(); if (!m) return;
      const bx = (e.clientX - d.sx) / m.vw, by = (e.clientY - d.sy) / m.vh;
      const b = { ...d.box };
      const MIN = 0.05;
      if (d.key === 'move') {
        b.x = this._clamp(b.x + bx, 0, 1 - b.w);
        b.y = this._clamp(b.y + by, 0, 1 - b.h);
      } else {
        // Corner resize, ratio-locked, opposite corner anchored.
        const ratioFrac = this.ratioAspect() / m.ia;
        const maxRight = 1 - b.x, maxBottom = 1 - b.y;
        const right = b.x + b.w, bottom = b.y + b.h;
        let nw = b.w, nh = b.h, x = b.x, y = b.y;
        if (d.key === 'se' || d.key === 'resize') { nh = this._clamp(b.h + Math.max(bx, by), MIN, Math.max(MIN, Math.min(maxBottom, maxRight / ratioFrac))); nw = nh * ratioFrac; }
        else if (d.key === 'sw') { nh = this._clamp(b.h + Math.max(-bx, by), MIN, Math.max(MIN, Math.min(maxBottom, right / ratioFrac))); nw = nh * ratioFrac; x = right - nw; }
        else if (d.key === 'ne') { nw = this._clamp(b.w + Math.max(bx, -by), MIN, Math.max(MIN, Math.min(maxRight, bottom * ratioFrac))); nh = nw / ratioFrac; y = bottom - nh; }
        else if (d.key === 'nw') { nw = this._clamp(b.w + Math.max(-bx, -by), MIN, Math.max(MIN, Math.min(right, bottom * ratioFrac))); nh = nw / ratioFrac; x = right - nw; y = bottom - nh; }
        b.w = this._clamp(nw, MIN, 1); b.h = this._clamp(nh, MIN, 1); b.x = x; b.y = y;
      }
      this.cropBox = b;
    },
    async confirmCrop() {
      if (!this.cropMode || this.reframing) return;
      const img = this.cvImg;
      if (!img) { this.toast('Chưa có ảnh trên canvas.', 'error'); return; }
      const iw = img.naturalWidth, ih = img.naturalHeight;
      if (!iw || !ih) { this.toast('Ảnh chưa tải xong.', 'error'); return; }
      const b = this.cropBox || { x: 0.15, y: 0.15, w: 0.7, h: 0.7 };
      const x = Math.max(0, Math.round(b.x * iw)), y = Math.max(0, Math.round(b.y * ih));
      const w = Math.max(1, Math.min(iw - x, Math.round(b.w * iw))), h = Math.max(1, Math.min(ih - y, Math.round(b.h * ih)));
      this.reframing = true;
      try {
        const d = await this.api('/api/reframe', { image: this.upscaleSrc, ratio: this.reframeRatio, x, y, w, h, ...this.projectField() });
        this.addGen({ id: d.generation_id, type: 'image', status: 'completed', model: 'reframe', provider: 'reframe', media_url: d.media_url, error: null, credits_cost: 0, created_at: 'Vừa cắt' });
        this.cropMode = false; this._cropStop(null);
        this.toast('Đã cắt vùng đã chọn.');
      } catch (err) { this.toast(err.message || 'Lỗi cắt.', 'error'); }
      finally { this.reframing = false; }
    },
    async reframeCenter() {
      if (!this.upscaleSrc || this.reframing) return;
      this.reframing = true;
      try {
        const d = await this.api('/api/reframe', { image: this.upscaleSrc, ratio: this.reframeRatio, ...this.projectField() });
        this.addGen({ id: d.generation_id, type: 'image', status: 'completed', model: 'reframe', provider: 'reframe', media_url: d.media_url, error: null, credits_cost: 0, created_at: 'Vừa cắt' });
        this.toast('Đã cắt giữa ' + this.reframeRatio + '.');
      } catch (e) { this.failToast(e, 'Lỗi cắt.'); }
      finally { this.reframing = false; }
    },
    async applyFilmLook() {
      if (!this.upscaleSrc || this.looking) return;
      this.looking = true;
      try {
        const d = await this.api('/api/look', { image: this.upscaleSrc, look: this.lookPreset, level: Number(this.lookLevel) || 5, ...this.projectField() });
        this.addGen({ id: d.generation_id, type: 'image', status: 'completed', model: 'look', provider: 'look', media_url: d.media_url, error: null, credits_cost: 0, created_at: 'Vừa áp dụng' });
        this.toast('Đã áp dụng Look ' + this.lookPreset + '.');
      } catch (e) { this.failToast(e, 'Lỗi áp dụng Look.'); }
      finally { this.looking = false; }
    },
    upscaleCfg() { return { scale: this.upscaleScale, refine: this.upscaleRefine, vibrance: this.vibrance }; },
    loadUpscaleMemory() {
      try { const m = JSON.parse(localStorage.getItem('fabrikai.upscale') || '{}'); if (m.settings) Object.assign(this, { upscaleScale: m.settings.scale ?? 2, upscaleRefine: m.settings.refine ?? 0, vibrance: m.settings.vibrance ?? 3 }); } catch (e) { console.error('studio operation failed', e); }
    },
    saveUpscaleMemory() { try { localStorage.setItem('fabrikai.upscale', JSON.stringify({ settings: this.upscaleCfg() })); } catch (e) { console.error('studio operation failed', e); } },
    zoomIn() { this.zoomAt(0, 0, 1.5); },
    zoomOut() { this.zoomAt(0, 0, 1 / 1.5); },
    // "Vừa": thu/phóng cho VỪA KHUNG nhìn — tính bbox các layer đang hiển thị (ở zoom 1, đã tính
    // rotation/scale) rồi chọn zoom = min(cw/bbw, ch/bbh). Trước đây chỉ reset zoom=1/pan=0 nên
    // ảnh nhỏ/ảnh đã di chuyển không bao giờ "vừa" được với vùng canvas.
    zoomFit() {
      const cont = this.canvasZoom;
      const layers = this.canvasLayers.filter((l) => l.visible !== false && l.image);
      if (!cont || !layers.length) { this.zoom = 1; this.pan = { x: 0, y: 0 }; return; }
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      layers.forEach((l) => {
        const bw = Number(l.baseW) || 512, bh = Number(l.baseH) || 512;
        const s = Number(l.scale) || 1;
        const rot = ((Number(l.rotation) || 0) * Math.PI) / 180;
        const cos = Math.cos(rot), sin = Math.sin(rot);
        const hw = (bw * s) / 2, hh = (bh * s) / 2;
        const x = Number(l.x) || 0, y = Number(l.y) || 0;
        [[-hw, -hh], [hw, -hh], [hw, hh], [-hw, hh]].forEach(([cx, cy]) => {
          const px = cx * cos - cy * sin + x;
          const py = cx * sin + cy * cos + y;
          if (px < minX) minX = px; if (px > maxX) maxX = px;
          if (py < minY) minY = py; if (py > maxY) maxY = py;
        });
      });
      const r = cont.getBoundingClientRect();
      const cw = Math.max(1, r.width - 64), ch = Math.max(1, r.height - 64);
      const bw2 = Math.max(1, maxX - minX), bh2 = Math.max(1, maxY - minY);
      // Giới hạn trên 100% để "Vừa" luôn là cái nhìn tổng thể (không phóng to hơn ảnh thật).
      const z = Math.max(0.05, Math.min(1, Math.min(cw / bw2, ch / bh2)));
      const cx = (minX + maxX) / 2, cy = (minY + maxY) / 2;
      this.zoom = z;
      this.pan = { x: -cx * z, y: -cy * z };
    },
    // Fit view KHUNG các layer/group ĐANG CHỌN (chỉ chúng, không toàn bộ canvas).
    zoomFitSelection() {
      const cont = this.canvasZoom;
      const ids = this.selectedIds;
      if (!cont || !ids.length) return;
      const layers = this.canvasLayers.filter(l => ids.includes(l.id) && l.visible !== false && l.image);
      if (!layers.length) return;
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      layers.forEach((l) => {
        const bw = Number(l.baseW) || 512, bh = Number(l.baseH) || 512;
        const s = Number(l.scale) || 1;
        const rot = ((Number(l.rotation) || 0) * Math.PI) / 180;
        const cos = Math.cos(rot), sin = Math.sin(rot);
        const hw = (bw * s) / 2, hh = (bh * s) / 2;
        const x = Number(l.x) || 0, y = Number(l.y) || 0;
        [[-hw, -hh], [hw, -hh], [hw, hh], [-hw, hh]].forEach(([cx, cy]) => {
          const px = cx * cos - cy * sin + x;
          const py = cx * sin + cy * cos + y;
          if (px < minX) minX = px; if (px > maxX) maxX = px;
          if (py < minY) minY = py; if (py > maxY) maxY = py;
        });
      });
      const r = cont.getBoundingClientRect();
      const cw = Math.max(1, r.width - 64), ch = Math.max(1, r.height - 64);
      const bw2 = Math.max(1, maxX - minX), bh2 = Math.max(1, maxY - minY);
      // Giới hạn max zoom = 4x cho fit selection (có thể nhỏ hơn ảnh thật để dễ thao tác).
      const z = Math.max(0.05, Math.min(4, Math.min(cw / bw2, ch / bh2)));
      const cx = (minX + maxX) / 2, cy = (minY + maxY) / 2;
      this.zoom = z;
      this.pan = { x: -cx * z, y: -cy * z };
    },
    // Pinch-zoom (2 ngón) trên cảm ứng.
    beginPinch(t1, t2) { this._pinch = { dist: Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY) || 1, zoom: this.zoom }; this._drag = null; },
    pinchMove(t1, t2) { if (!this._pinch) return; const dist = Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY) || 1; this.zoom = Math.max(0.1, Math.min(8, this._pinch.zoom * (dist / this._pinch.dist))); },
    endPinch() { this._pinch = null; },
    panStart(e) { if (this._cropDrag) return; this._drag = { x: e.clientX, y: e.clientY, px: this.pan.x, py: this.pan.y }; },
    panMove(e) {
      if (!this._drag) return;
      // Pan TỰ DO, không giới hạn, không đọc layout mỗi event → kéo mượt không khựng.
      this.pan.x = this._drag.px + (e.clientX - this._drag.x);
      this.pan.y = this._drag.py + (e.clientY - this._drag.y);
    },
    panEnd() { this._drag = null; },
    async deleteGen(g) {
      // Layer lưu cục bộ từ canvas (saveActiveLayerToOutput) KHÔNG có record server → xóa cục bộ.
      const isLocal = !!(g && (g.provider === 'layer' || g.model === 'layer' || String(g.id || '').startsWith('layer-')));
      if (isLocal) {
        this._removeGenLocal(g);
        this.toast('Đã xóa layer khỏi Output.');
        return true;
      }
      try {
        const r = await fetch('/api/generations/' + g.id, { method: 'DELETE', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' } });
        const ct = (r.headers.get('content-type') || '');
        const d = ct.includes('application/json') ? await r.json().catch(() => ({})) : {};
        // Redirect về trang login (chưa đăng nhập/hết phiên) trả HTML 200 — phải coi là THẤT BẠI
        // để không báo "Đã xóa" trong khi server thực tế chưa xóa.
        if (!r.ok || r.redirected || !ct.includes('application/json')) throw apiError(d, 'Không xóa được — hãy tải lại trang và thử lại.');
        this._removeGenLocal(g);
        this.toast('Đã xóa.');
        return true;
      }
      catch (e) { this.failToast(e, 'Lỗi xóa.'); return false; }
    },
    // Gỡ generation khỏi store + layer canvas liên quan (dùng chung cho xóa server & xóa cục bộ).
    _removeGenLocal(g) {
      this.generations = this.generations.filter(x => x.id !== g.id);
      if (this.libraryItems && this.libraryItems.some(x => x.id === g.id)) {
        this.libraryItems = this.libraryItems.filter(x => x.id !== g.id);
        this.libraryTotal = Math.max(0, this.libraryTotal - 1);
      }
      this.librarySelection = (this.librarySelection || []).filter(id => id !== g.id);
      const lid = String(g.id);
      if (this.canvasLayers.some((l) => l.id === lid)) this.canvasLayers = this.canvasLayers.filter((l) => l.id !== lid);
      const orphanSource = g.media_url ? this.canvasLayers.find((l) => l.kind === 'source' && l.image === g.media_url) : null;
      if (orphanSource) this.canvasLayers = this.canvasLayers.filter((l) => !(l.kind === 'source' && l.image === g.media_url));
      if (this.activeLayerId === lid || this.previewId === g.id || (orphanSource && this.activeLayerId === orphanSource.id)) {
        const next = this.canvasLayers.find((x) => x.visible !== false);
        if (next) this.selectLayer(next);
        else { this.activeLayerId = ''; this.editSource = null; this.previewId = null; this.preview = null; }
      }
      this.saveLayerLayout();
    },
};
