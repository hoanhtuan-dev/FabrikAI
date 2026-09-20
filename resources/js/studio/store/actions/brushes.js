// TÁCH TIẾP từ layers.js (đợt tối ưu 2026-09-24) — miền: erase brush + draw brush (GIMP/PS) + thoát công cụ canvas.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt bản gộp.
export const brushesActions = {
    // ── Xóa vùng (erase brush + feather) ──
    async toggleErase() {
      const l = this.activeLayer;
      if (l && l.locked) { this.toast('Layer đang khóa — mở khóa trước khi xóa.', 'error'); return; }
      if (!this.eraseMode && !(await this.flattenActiveLayerTransform())) return;
      this.eraseMode = !this.eraseMode;
      // KHÔNG reset zoom/pan — đóng băng vị trí & độ thu phóng hiện tại khi chọn công cụ.
      if (!this.eraseMode) this.applyErase();
    },
    // Gắn canvas overlay (DOM) làm mask để vẽ + xem trước realtime.
    attachEraseCanvas(el) {
      if (!el) { this._eraseCanvas = null; this._eraseCtx = null; return; }
      // Canvas theo TỈ LỆ HIỂN THỊ của ảnh trên canvas (không vuông cứng) — nếu vuông thì nét xóa
      // bị méo & lệch vị trí khi bake. Dùng vw/vh (vùng ảnh hiển thị) thay vì naturalWidth:
      // đúng tỉ lệ NGAY CẢ khi ảnh chưa decode xong (naturalWidth = 0) vì vw/vh đã có sau layout.
      const m = this.canvasMetrics();
      const base = 1024;
      const ratio = m && m.vw && m.vh ? m.vw / m.vh : 1;
      let w = base, h = base;
      if (ratio >= 1) h = Math.max(1, Math.round(base / ratio));
      else w = Math.max(1, Math.round(base * ratio));
      el.width = w; el.height = h;
      this._eraseCanvas = el;
      this._eraseCtx = el.getContext('2d');
      this._eraseCtx.clearRect(0, 0, w, h);
      this._eraseHasStrokes = false; // canvas mới → chưa có nét
    },
    setEraseFeather(v) { this.eraseFeather = Number(v) || 0; },
    _eraseRadius() { return Math.max(3, Math.min(150, Number(this.eraseBrushSize) || 24)); },
    _erasePoint(e) {
      const m = this.canvasMetrics();
      if (!m) return { nx: 0.5, ny: 0.5 };
      return { nx: this._clamp((e.clientX - m.crLeft - m.vx) / m.vw, 0, 1), ny: this._clamp((e.clientY - m.crTop - m.vy) / m.vh, 0, 1) };
    },
    _drawEraseDot(p) {
      const c = this._eraseCtx; if (!c) return;
      const w = this._eraseCanvas.width, h = this._eraseCanvas.height, r = this._eraseRadius();
      const f = Math.max(0, Math.min(1, (Number(this.eraseFeather) || 0) / 60)); // feather 0-60
      const hard = Math.max(0.15, 0.9 - f * 0.75); // mép cứng (f=0) → rất mềm (f=1)
      const g = c.createRadialGradient(p.nx * w, p.ny * h, 0, p.nx * w, p.ny * h, r);
      g.addColorStop(0, 'rgba(0,0,0,1)');
      g.addColorStop(hard, 'rgba(0,0,0,0.85)');
      g.addColorStop(1, 'rgba(0,0,0,0)');
      c.fillStyle = g;
      c.beginPath(); c.arc(p.nx * w, p.ny * h, r, 0, Math.PI * 2); c.fill();
    },
    _drawEraseLine(from, to) {
      const c = this._eraseCtx; if (!c) return;
      const w = this._eraseCanvas.width, r = this._eraseRadius();
      const steps = Math.max(1, Math.ceil(Math.hypot(to.nx - from.nx, to.ny - from.ny) * w / (r / 2)));
      for (let s = 0; s <= steps; s++) this._drawEraseDot({ nx: from.nx + (to.nx - from.nx) * (s / steps), ny: from.ny + (to.ny - from.ny) * (s / steps) });
    },
    beginEraseBrush(e) { if (!this.eraseMode) return; if (e.currentTarget && e.currentTarget.setPointerCapture && e.pointerId != null) { try { e.currentTarget.setPointerCapture(e.pointerId); } catch (err) {} } this._eraseDrawing = true; this._eraseLast = this._erasePoint(e); this._eraseHasStrokes = true; this._drawEraseDot(this._eraseLast); },
    eraseBrushMove(e) { if (!this._eraseDrawing) return; const p = this._erasePoint(e); this._drawEraseLine(this._eraseLast || p, p); this._eraseLast = p; },
    endEraseBrush() { this._eraseDrawing = false; this._eraseLast = null; },
    applyErase() {
      if (this._eraseBusy) return Promise.resolve();
      // Không có nét thật → không push history / không bake (tránh undo rác khi chỉ bật/tắt công cụ).
      if (!this._eraseHasStrokes) return Promise.resolve();
      this.pushHistory();
      this._eraseBusy = true;
      this._eraseHasStrokes = false;
      return new Promise((resolve) => {
        const l = this.activeLayer;
        const ec = this._eraseCanvas;
        if (!l || l.locked || !ec) {
          if (l && l.locked) this.toast('Layer đang khóa — mở khóa trước khi xóa.', 'error');
          this._eraseBusy = false;
          resolve();
          return;
        }
        const img = new Image();
        img.onload = () => {
          const w = img.naturalWidth, h = img.naturalHeight;
          const canvas = document.createElement('canvas'); canvas.width = w; canvas.height = h;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0);
          ctx.globalCompositeOperation = 'destination-out';
          ctx.drawImage(ec, 0, 0, w, h);
          l.image = canvas.toDataURL('image/png');
          this.saveLayerLayout();
          this.toast('Đã xóa vùng.');
          this._eraseBusy = false;
          resolve();
        };
        img.onerror = () => { this.toast('Không xóa được ảnh.', 'error'); this._eraseBusy = false; resolve(); };
        img.src = l.image;
      });
    },
    // " Xóa": áp dụng nét đã vẽ vào layer rồi xoá canvas — GIỮ chế độ để vẽ tiếp (không tự thoát).
    applyEraseNow() {
      this.applyErase().then(() => {
        if (this._eraseCtx && this._eraseCanvas) this._eraseCtx.clearRect(0, 0, this._eraseCanvas.width, this._eraseCanvas.height);
      });
    },
    // "✓ Xong": hoàn tất xóa — áp dụng nét còn lại rồi thoát chế độ.
    finishErase() { if (!this.eraseMode) return; this.applyErase().then(() => { this.eraseMode = false; }); },
    // "✕ Hủy": thoát chế độ erase KHÔNG áp dụng (bỏ nét đã vẽ).
    cancelErase() { if (!this.eraseMode) return; this.eraseMode = false; this.toast('Đã hủy xóa.'); },
    // ── Vẽ tự do (paint brush): tô màu lên layer active ──
    async toggleDraw() {
      const l = this.activeLayer;
      if (l && l.locked) { this.toast('Layer đang khóa — mở khóa trước khi vẽ.', 'error'); return; }
      if (!this.drawMode && !(await this.flattenActiveLayerTransform())) return;
      this.drawMode = !this.drawMode;
      if (!this.drawMode) this.applyDraw();
    },
    attachDrawCanvas(el) {
      if (!el) { this._drawCanvas = null; this._drawCtx = null; return; }
      // Canvas theo TỈ LỆ HIỂN THỊ (không vuông cứng) — khớp đúng tỉ lệ ảnh kể cả khi chưa decode.
      const m = this.canvasMetrics();
      const base = 1024;
      const ratio = m && m.vw && m.vh ? m.vw / m.vh : 1;
      let w = base, h = base;
      if (ratio >= 1) h = Math.max(1, Math.round(base / ratio));
      else w = Math.max(1, Math.round(base * ratio));
      el.width = w; el.height = h;
      this._drawCanvas = el;
      this._drawCtx = el.getContext('2d');
      this._drawCtx.clearRect(0, 0, w, h);
      this._drawHasStrokes = false; // canvas mới → chưa có nét
    },
    _drawRadius() { const base = Math.max(3, Math.min(150, Number(this.drawBrushSize) || 24)); return base * (0.4 + 0.6 * (Number(this._drawPressure) || 1)); },
    _hexToRgba(hex, a = 1) {
      const mm = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
      if (!mm) return 'rgba(255,255,255,' + a + ')';
      return 'rgba(' + parseInt(mm[1], 16) + ',' + parseInt(mm[2], 16) + ',' + parseInt(mm[3], 16) + ',' + a + ')';
    },
    _drawBlendOp() { return { normal: 'source-over', multiply: 'multiply', screen: 'screen', overlay: 'overlay', darken: 'darken', lighten: 'lighten' }[this.drawBlend] || 'source-over'; },
    _drawPoint(e) {
      const m = this.canvasMetrics();
      if (!m) return { nx: 0.5, ny: 0.5 };
      return { nx: this._clamp((e.clientX - m.crLeft - m.vx) / m.vw, 0, 1), ny: this._clamp((e.clientY - m.crTop - m.vy) / m.vh, 0, 1) };
    },
    _drawPaintDot(p) {
      const c = this._drawCtx; if (!c) return;
      const w = this._drawCanvas.width, h = this._drawCanvas.height, r = this._drawRadius();
      const op = Math.max(0.01, Math.min(1, (Number(this.drawOpacity) || 1) * (Number(this.drawFlow) || 1))); // opacity × flow
      const feather = Math.max(0, Math.min(1, (Number(this.drawSoftness) || 0) / 60)); // 0..1
      // Plateau (độ cứng) = hardness trừ bớt phần mềm; rồi GAUSS smooth ramp tới mép — KHÔNG có mid-stop
      // (mid-stop tạo vòng/banding). Hardness cao = mép sắc; thấp = mềm mượt.
      const core = Math.max(0.03, Math.min(0.98, (Number(this.drawHardness) || 80) / 100 * (1 - feather * 0.5)));
      const g = c.createRadialGradient(p.nx * w, p.ny * h, 0, p.nx * w, p.ny * h, r);
      const stops = [[0, op]];
      if (core > 0.05) stops.push([core, op]);
      stops.push([1, 0]);
      stops.forEach((s) => g.addColorStop(s[0], this._hexToRgba(this.inpaintFillColor, s[1])));
      c.save();
      c.globalCompositeOperation = this._drawBlendOp();
      c.fillStyle = g;
      c.beginPath(); c.arc(p.nx * w, p.ny * h, r, 0, Math.PI * 2); c.fill();
      c.restore();
    },
    _drawPaintLine(from, to) {
      const c = this._drawCtx; if (!c) return;
      const w = this._drawCanvas.width, r = this._drawRadius();
      const spacing = Math.max(0.5, r * 2 * Math.min(1, Math.max(0.03, Number(this.drawSpacing) || 0.15)));
      const dist = Math.hypot(to.nx - from.nx, to.ny - from.ny) * w;
      const steps = Math.max(1, Math.ceil(dist / spacing));
      for (let s = 0; s <= steps; s++) this._drawPaintDot({ nx: from.nx + (to.nx - from.nx) * (s / steps), ny: from.ny + (to.ny - from.ny) * (s / steps) });
    },
    beginDrawBrush(e) { if (!this.drawMode) return; if (e.currentTarget && e.currentTarget.setPointerCapture && e.pointerId != null) { try { e.currentTarget.setPointerCapture(e.pointerId); } catch (err) {} } this._drawPressure = (e.pointerType === 'pen' && e.pressure != null && e.pressure > 0) ? e.pressure : 1; this._drawDrawing = true; const p = this._drawPoint(e); this._drawSmooth = p; this._drawLast = p; this._drawCursor = { x: e.clientX, y: e.clientY }; this._drawHasStrokes = true; this._drawPaintDot(p); },
    drawBrushMove(e) { if (!this._drawDrawing) return; if (e.pointerType === 'pen' && e.pressure != null && e.pressure > 0) this._drawPressure = e.pressure; let p = this._drawPoint(e); const sm = Math.max(0, Math.min(100, Number(this.drawSmoothing) || 0)); if (sm > 0 && this._drawSmooth) { const k = Math.max(0.05, 1 - sm / 100); p = { nx: this._drawSmooth.nx + (p.nx - this._drawSmooth.nx) * k, ny: this._drawSmooth.ny + (p.ny - this._drawSmooth.ny) * k }; } this._drawSmooth = p; this._drawCursor = { x: e.clientX, y: e.clientY }; this._drawPaintLine(this._drawLast || p, p); this._drawLast = p; },
    endDrawBrush() { this._drawDrawing = false; this._drawLast = null; this._drawSmooth = null; this._drawCursor = null; },
    applyDraw() {
      if (this._drawBusy) return Promise.resolve();
      // Không có nét thật → không push history / không bake.
      if (!this._drawHasStrokes) return Promise.resolve();
      this.pushHistory();
      this._drawBusy = true;
      this._drawHasStrokes = false;
      return new Promise((resolve) => {
        const l = this.activeLayer;
        const dc = this._drawCanvas;
        if (!l || l.locked || !dc) {
          if (l && l.locked) this.toast('Layer đang khóa — mở khóa trước khi vẽ.', 'error');
          this._drawBusy = false;
          resolve();
          return;
        }
        const img = new Image();
        img.onload = () => {
          const w = img.naturalWidth, h = img.naturalHeight;
          const canvas = document.createElement('canvas'); canvas.width = w; canvas.height = h;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0);
          ctx.globalCompositeOperation = 'source-over';
          ctx.drawImage(dc, 0, 0, w, h);
          l.image = canvas.toDataURL('image/png');
          this.saveLayerLayout();
          this.toast('Đã vẽ lên layer.');
          this._drawBusy = false;
          resolve();
        };
        img.onerror = () => { this.toast('Không vẽ được.', 'error'); this._drawBusy = false; resolve(); };
        img.src = l.image;
      });
    },
    applyDrawNow() {
      this.applyDraw().then(() => {
        if (this._drawCtx && this._drawCanvas) this._drawCtx.clearRect(0, 0, this._drawCanvas.width, this._drawCanvas.height);
      });
    },
    finishDraw() { if (!this.drawMode) return; this.applyDraw().then(() => { this.drawMode = false; }); },
    cancelDraw() { if (!this.drawMode) return; this.drawMode = false; this.toast('Đã hủy vẽ.'); },
    exitErase() { if (this.eraseMode) { this.eraseMode = false; this.applyErase(); } },
    // "Thoát công cụ" thông minh: gọi khi CHUYỂN SANG TÁC VỤ KHÁC / THOÁT ẢNH TIÊU ĐIỂM
    // (đổi bước, mở trình xem ảnh, mở popup Prompt Tạo Ảnh, mở popup tải ảnh nguồn, bỏ chọn layer…).
    exitCanvasTools() {
      if (this.drawMode) this.finishDraw();
      if (this.eraseMode) this.exitErase();
      if (this.inpaintMaskMode !== 'none') this.clearInpaintMask();
      this.reframeOpen = false;
      this.cropMode = false;
      if (this._cropStop) this._cropStop(null);
      this.filmOpen = false;
      this.selectTool = false;
      this.panMode = false;
    },
};
