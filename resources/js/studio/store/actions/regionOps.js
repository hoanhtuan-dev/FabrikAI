// TÁCH TIẾP từ selection.js (đợt tối ưu 2026-09-24) — miền: thao tác vùng chọn (xoá/tô/nhân đôi/tách) + snapshot/undo/redo layer.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt bản gộp.
export const regionOpsActions = {
    // ── Hành động vùng chọn (Xóa / Tô màu) cho rect + freehand ──
    _loadImageSrc(src) {
      return new Promise((resolve, reject) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Không tải được ảnh.'));
        img.src = src;
      });
    },
    // Canvas alpha (trắng=vùng chọn, trong suốt=ngoài) theo kích thước layer, có feather.
    async _buildSelectionAlpha(w, h, maskData = null) {
      const sel = document.createElement('canvas'); sel.width = w; sel.height = h;
      const sctx = sel.getContext('2d');
      const mode = this.inpaintMaskMode;
      const data = maskData ?? this.inpaintBrushData;
      if (mode === 'rect' && !data) {
        const b = this.inpaintMaskBox;
        if (!b || b.w < 0.02 || b.h < 0.02) throw new Error('Chưa có vùng chọn.');
        sctx.fillStyle = '#fff';
        sctx.fillRect(b.x * w, b.y * h, b.w * w, b.h * h);
      } else {
        // freehand/brush/path/magic → mask PNG (đen=vùng chọn); rect đã ĐẢO cũng dùng mask.
        // Dùng maskData ĐÃ CAPTURE (ổn định, không đổi giữa chừng).
        if (!data) throw new Error('Chưa vẽ vùng chọn.');
        const mimg = await this._loadImageSrc('data:image/png;base64,' + data);
        const tmp = document.createElement('canvas'); tmp.width = w; tmp.height = h;
        const tctx = tmp.getContext('2d');
        tctx.drawImage(mimg, 0, 0, w, h);
        const id = tctx.getImageData(0, 0, w, h);
        const d = id.data;
        for (let i = 0; i < d.length; i += 4) {
          // Cứng hoá mép (ngưỡng) để Xóa/Tô/Nhân đôi/Nâng che khuất TRỌN vùng chọn —
          // bỏ viền mờ do anti-alias (mép bán trong suốt làm màu tô lộ ảnh nền một ít).
          const sel = 255 - d[i];
          d[i] = 255; d[i + 1] = 255; d[i + 2] = 255; d[i + 3] = sel >= 128 ? 255 : 0;
        }
        sctx.putImageData(id, 0, 0);
      }
      const feather = Number(this.inpaintFeather) || 0;
      if (feather > 0) {
        const blurred = document.createElement('canvas'); blurred.width = w; blurred.height = h;
        const bctx = blurred.getContext('2d');
        bctx.filter = `blur(${feather}px)`;
        bctx.drawImage(sel, 0, 0);
        return blurred;
      }
      return sel;
    },
    async _applySelectionToLayer(action, color) {
      const l = this.activeLayer;
      if (!l || !l.image) { this.toast('Chọn 1 layer ảnh trước.', 'error'); return; }
      if (l.locked) { this.toast('Layer đang khóa — mở khóa trước khi xóa/tô vùng chọn.', 'error'); return; }
      const maskData = this.inpaintBrushData; // capture đồng bộ
      this.pushHistory();
      try {
        const img = await this._loadImageSrc(l.image);
        const w = img.naturalWidth, h = img.naturalHeight;
        const sel = await this._buildSelectionAlpha(w, h, maskData);
        const out = document.createElement('canvas'); out.width = w; out.height = h;
        const octx = out.getContext('2d');
        octx.drawImage(img, 0, 0);
        if (action === 'delete') {
          octx.globalCompositeOperation = 'destination-out';
          octx.drawImage(sel, 0, 0);
        } else {
          const colorCanvas = document.createElement('canvas'); colorCanvas.width = w; colorCanvas.height = h;
          const cctx = colorCanvas.getContext('2d');
          cctx.fillStyle = color || this.inpaintFillColor;
          cctx.fillRect(0, 0, w, h);
          cctx.globalCompositeOperation = 'destination-in';
          cctx.drawImage(sel, 0, 0);
          octx.drawImage(colorCanvas, 0, 0);
        }
        l.image = out.toDataURL('image/png');
        this.saveLayerLayout();
        this.toast(action === 'delete' ? 'Đã xóa nội dung vùng chọn.' : 'Đã tô màu vùng chọn.');
      } catch (e) {
        this.failToast(e, 'Không áp dụng được.');
      }
    },
    deleteSelectedRegion() { this._applySelectionToLayer('delete'); },
    fillSelectedRegion() { this._applySelectionToLayer('fill'); },
    // Nhân đôi vùng chọn (rect/freehand) thành 1 layer mới chứa đúng phần được chọn.
    async duplicateSelectedRegion() {
      const src = this.activeLayer;
      if (!src || !src.image) { this.toast('Chọn 1 layer ảnh trước.', 'error'); return; }
      if (src.locked) { this.toast('Layer đang khóa — mở khóa trước khi nhân đôi vùng chọn.', 'error'); return; }
      const maskData = this.inpaintBrushData; // capture đồng bộ
      this.pushHistory();
      try {
        const img = await this._loadImageSrc(src.image);
        const w = img.naturalWidth, h = img.naturalHeight;
        const sel = await this._buildSelectionAlpha(w, h, maskData);
        const out = document.createElement('canvas'); out.width = w; out.height = h;
        const octx = out.getContext('2d');
        octx.drawImage(img, 0, 0);
        octx.globalCompositeOperation = 'destination-in';
        octx.drawImage(sel, 0, 0); // giữ đúng pixel trong vùng chọn, ngoài trong suốt
        const url = out.toDataURL('image/png');
        const id = 'dup-' + Date.now();
        // Nhân đôi NGAY tại vị trí vùng chọn: cùng transform với layer gốc (chồng khít, dễ kéo đi).
        this.canvasLayers.push({
          id, kind: 'source', name: 'Nhân đôi vùng chọn', image: url, genId: null,
          visible: true, locked: false,
          x: src.x || 0, y: src.y || 0, scale: src.scale || 1, rotation: src.rotation || 0,
          opacity: src.opacity != null ? src.opacity : 1, blend: src.blend || 'normal', flipX: false, flipY: false,
          baseW: src.baseW, baseH: src.baseH,
        });
        this.saveLayerLayout();
        this.setActiveLayer(id);
        this.toast('Đã nhân đôi vùng chọn tại vị trí.');
      } catch (e) {
        this.failToast(e, 'Không nhân đôi được.');
      }
    },
    // Nâng (float/cut) vùng chọn: cắt nội dung khỏi layer gốc → đưa lên layer mới chồng khít để kéo đi.
    async floatSelectedRegion() {
      const src = this.activeLayer;
      if (!src || !src.image) { this.toast('Chọn 1 layer ảnh trước.', 'error'); return; }
      if (src.locked) { this.toast('Layer đang khóa — mở khóa trước khi nâng vùng chọn.', 'error'); return; }
      const maskData = this.inpaintBrushData; // capture đồng bộ
      this.pushHistory();
      try {
        const img = await this._loadImageSrc(src.image);
        const w = img.naturalWidth, h = img.naturalHeight;
        const sel = await this._buildSelectionAlpha(w, h, maskData);
        // 1) Layer mới chứa đúng phần chọn (floating)
        const fc = document.createElement('canvas'); fc.width = w; fc.height = h;
        const fctx = fc.getContext('2d');
        fctx.drawImage(img, 0, 0);
        fctx.globalCompositeOperation = 'destination-in';
        fctx.drawImage(sel, 0, 0);
        const floatUrl = fc.toDataURL('image/png');
        // 2) Cắt phần chọn khỏi layer gốc
        const cc = document.createElement('canvas'); cc.width = w; cc.height = h;
        const cctx = cc.getContext('2d');
        cctx.drawImage(img, 0, 0);
        cctx.globalCompositeOperation = 'destination-out';
        cctx.drawImage(sel, 0, 0);
        src.image = cc.toDataURL('image/png');
        // 3) Thêm layer floating trùng vị trí gốc
        const id = 'float-' + Date.now();
        this.canvasLayers.push({
          id, kind: 'source', name: 'Nâng vùng chọn', image: floatUrl, genId: null,
          visible: true, locked: false,
          x: src.x || 0, y: src.y || 0, scale: src.scale || 1, rotation: src.rotation || 0,
          opacity: src.opacity != null ? src.opacity : 1, blend: src.blend || 'normal', flipX: false, flipY: false,
          baseW: src.baseW, baseH: src.baseH,
        });
        this.saveLayerLayout();
        this.setActiveLayer(id);
        this.toast('Đã nâng vùng chọn thành layer mới — kéo để di chuyển.');
      } catch (e) {
        this.failToast(e, 'Không nâng được.');
      }
    },
    // ── Undo / Redo (lịch sử layer) ──
    _snapshot() { return { layers: this.canvasLayers.map((l) => ({ ...l })), activeLayerId: this.activeLayerId }; },
    pushHistory() {
      this.undoStack.push(this._snapshot());
      if (this.undoStack.length > 50) this.undoStack.shift();
      this.redoStack = [];
    },
    _restoreSnapshot(snap) {
      this.canvasLayers = snap.layers.map((l) => ({ ...l }));
      this.activeLayerId = snap.activeLayerId;
      const l = this.activeLayer;
      if (l) {
        if (l.kind === 'source') { this.editSource = { url: l.image, name: l.name }; this.previewId = null; this.preview = null; }
        else if (l.genId) {
          const g = this.generations.find((x) => x.id === l.genId);
          if (g) { this.previewId = g.id; this.preview = { id: g.id, media_url: g.media_url, type: g.type || 'image', status: g.status || 'completed' }; }
          this.editSource = null;
        } else { this.editSource = null; this.previewId = null; this.preview = null; }
      } else { this.editSource = null; this.previewId = null; this.preview = null; }
      this.saveLayerLayout();
    },
    undo() {
      const snap = this.undoStack.pop();
      if (!snap) { this.toast('Không còn thao tác để hoàn tác.', 'info'); return; }
      this.redoStack.push(this._snapshot());
      this._restoreSnapshot(snap);
      this.toast('Đã hoàn tác.');
    },
    redo() {
      const snap = this.redoStack.pop();
      if (!snap) { this.toast('Không còn thao tác để làm lại.', 'info'); return; }
      this.undoStack.push(this._snapshot());
      this._restoreSnapshot(snap);
      this.toast('Đã làm lại.');
    },
};
