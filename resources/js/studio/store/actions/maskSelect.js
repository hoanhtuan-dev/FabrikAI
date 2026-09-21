// TÁCH TIẾP từ selection.js (đợt tối ưu 2026-09-24) — miền: vùng chọn rect + freehand (lasso) để tạo mask.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt bản gộp.
export const maskSelectActions = {
    confirmInpaintMask() {
      if (this.inpaintMaskMode === 'brush') {
        if (this._inpaintDrag) this._inpaintStopDrag(); // đang kéo dở → finalize trước
        if (!this.inpaintBrushData) { this.toast('Chưa vẽ mask — vẽ vùng cần sửa trước.', 'error'); return; }
        this._inpaintMaskKind = 'brush';
      } else if (this.inpaintMaskMode === 'rect') {
        // Rect đã ĐẢO (có mask_data) → gửi như brush mask, không dùng box nữa.
        this._inpaintMaskKind = this.inpaintBrushData ? 'brush' : 'rect';
      } else if (this.inpaintMaskMode === 'freehand') {
        if (this._inpaintFreehandActive) { this.freehandStop(); }
        if (!this.inpaintBrushData) { this.toast('Chưa vẽ vùng chọn — vẽ tự do để khoanh vùng.', 'error'); return; }
        this._inpaintMaskKind = 'brush'; // freehand tạo mask_data như brush
      } else if (this.inpaintMaskMode === 'path') {
        if (!this.inpaintBrushData) { this.toast('Chưa đóng vùng chọn — thêm điểm rồi bấm "Đóng".', 'error'); return; }
        this._inpaintMaskKind = 'brush'; // path tạo mask_data như brush
      } else if (this.inpaintMaskMode === 'magic') {
        if (!this.inpaintBrushData) { this.toast('Chưa chọn vùng — bấm vào ảnh để chọn theo màu.', 'error'); return; }
        this._inpaintMaskKind = 'brush'; // magic tạo mask_data như brush
      } else {
        return;
      }
      this._inpaintStopDrag && this._inpaintStopDrag();
      this.inpaintMaskMode = 'none'; // tắt overlay
      this.inpaintErase = false;
      this.inpaintFreehandPoints = [];
      this.inpaintFreehandPaths = [];
      this.inpaintPathPoints = [];
      this.inpaintPathRegions = [];
      this._pathEditingRegion = -1;
      this._pathHoverRegion = -1;
      if (this.inpaintMaskSource === 'canvas') {
        // Vùng chọn trên canvas: chỉ thoát + xoá dữ liệu, không giữ làm mask inpaint.
        this.inpaintMaskDone = false;
        this._inpaintMaskKind = '';
        this.inpaintBrushData = '';
        this.inpaintMaskSource = 'inpaint';
        this.toast('Đã thoát vùng chọn.');
      } else {
        this.inpaintMaskDone = true;
        this.toast('Đã lưu vùng — bấm "Sửa ảnh" để xử lý.');
      }
    },
    // "Bỏ mask": xoá hoàn toàn vùng chọn/vẽ (rect + brush).
    clearInpaintMask() {
      this._inpaintStopDrag && this._inpaintStopDrag();
      this.inpaintMaskMode = 'none';
      this.inpaintMaskDone = false;
      this._inpaintMaskKind = '';
      this.inpaintBrushData = '';
      this.inpaintErase = false;
      this.inpaintMaskSource = 'inpaint';
      this.inpaintFreehandPoints = [];
      this.inpaintFreehandPaths = [];
      this.inpaintPathPoints = [];
      this.inpaintPathRegions = [];
      this._pathEditingRegion = -1;
      this._pathHoverRegion = -1;
      this.inpaintMaskBox = { x: 0.425, y: 0.425, w: 0.15, h: 0.15 };
    },
    async toggleInpaintMask(mode) {
      if (this.inpaintMaskMode === mode) { this.clearInpaintMask(); return; }
      if (this.inpaintStage === 'send' || this.inpaintStage === 'processing') { this.toast('Đang xử lý — chờ xong rồi chọn vùng.', 'error'); return; }
      // Vẽ mask trên layer bị xoay/lật → vùng chọn lệch khỏi nội dung hiển thị; chuẩn hoá trước.
      if (!(await this.flattenActiveLayerTransform())) return;
      if (this._inpaintDrag) this._inpaintStopDrag();
      this.inpaintMaskSource = 'inpaint';
      this.inpaintMaskMode = mode;
      this.inpaintMaskDone = false;
      this._inpaintMaskKind = '';
      this.inpaintBrushData = '';
      this.inpaintMaskBox = { x: 0.425, y: 0.425, w: 0.15, h: 0.15 }; // khung mặc định 15% khi bật — nhỏ, dễ kéo/chỉnh
      if (mode === 'brush') { this._initInpaintBrush(); this.inpaintErase = false; }
      if (mode === 'freehand') { this.inpaintFreehandPoints = []; this.inpaintFreehandPaths = []; this._initInpaintBrush(); }
      if (mode === 'path') { this.inpaintPathPoints = []; this.inpaintPathRegions = []; this.inpaintPathCloseHover = false; this._pathEditingRegion = -1; this._pathHoverRegion = -1; this._initInpaintBrush(); }
      if (mode === 'magic') { this._initInpaintBrush(); }
    },
    // Mở vùng chọn từ THANH CÔNG CỤ CANVAS (rect/freehand) — dùng chung overlay chính xác của Inpaint,
    // nhưng hành động là Xóa / Tô màu / Feather tại chỗ (không phải mask AI inpaint).
    async startCanvasSelect(mode) {
      if (this.inpaintStage === 'send' || this.inpaintStage === 'processing') { this.toast('Đang xử lý — chờ xong rồi chọn vùng.', 'error'); return; }
      if (this.inpaintMaskMode === mode) { this.clearInpaintMask(); return; }
      // Lasso/rect/vùng chọn cũng thao tác theo pixel layer → cần layer thẳng hàng (không xoay/lật).
      if (!(await this.flattenActiveLayerTransform())) return;
      this.inpaintMaskSource = 'canvas';
      if (this._inpaintDrag) this._inpaintStopDrag();
      this.inpaintMaskMode = mode;
      this.inpaintMaskDone = false;
      this._inpaintMaskKind = '';
      this.inpaintBrushData = '';
      this.inpaintMaskBox = { x: 0.425, y: 0.425, w: 0.15, h: 0.15 };
      if (mode === 'freehand') { this.inpaintFreehandPoints = []; this.inpaintFreehandPaths = []; this._initInpaintBrush(); }
      if (mode === 'path') { this.inpaintPathPoints = []; this.inpaintPathRegions = []; this.inpaintPathCloseHover = false; this._pathEditingRegion = -1; this._pathHoverRegion = -1; this._initInpaintBrush(); }
      if (mode === 'magic') { this._initInpaintBrush(); }
    },
    // ── Freehand (lasso) select: vẽ tự do tạo vùng kín → mask ──
    freehandStart(e) {
      if (this.inpaintMaskMode !== 'freehand') return;
      e.stopPropagation();
      const p = this.inpaintMaskPointer(e); if (!p) return;
      this._inpaintFreehandActive = true;
      this.inpaintFreehandPoints = [p];
      // Chế độ 'new' → xoá nét cũ; 'add'/'subtract' → giữ mask + paths để cộng/trừ vào vùng hiện có.
      if (this.inpaintSelectMode === 'new') {
        if (this._inpaintMaskCtx && this._inpaintMaskCanvas) {
          this._inpaintMaskCtx.clearRect(0, 0, this._inpaintMaskCanvas.width, this._inpaintMaskCanvas.height);
        }
        this.inpaintFreehandPaths = [];
      }
    },
    setInpaintSelectMode(mode) { this.inpaintSelectMode = (this.inpaintSelectMode === mode) ? 'new' : mode; },
    freehandMove(e) {
      if (!this._inpaintFreehandActive) return;
      const p = this.inpaintMaskPointer(e); if (!p) return;
      const pts = this.inpaintFreehandPoints;
      const last = pts[pts.length - 1];
      if (last && Math.hypot(p.nx - last.nx, p.ny - last.ny) < 0.002) return;
      pts.push(p);
    },
    freehandStop() {
      if (!this._inpaintFreehandActive) return;
      this._inpaintFreehandActive = false;
      const pts = this.inpaintFreehandPoints;
      if (pts.length < 3) { this.inpaintFreehandPoints = []; return; }
      if (!this._inpaintMaskCtx) { this._initInpaintBrush(); }
      const c = this._inpaintMaskCanvas, ctx = this._inpaintMaskCtx;
      if (!c || !ctx) { return; }
      const w = c.width, h = c.height;
      ctx.globalCompositeOperation = this.inpaintSelectMode === 'subtract' ? 'destination-out' : 'source-over';
      ctx.beginPath();
      pts.forEach((pt, i) => { const x = pt.nx * w, y = pt.ny * h; if (i === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); } });
      ctx.closePath();
      ctx.fillStyle = maskVeil();
      ctx.fill();
      ctx.globalCompositeOperation = 'source-over';
      this._finalizeInpaintBrush();
      // Lưu nét đã hoàn thành vào paths để preview hiển thị ĐỦ các lần cộng/trừ.
      this.inpaintFreehandPaths.push(pts.map((p) => ({ nx: p.nx, ny: p.ny })));
      this.inpaintFreehandPoints = [];
      // Sau vòng lasso đầu tiên chuyển sang 'add' (giống path/magic) — vẽ tiếp sẽ CỘNG DỒN
      // vùng thay vì vô tình XOÁ vùng vừa khoanh (bấm nút  nếu muốn chủ động thêm).
      if (this.inpaintSelectMode === 'new') this.inpaintSelectMode = 'add';
    },
};
