// TÁCH TIẾP từ selection.js (đợt tối ưu 2026-09-24) — miền: magic wand · đảo vùng · drag/brush mask inpaint.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt bản gộp.
import { markRaw } from 'vue';
import { maskVeil } from '../overlayTokens.js';
export const maskBrushActions = {
    // ── Magic Wand: click chọn vùng theo màu tương tự (flood-fill theo ngưỡng) ──
    async magicWand(e) {
      if (this.inpaintMaskMode !== 'magic') return;
      e.stopPropagation();
      const p = this.inpaintMaskPointer(e); if (!p) return;
      const l = this.activeLayer;
      if (!l || !l.image) { this.toast('Chọn 1 layer ảnh để dùng Magic Wand.', 'error'); return; }
      const tol = Math.max(1, Math.min(128, Number(this.magicTolerance) || 32));
      try {
        const img = await this._loadImageSrc(l.image);
        const w = img.naturalWidth, h = img.naturalHeight;
        // Độ chính xác: flood-fill ở 1024 (gấp đôi 512) rồi thu nhỏ về mask canvas
        // → mép vùng chọn được anti-alias (sub-pixel), bớt răng cưa khi phóng to ảnh.
        const base = 1024, ia = w / h;
        const mw = ia >= 1 ? base : Math.max(1, Math.round(base * ia));
        const mh = ia >= 1 ? Math.max(1, Math.round(base / ia)) : base;
        const c = document.createElement('canvas'); c.width = mw; c.height = mh;
        const ctx = c.getContext('2d');
        ctx.drawImage(img, 0, 0, mw, mh);
        const id = ctx.getImageData(0, 0, mw, mh);
        const d = id.data;
        const sx = Math.min(mw - 1, Math.max(0, Math.round(p.nx * mw)));
        const sy = Math.min(mh - 1, Math.max(0, Math.round(p.ny * mh)));
        const si = (sy * mw + sx) * 4;
        const sr = d[si], sg = d[si + 1], sb = d[si + 2];
        // Flood-fill (stack/DFS) theo ngưỡng màu — EUCLIDEAN distance (chính xác hơn Manhattan).
        const visited = new Uint8Array(mw * mh);
        const stack = [sy * mw + sx];
        visited[sy * mw + sx] = 1;
        const threshSq = tol * tol;
        while (stack.length) {
          const idx = stack.pop();
          const x = idx % mw, y = (idx / mw) | 0;
          const neighbors = y > 0 ? [idx - mw] : [];
          if (y < mh - 1) neighbors.push(idx + mw);
          if (x > 0) neighbors.push(idx - 1);
          if (x < mw - 1) neighbors.push(idx + 1);
          for (const ni of neighbors) {
            if (visited[ni]) continue;
            const pi = ni * 4;
            const dr = d[pi] - sr, dg = d[pi + 1] - sg, db = d[pi + 2] - sb;
            if (dr * dr + dg * dg + db * db <= threshSq) { visited[ni] = 1; stack.push(ni); }
          }
        }
        // Tạo temp canvas chứa vùng chọn (đỏ), rồi vẽ lên mask canvas theo add/subtract.
        if (!this._inpaintMaskCtx) this._initInpaintBrush();
        const mc = this._inpaintMaskCanvas, mctx = this._inpaintMaskCtx;
        if (!mc || !mctx) return;
        if (this.inpaintSelectMode === 'new') { mctx.clearRect(0, 0, mc.width, mc.height); this.inpaintPathRegions = []; }
        const temp = document.createElement('canvas'); temp.width = mw; temp.height = mh;
        const tctx = temp.getContext('2d');
        const td = tctx.createImageData(mw, mh);
        for (let i = 0; i < mw * mh; i++) {
          if (visited[i]) { td.data[i * 4] = 220; td.data[i * 4 + 1] = 38; td.data[i * 4 + 2] = 38; td.data[i * 4 + 3] = 255; }
        }
        tctx.putImageData(td, 0, 0);
        // Độ mịn: blur mép vùng chọn → mask có viền mềm, chống răng cưa.
        // Nhân 2 vì xử lý ở 1024 (gấp đôi mask 512) → giá trị slider giữ nguyên cảm giác cũ.
        let selCanvas = temp;
        const feather = Math.max(0, Math.min(20, Number(this.magicFeather) || 0)) * 2;
        if (feather > 0) {
          const blurred = document.createElement('canvas'); blurred.width = mw; blurred.height = mh;
          const bctx = blurred.getContext('2d');
          bctx.filter = 'blur(' + feather + 'px)';
          bctx.drawImage(temp, 0, 0);
          selCanvas = blurred;
        }
        mctx.globalCompositeOperation = this.inpaintSelectMode === 'subtract' ? 'destination-out' : 'source-over';
        // Thu 1024 → kích thước mask canvas (512) — drawImage resize cho anti-alias mép.
        mctx.drawImage(selCanvas, 0, 0, mc.width, mc.height);
        mctx.globalCompositeOperation = 'source-over';
        this._finalizeInpaintBrush();
        if (this.inpaintSelectMode === 'new') this.inpaintSelectMode = 'add';
        this.toast('Đã chọn vùng theo màu.');
      } catch (err) { this.toast('Không chọn được vùng.', 'error'); }
    },
    // ── Đảo ngược vùng chọn (invert selection) ──
    async invertSelection() {
      const mode = this.inpaintMaskMode;
      if (mode === 'none') { this.toast('Chưa có vùng chọn để đảo.', 'error'); return; }
      if (mode === 'rect' && !this.inpaintBrushData) {
        const b = this.inpaintMaskBox;
        if (!b || b.w < 0.02 || b.h < 0.02) { this.toast('Chưa có vùng chọn.', 'error'); return; }
        if (!this._inpaintMaskCtx) this._initInpaintBrush();
        const c = this._inpaintMaskCanvas, ctx = this._inpaintMaskCtx;
        if (!c || !ctx) return;
        const w = c.width, h = c.height;
        ctx.clearRect(0, 0, w, h);
        ctx.fillStyle = maskVeil();
        ctx.fillRect(b.x * w, b.y * h, b.w * w, b.h * h);
        this._finalizeInpaintBrush();
        this._inpaintMaskKind = 'brush'; // rect → mask để đảo
      }
      if (!this.inpaintBrushData) { this.toast('Chưa có vùng chọn.', 'error'); return; }
      try {
        const mimg = await this._loadImageSrc('data:image/png;base64,' + this.inpaintBrushData);
        const w = mimg.naturalWidth, h = mimg.naturalHeight;
        const canvas = document.createElement('canvas'); canvas.width = w; canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(mimg, 0, 0);
        const id = ctx.getImageData(0, 0, w, h);
        const d = id.data;
        for (let i = 0; i < d.length; i += 4) {
          const v = 255 - d[i]; // đảo đen↔trắng
          d[i] = v; d[i + 1] = v; d[i + 2] = v; d[i + 3] = 255;
        }
        ctx.putImageData(id, 0, 0);
        this.inpaintBrushData = canvas.toDataURL('image/png').replace(/^data:image\/png;base64,/, '');
        // Đồng bộ overlay mask canvas (đỏ) theo mask đã đảo → add/subtract/vẽ tiếp vẫn đúng.
        const mc = this._inpaintMaskCanvas, mctx = this._inpaintMaskCtx;
        if (mc && mctx && mc.width === w && mc.height === h) {
          mctx.globalCompositeOperation = 'source-over';
          mctx.clearRect(0, 0, w, h);
          const rd = mctx.createImageData(w, h);
          for (let i = 0; i < w * h; i++) {
            const v = d[i * 4]; // 0=vùng chọn, 255=ngoài
            rd.data[i * 4] = 220; rd.data[i * 4 + 1] = 38; rd.data[i * 4 + 2] = 38;
            rd.data[i * 4 + 3] = Math.round((255 - v) * (153 / 255)); // đỏ theo độ chọn
          }
          mctx.putImageData(rd, 0, 0);
        }
        this.toast('Đã đảo ngược vùng chọn.');
      } catch (err) { this.toast('Không đảo được vùng chọn.', 'error'); }
    },
    inpaintMaskPointer(e) {
      const m = this.canvasMetrics();
      if (!m) return null;
      // Convert clientX/Y to normalized coords (0..1) on the visible image, accounting for zoom/pan
      const nx = this._clamp((e.clientX - m.crLeft - m.vx) / m.vw, 0, 1);
      const ny = this._clamp((e.clientY - m.crTop - m.vy) / m.vh, 0, 1);
      return { nx, ny };
    },
    // Bắt đầu kéo 1 thao tác với KEY TƯỜNG MINH — gọi từ.stop trên chính box/handle
    // (giống hệt crop: mỗi vùng biết mình là gì, KHÔNG hit-test, không bao giờ nhầm
    // 'resize' ↔ 'move', và không vô tình tạo vùng mới khi bấm lệch ra ngoài).
    beginInpaintDrag(key, e) {
      if (this.inpaintMaskMode === 'none') return;
      e.stopPropagation();
      const p = this.inpaintMaskPointer(e); if (!p) return;
      // Drag cũ còn dở → đóng sạch trước.
      if (this._inpaintDrag) this._inpaintStopDrag();
      const handlers = { move: (ev) => this._inpaintQueue(ev), up: () => this._inpaintStopDrag() };
      if (key === 'brush') {
        this._inpaintBrushDrawing = true;
        this._inpaintDrag = { key: 'brush', sx: e.clientX, sy: e.clientY, last: p, handlers };
        // Snapshot để Ctrl+Z hoàn tác từng nét (giới hạn 30 bước).
        if (this._inpaintMaskCanvas && this._inpaintMaskCtx) {
          const snap = this._inpaintMaskCtx.getImageData(0, 0, this._inpaintMaskCanvas.width, this._inpaintMaskCanvas.height);
          this._inpaintUndoStack.push(snap);
          if (this._inpaintUndoStack.length > 30) this._inpaintUndoStack.shift();
        }
        this._drawInpaintBrushDot(p);
      } else if (key === 'draw') {
        // Kéo tạo vùng mới từ điểm nhấn. Lưu box cũ để khôi phục nếu chỉ click nhầm.
        const prev = this.inpaintMaskBox;
        this._inpaintPrevBox = (prev && prev.w >= 0.02 && prev.h >= 0.02) ? { ...prev } : null;
        this._inpaintDrew = false;
        this._inpaintDrag = { key: 'draw', sx: e.clientX, sy: e.clientY, box: { x: p.nx, y: p.ny, w: 0, h: 0 }, handlers };
        this.inpaintMaskBox = { x: p.nx, y: p.ny, w: 0, h: 0 };
      } else {
        // 'move' hoặc 1 trong 'nw'/'ne'/'sw'/'se' — bám vào box hiện tại
        const b = { ...(this.inpaintMaskBox || { x: 0, y: 0, w: 0, h: 0 }) };
        this._inpaintDrag = { key, sx: e.clientX, sy: e.clientY, box: b, handlers };
      }
      window.addEventListener('pointermove', handlers.move);
      window.addEventListener('pointerup', handlers.up);
      window.addEventListener('pointercancel', handlers.up);
    },
    // Container overlay: bấm ngoài box → bắt đầu vẽ vùng mới. Box cũ được lưu lại
    // (_inpaintPrevBox); nếu chỉ click nhầm (không kéo) thì khôi phục — không mất vùng.
    inpaintMaskStart(e) {
      if (this.inpaintMaskMode === 'none') return;
      this.beginInpaintDrag(this.inpaintMaskMode === 'brush' ? 'brush' : 'draw', e);
    },
    // Reset về chế độ vẽ vùng mới (gọi từ nút " Vẽ lại" hoặc double-click trên box)
    resetInpaintMaskBox() {
      if (this._inpaintDrag) this._inpaintStopDrag();
      this.inpaintMaskBox = { x: 0, y: 0, w: 0, h: 0 };
      this._inpaintPrevBox = null;
      this._inpaintDrew = false;
      this.toast?.('Kéo chọn vùng mới trên ảnh.');
    },

    // Batch pointermoves qua rAF — kéo không giật, không đọc layout mỗi event.
    _inpaintQueue(e) {
      if (!this._inpaintDrag) return;
      this._inpaintPending = e;
      if (this._inpaintRaf) return;
      const flush = () => {
        this._inpaintRaf = null;
        const ev = this._inpaintPending; this._inpaintPending = null;
        if (ev && this._inpaintDrag) this._inpaintDragMove(ev);
      };
      if (typeof requestAnimationFrame === 'function') this._inpaintRaf = requestAnimationFrame(flush);
      else flush();
    },
    _inpaintDragMove(e) {
      const d = this._inpaintDrag; if (!d) return;
      const m = this.canvasMetrics(); if (!m) return;
      // Đánh dấu đã kéo thật (ngưỡng 4px) — phân biệt với click nhầm
      if (d.key === 'draw' && (Math.abs(e.clientX - d.sx) > 4 || Math.abs(e.clientY - d.sy) > 4)) {
        this._inpaintDrew = true;
      }
      if (d.key === 'brush') {
        const p = this.inpaintMaskPointer(e); if (!p) return;
        this._drawInpaintBrushLine(d.last || p, p);
        d.last = p;
        return;
      }
      const bx = (e.clientX - d.sx) / m.vw, by = (e.clientY - d.sy) / m.vh;
      const b = { ...(d.box || { x: 0.425, y: 0.425, w: 0.15, h: 0.15 }) };
      const MIN = 0.02;
      const cl = (v, lo, hi) => this._clamp(v, lo, Math.max(lo, hi));
      if (d.key === 'move') {
        b.x = this._clamp(b.x + bx, 0, 1 - b.w);
        b.y = this._clamp(b.y + by, 0, 1 - b.h);
      } else if (d.key === 'draw') {
        const ox = d.box.x, oy = d.box.y;
        const cx = this._clamp(ox + bx, 0, 1), cy = this._clamp(oy + by, 0, 1);
        b.x = Math.min(ox, cx); b.y = Math.min(oy, cy);
        b.w = Math.max(MIN, Math.abs(cx - ox));
        b.h = Math.max(MIN, Math.abs(cy - oy));
      } else {
        // Kéo handle: góc đối diện neo cố định
        const { x: x0, y: y0, w: w0, h: h0 } = d.box;
        const right = x0 + w0, bottom = y0 + h0;
        let x = x0, y = y0, w = w0, h = h0;
        if (d.key === 'se') { w = cl(w0 + bx, MIN, 1 - x0); h = cl(h0 + by, MIN, 1 - y0); }
        else if (d.key === 'sw') { w = cl(w0 - bx, MIN, right); x = right - w; h = cl(h0 + by, MIN, 1 - y0); }
        else if (d.key === 'ne') { w = cl(w0 + bx, MIN, 1 - x0); h = cl(h0 - by, MIN, bottom); y = bottom - h; }
        else if (d.key === 'nw') { w = cl(w0 - bx, MIN, right); x = right - w; h = cl(h0 - by, MIN, bottom); y = bottom - h; }
        b.x = x; b.y = y; b.w = w; b.h = h;
      }
      this.inpaintMaskBox = b;
    },
    // Kết thúc drag: gỡ window listeners, finalize brush, reset nếu vùng quá nhỏ.
    _inpaintStopDrag() {
      const d = this._inpaintDrag;
      this._inpaintDrag = null;
      this._inpaintHandle = null;
      this._inpaintPending = null;
      if (this._inpaintRaf != null) {
        if (typeof cancelAnimationFrame === 'function') cancelAnimationFrame(this._inpaintRaf);
        this._inpaintRaf = null;
      }
      if (d && d.handlers) {
        window.removeEventListener('pointermove', d.handlers.move);
        window.removeEventListener('pointerup', d.handlers.up);
        window.removeEventListener('pointercancel', d.handlers.up);
      }
      if (this._inpaintBrushDrawing) {
        this._inpaintBrushDrawing = false;
        this._inpaintBrushLast = null;
        this._finalizeInpaintBrush();
      } else if (this.inpaintMaskMode === 'rect') {
        const wasDraw = d && d.key === 'draw';
        const b = this.inpaintMaskBox;
        if (wasDraw && !this._inpaintDrew && this._inpaintPrevBox) {
          // Bấm ngoài box nhưng KHÔNG kéo (click nhầm) → khôi phục vùng cũ
          this.inpaintMaskBox = this._inpaintPrevBox;
        } else if (!b || b.w < 0.02 || b.h < 0.02) {
          // Vùng quá nhỏ và không có gì để khôi phục → reset trống
          this.inpaintMaskBox = { x: 0, y: 0, w: 0, h: 0 };
        }
      }
      this._inpaintPrevBox = null;
      this._inpaintDrew = false;
    },
    // Alias cho pointerup fallback nếu template vẫn gọi
    inpaintMaskStop() { this._inpaintStopDrag(); },
    _inpaintHitTest(p, b) {
      if (!b || b.w < 0.02 || b.h < 0.02) return null;
      // Margin bắt handle theo PIXEL thật trên màn hình (≈ 26px quanh góc) —
      // không phải % cố định → dễ bắt góc dù ảnh nhỏ hay zoom xa.
      const m = this.canvasMetrics();
      const M = m ? Math.max(0.025, 26 / m.vw) : 0.06;
      const corners = [['nw', b.x, b.y], ['ne', b.x + b.w, b.y], ['sw', b.x, b.y + b.h], ['se', b.x + b.w, b.y + b.h]];
      for (const [k, cx, cy] of corners) { if (Math.abs(p.nx - cx) <= M && Math.abs(p.ny - cy) <= M) return k; }
      if (p.nx >= b.x && p.nx <= b.x + b.w && p.ny >= b.y && p.ny <= b.y + b.h) return 'move';
      return null;
    },
    _initInpaintBrush() {
      // Canvas theo TỈ LỆ KHUNG ẢNH LAYER (frameLayout = baseW/baseH) — khớp hệ toạ độ nx/ny mà con trỏ
      // dùng để vẽ (tránh mask bị ép méo/tỷ lệ sai khi hiển thị hoặc tô màu). Vuông 512×512 chỉ là mặc định.
      const fl = this.frameLayout;
      let w = 512, h = 512;
      if (fl && fl.w && fl.h) {
        // Dùng ĐÚNG kích thước khung layer (baseW/baseH) — 1 px mask = 1 px khung vẽ → vùng chọn & tô màu khớp 100%.
        const cap = 1024;
        const s = Math.min(1, cap / fl.w, cap / fl.h);
        w = Math.max(1, Math.round(fl.w * s));
        h = Math.max(1, Math.round(fl.h * s));
      }
      const c = document.createElement('canvas');
      c.width = w; c.height = h;
      this._inpaintMaskCanvas = c;
      // willReadFrequently: _finalizeInpaintBrush đọc lại (getImageData) sau mỗi nét → tránh cảnh báo + nhanh hơn.
      this._inpaintMaskCtx = c.getContext('2d', { willReadFrequently: true });
      this._inpaintUndoStack = [];
    },
    // Ctrl+Z: khôi phục canvas về trước nét vẽ gần nhất, rồi snapshot lại mask data.
    undoInpaintBrush() {
      if (this.inpaintMaskMode !== 'brush') return;
      const c = this._inpaintMaskCtx; if (!c) return;
      const snap = this._inpaintUndoStack.pop();
      if (!snap) { this.toast('Không còn nét để hoàn tác.', 'info'); return; }
      const w = this._inpaintMaskCanvas.width, h = this._inpaintMaskCanvas.height;
      c.globalCompositeOperation = 'source-over';
      c.clearRect(0, 0, w, h);
      c.putImageData(snap, 0, 0);
      this._finalizeInpaintBrush();
      this.toast('Đã hoàn tác nét vẽ.');
    },
    // Gắn canvas DOM thật (overlay trên ảnh) làm nơi vẽ mask → nét vẽ HIỂN THỊ realtime.
    // Khi tắt brush (el = null) → gỡ tham chiếu để không vẽ vào element đã unmount.
    attachBrushCanvas(el) {
      if (!el) { this._inpaintMaskCanvas = null; this._inpaintMaskCtx = null; return; }
      const c = markRaw(el);
      const ctx = c.getContext('2d', { willReadFrequently: true });
      ctx.clearRect(0, 0, c.width, c.height);
      this._inpaintMaskCanvas = c;
      this._inpaintMaskCtx = ctx;
    },
    _inpaintBrushRadius() { const s = Math.max(2, Math.min(48, Number(this.inpaintBrushSize) || 10)); return this.inpaintErase ? s * 1.6 : s; },
    _inpaintBrushWidth() { return this._inpaintBrushRadius() * 2; },
    _drawInpaintBrushDot(p) {
      const c = this._inpaintMaskCtx; if (!c) return;
      const w = this._inpaintMaskCanvas.width, h = this._inpaintMaskCanvas.height;
      // Tẩy: destination-out xoá hẳn pixel (cả nét vẽ lẫn mask) — sửa khi vẽ lỡ.
      const erase = !!this.inpaintErase;
      c.globalCompositeOperation = erase ? 'destination-out' : 'source-over';
      c.fillStyle = erase ? 'rgba(0,0,0,1)' : maskVeil(); // đỏ 60% — rõ mà không che ảnh
      c.beginPath(); c.arc(p.nx * w, p.ny * h, this._inpaintBrushRadius(), 0, Math.PI * 2); c.fill();
      c.globalCompositeOperation = 'source-over';
    },
    _drawInpaintBrushLine(from, to) {
      const c = this._inpaintMaskCtx; if (!c) return;
      const w = this._inpaintMaskCanvas.width, h = this._inpaintMaskCanvas.height;
      const erase = !!this.inpaintErase;
      c.globalCompositeOperation = erase ? 'destination-out' : 'source-over';
      c.strokeStyle = erase ? 'rgba(0,0,0,1)' : maskVeil();
      c.lineWidth = this._inpaintBrushWidth(); c.lineCap = 'round'; c.lineJoin = 'round';
      c.beginPath(); c.moveTo(from.nx * w, from.ny * h); c.lineTo(to.nx * w, to.ny * h); c.stroke();
      c.globalCompositeOperation = 'source-over';
    },
    _finalizeInpaintBrush() {
      const el = this._inpaintMaskCanvas; if (!el || !this._inpaintMaskCtx) return;
      const w = el.width, h = el.height;
      const ctx = this._inpaintMaskCtx;
      const src = ctx.getImageData(0, 0, w, h);
      const mask = document.createElement('canvas'); mask.width = w; mask.height = h;
      const mctx = mask.getContext('2d', { willReadFrequently: true });
      const out = mctx.createImageData(w, h);
      for (let i = 0; i < w * h; i++) {
        // Grayscale mask chống răng cưa: nét đỏ 60% (alpha≈153) chuẩn hoá → đen(0=edit),
        // mép anti-aliased cho gray chuyển mềm, nền chưa vẽ → trắng(255=keep).
        const a = src.data[i * 4 + 3];
        const v = 255 - Math.min(255, Math.round(a * (255 / 153)));
        out.data[i * 4] = v; out.data[i * 4 + 1] = v; out.data[i * 4 + 2] = v; out.data[i * 4 + 3] = 255;
      }
      mctx.putImageData(out, 0, 0);
      this.inpaintBrushData = mask.toDataURL('image/png').replace(/^data:image\/png;base64,/, '');
      let minX = w, minY = h, maxX = 0, maxY = 0, found = false;
      for (let y = 0; y < h; y++) { for (let x = 0; x < w; x++) {
        if (src.data[(y * w + x) * 4 + 3] > 40) { found = true; if (x < minX) minX = x; if (x > maxX) maxX = x; if (y < minY) minY = y; if (y > maxY) maxY = y; }
      }}
      if (found && minX <= maxX && minY <= maxY) this.inpaintMaskBox = { x: minX / w, y: minY / h, w: (maxX - minX + 1) / w, h: (maxY - minY + 1) / h };
      // KHÔNG null canvas: giữ nét để vẽ TIẾP nhiều nét trên cùng mask (finalize chỉ snapshot
      // dữ liệu). Canvas bị xoá khi bật brush mới / thoát chế độ (attachBrushCanvas clearRect).
    },
};
