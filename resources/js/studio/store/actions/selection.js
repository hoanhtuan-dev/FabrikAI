// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — miền: vùng chọn & mask: freehand/path/bezier/wand · brush mask · thao tác vùng · undo/redo.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt file gốc.
import { markRaw } from 'vue';
export const selectionActions = {
    // ── Inpaint Mask: chọn vùng trên ảnh preview (integrated into InpaintCard) ──
    // "Xong": áp dụng vùng chọn/vùng vẽ, thoát công cụ, LƯU mask vào store để Sửa ảnh dùng.
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
      ctx.fillStyle = 'rgba(220,38,38,0.6)';
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
    // ── Path (curve) select: click thêm điểm neo → đường cong mượt → đóng để tạo vùng chọn ──
    // Bán kính grab node/handle — lớn hơn trên touch/bút (pointer: coarse) để dễ bấm bằng ngón tay.
    _grabR() {
      const coarse = typeof matchMedia !== 'undefined' && matchMedia('(pointer: coarse)').matches;
      return coarse ? 22 : 12;
    },
    // ── Bezier curve selection (Krita Pen tool): click=neo (kéo=handle), kéo NODE/HANDLE để chỉnh, right-click=xóa ──
    _pathHit(p) {
      const m = this.canvasMetrics();
      if (!m) return null;
      const d = (nx, ny) => Math.hypot((p.nx - nx) * m.vw, (p.ny - ny) * m.vh);
      const R = this._grabR();
      for (let i = this.inpaintPathPoints.length - 1; i >= 0; i--) {
        const nd = this.inpaintPathPoints[i];
        // Bỏ qua tay điều khiển SUY BIẾN (độ dài ~0) để kéo NODE thay vì kéo tay lệch.
        if (Math.hypot((nd.ox || 0) * m.vw, (nd.oy || 0) * m.vh) > 4 && d(nd.nx + (nd.ox || 0), nd.ny + (nd.oy || 0)) < R) return { type: 'handle', i, side: 'out' };
        if (Math.hypot((nd.ix || 0) * m.vw, (nd.iy || 0) * m.vh) > 4 && d(nd.nx + (nd.ix || 0), nd.ny + (nd.iy || 0)) < R) return { type: 'handle', i, side: 'in' };
      }
      for (let i = this.inpaintPathPoints.length - 1; i >= 0; i--) {
        const nd = this.inpaintPathPoints[i];
        if (d(nd.nx, nd.ny) < R + 2) return { type: 'node', i };
      }
      return null;
    },
    _pathScreenDist(nx, ny, px, py) {
      const m = this.canvasMetrics(); if (!m) return Infinity;
      return Math.hypot((nx - px) * m.vw, (ny - py) * m.vh);
    },
    // Hover gần điểm BẮT ĐẦU (snap để đóng kín): highlight node đầu + guide line nối về điểm đầu.
    // Đồng thời: khi không đang vẽ, hover gần đường bao vùng ĐÃ ĐÓNG → đánh dấu vùng để hiện nút 'Sửa'.
    _clearPathHover() {
      if (this._pathHoverTimer) { clearTimeout(this._pathHoverTimer); this._pathHoverTimer = null; }
      this._pathHoverRegion = -1;
      this._pathHoverPoint = null;
    },
    pathHover(e) {
      if (this.inpaintMaskMode !== 'path' || this._pathDrag) { this.inpaintPathCloseHover = false; this._clearPathHover(); return; }
      const p = this.inpaintMaskPointer(e);
      if (!p) { this.inpaintPathCloseHover = false; this._clearPathHover(); return; }
      if (this.inpaintPathPoints.length >= 3) {
        const s = this.inpaintPathPoints[0];
        this.inpaintPathCloseHover = this._pathScreenDist(p.nx, p.ny, s.nx, s.ny) < (this._grabR() + 8);
      } else {
        this.inpaintPathCloseHover = false;
      }
      // Chỉ hover vùng ĐÃ ĐÓNG khi không đang vẽ điểm mới.
      if (this.inpaintPathPoints.length === 0) {
        const hit = this._regionHoverHit(p);
        if (hit.region >= 0) {
          // Đang hover: giữ nút + hủy timer ẩn.
          if (this._pathHoverTimer) { clearTimeout(this._pathHoverTimer); this._pathHoverTimer = null; }
          this._pathHoverRegion = hit.region;
          this._pathHoverPoint = { nx: hit.x, ny: hit.y };
        } else if (this._pathHoverRegion >= 0 && !this._pathHoverTimer) {
          // Rời vùng → đếm 450ms mới ẩn nút (đủ thời gian di tới bấm).
          this._pathHoverTimer = setTimeout(() => { this._pathHoverRegion = -1; this._pathHoverPoint = null; this._pathHoverTimer = null; }, 450);
        }
      } else {
        this._clearPathHover();
      }
    },
    // Khoảng cách điểm-đoạn thẳng (px màn hình).
    _pointSegDist(px, py, x1, y1, x2, y2) {
      const dx = x2 - x1, dy = y2 - y1;
      const len2 = dx * dx + dy * dy;
      let t = len2 ? ((px - x1) * dx + (py - y1) * dy) / len2 : 0;
      t = Math.max(0, Math.min(1, t));
      const qx = x1 + t * dx, qy = y1 + t * dy;
      return Math.hypot(px - qx, py - qy);
    },
    // Điểm gần nhất trên đoạn thẳng AB với điểm P (px màn hình).
    _closestOnSeg(px, py, x1, y1, x2, y2) {
      const dx = x2 - x1, dy = y2 - y1;
      const len2 = dx * dx + dy * dy;
      let t = len2 ? ((px - x1) * dx + (py - y1) * dy) / len2 : 0;
      t = Math.max(0, Math.min(1, t));
      return { x: x1 + t * dx, y: y1 + t * dy };
    },
    // Vùng đã đóng gần con trỏ nhất: trả {region, x, y} (x,y = điểm gần nhất, normalized) — anchor nút 'Sửa'.
    _regionHoverHit(p) {
      const m = this.canvasMetrics(); if (!m) return { region: -1, x: 0, y: 0 };
      const sx = p.nx * m.vw, sy = p.ny * m.vh;
      let best = { region: -1, x: p.nx, y: p.ny }, bestDist = this._grabR() + 6;
      for (let r = 0; r < this.inpaintPathRegions.length; r++) {
        const arr = this.inpaintPathRegions[r]; const n = arr.length;
        for (let i = 0; i < n; i++) {
          const a = arr[i], b = arr[(i + 1) % n];
          const ax = a.nx * m.vw, ay = a.ny * m.vh, bx = b.nx * m.vw, by = b.ny * m.vh;
          const d = this._pointSegDist(sx, sy, ax, ay, bx, by);
          if (d < bestDist) {
            const cp = this._closestOnSeg(sx, sy, ax, ay, bx, by);
            bestDist = d; best = { region: r, x: cp.x / m.vw, y: cp.y / m.vh };
          }
        }
      }
      return best;
    },
    // Mở vùng đã đóng để chỉnh sửa (từ nút 'Sửa').
    enterEditRegion(r) {
      if (this.inpaintMaskMode !== 'path') return;
      if (this._pathEditingRegion >= 0) return;
      if (this._loadEditRegion(r)) { this._clearPathHover(); this.inpaintPathCloseHover = false; }
    },
    // Hit node (KHÔNG xét tay điều khiển) — dùng cho Ctrl+click đổi kiểu node.
    _pathNodeHit(p) {
      const m = this.canvasMetrics(); if (!m) return -1;
      for (let i = this.inpaintPathPoints.length - 1; i >= 0; i--) {
        const nd = this.inpaintPathPoints[i];
        if (Math.hypot((p.nx - nd.nx) * m.vw, (p.ny - nd.ny) * m.vh) < (this._grabR() + 2)) return i;
      }
      return -1;
    },
    // Ctrl+click node → xoay vòng kiểu: smooth (mượt) → cusp (góc cong lệch) → sharp (góc nhọn).
    _zeroHandles(nd) { return !(nd.ox || 0) && !(nd.oy || 0) && !(nd.ix || 0) && !(nd.iy || 0); },
    // Sinh tay điều khiển mặc định cho node khi chuyển kiểu KHỎI 'sharp' (đang không có tay).
    _initHandlesForKind(i, kind) {
      const pts = this.inpaintPathPoints; const n = pts.length;
      const nd = pts[i]; if (!nd) return;
      if (n < 2) { nd.ox = 0.09; nd.oy = 0; nd.ix = -0.09; nd.iy = 0; nd.sym = kind === 'smooth'; return; }
      const prev = pts[(i - 1 + n) % n], next = pts[(i + 1) % n];
      const tox = next.nx - nd.nx, toy = next.ny - nd.ny;
      const tx = prev.nx - nd.nx, ty = prev.ny - nd.ny;
      const lto = Math.hypot(tox, toy), lti = Math.hypot(tx, ty);
      nd.ox = (lto > 0.0001 ? tox * 0.4 : 0.09); nd.oy = (lto > 0.0001 ? toy * 0.4 : 0);
      if (kind === 'smooth') { nd.ix = -nd.ox; nd.iy = -nd.oy; nd.sym = true; }
      else if (kind === 'cusp') { if (lti > 0.0001) { nd.ix = tx * 0.4; nd.iy = ty * 0.4; } else { nd.ix = -nd.ox * 0.5; nd.iy = -nd.oy * 0.5; } nd.sym = false; }
      else { nd.ox = 0; nd.oy = 0; nd.ix = 0; nd.iy = 0; nd.sym = false; }
    },
    pathSetNodeKind(i) {
      if (this.inpaintMaskMode !== 'path') return;
      const nd = this.inpaintPathPoints[i]; if (!nd) return;
      this._snapshotInpaintPath();
      const order = ['smooth', 'cusp', 'sharp'];
      const cur = nd.kind || 'smooth';
      const next = order[(order.indexOf(cur) + 1) % order.length];
      const hadNoHandles = cur === 'sharp' || this._zeroHandles(nd);
      nd.kind = next;
      // Chuyển KIỂU → đảm bảo tay điều khiển TƯƠNG ỨNG hiện ra (nếu node đang không có tay).
      if ((next === 'smooth' || next === 'cusp') && hadNoHandles) this._initHandlesForKind(i, next);
      else if (next === 'smooth') nd.sym = true;
      else if (next === 'cusp') nd.sym = false;
      else if (next === 'sharp') { nd.ox = 0; nd.oy = 0; nd.ix = 0; nd.iy = 0; nd.sym = false; }
      this.toast(next === 'smooth' ? 'Node: Mượt' : next === 'cusp' ? 'Node: Cusp' : 'Node: Góc nhọn');
    },
    // Hit node của vùng ĐÃ ĐÓNG (để mở lại chỉnh sửa) — trả về {region, index}.
    _regionNodeHit(p) {
      const m = this.canvasMetrics(); if (!m) return { region: -1, index: -1 };
      for (let r = 0; r < this.inpaintPathRegions.length; r++) {
        const arr = this.inpaintPathRegions[r];
        for (let i = arr.length - 1; i >= 0; i--) {
          const nd = arr[i];
          if (Math.hypot((p.nx - nd.nx) * m.vw, (p.ny - nd.ny) * m.vh) < (this._grabR() + 2)) return { region: r, index: i };
        }
      }
      return { region: -1, index: -1 };
    },
    // Nạp vùng đã đóng vào inpaintPathPoints để chỉnh sửa (giữ _pathEditingRegion để đóng là cập nhật).
    _loadEditRegion(region) {
      const arr = this.inpaintPathRegions[region]; if (!arr) return false;
      this._pathEditingRegion = region;
      this.inpaintPathPoints = arr.map((p) => ({ nx: p.nx, ny: p.ny, ox: p.ox || 0, oy: p.oy || 0, ix: p.ix || 0, iy: p.iy || 0, sym: p.sym !== false, kind: p.kind || 'smooth' }));
      return true;
    },
    // Vẽ lại mask từ TẤT CẢ vùng đã đóng (dùng để cập nhật sau khi sửa 1 vùng / thêm vùng mới).
    _rebakePathRegions() {
      if (!this._inpaintMaskCtx) this._initInpaintBrush();
      const c = this._inpaintMaskCanvas, ctx = this._inpaintMaskCtx;
      if (!c || !ctx) return;
      const w = c.width, h = c.height;
      ctx.globalCompositeOperation = 'source-over';
      ctx.clearRect(0, 0, w, h);
      for (const arr of this.inpaintPathRegions) {
        ctx.globalCompositeOperation = (arr._mode === 'subtract') ? 'destination-out' : 'source-over';
        const P = arr.map((p) => ({ x: p.nx * w, y: p.ny * h, ox: (p.ox || 0) * w, oy: (p.oy || 0) * h, ix: (p.ix || 0) * w, iy: (p.iy || 0) * h }));
        const n = P.length;
        ctx.beginPath();
        ctx.moveTo(P[0].x, P[0].y);
        for (let i = 0; i < n; i++) {
          const a = P[i], b = P[(i + 1) % n];
          ctx.bezierCurveTo(a.x + a.ox, a.y + a.oy, b.x + b.ix, b.y + b.iy, b.x, b.y);
        }
        ctx.closePath();
        ctx.fillStyle = 'rgba(220,38,38,0.6)';
        ctx.fill();
      }
      ctx.globalCompositeOperation = 'source-over';
      this._finalizeInpaintBrush();
    },
    pathDown(e) {
      if (this.inpaintMaskMode !== 'path') return;
      e.stopPropagation();
      const p = this.inpaintMaskPointer(e); if (!p) return;
      const pts = this.inpaintPathPoints;
      // Ctrl(hoặc ⌘)+click node → đổi kiểu node (đang vẽ hoặc của vùng đã đóng).
      if (e.ctrlKey || e.metaKey) {
        const ni = this._pathNodeHit(p);
        if (ni >= 0) { this.pathSetNodeKind(ni); return; }
        const ri = this._regionNodeHit(p);
        if (ri.region >= 0) { this._loadEditRegion(ri.region); this.pathSetNodeKind(ri.index); return; }
        return;
      }
      // Không có điểm đang vẽ (đã đóng hết) → bấm node của vùng ĐÃ ĐÓNG để mở lại chỉnh sửa nó.
      if (pts.length === 0) {
        const ri = this._regionNodeHit(p);
        if (ri.region >= 0) {
          this._loadEditRegion(ri.region);
          this._pathDrag = { type: 'node', i: ri.index, sx: p.nx, sy: p.ny, moved: false };
          return;
        }
      }
      // Snap/đóng kín: >=3 điểm & nhấp gần điểm BẮT ĐẦU → tap = đóng, kéo = di chuyển node đầu.
      if (pts.length >= 3 && this._pathScreenDist(p.nx, p.ny, pts[0].nx, pts[0].ny) < (this._grabR() + 10)) {
        this.inpaintPathCloseHover = false;
        this._pathDrag = { type: 'close', i: 0, sx: p.nx, sy: p.ny, moved: false };
        return;
      }
      this._snapshotInpaintPath();
      const hit = this._pathHit(p);
      if (hit) { this._pathDrag = { ...hit, broke: false, sx: p.nx, sy: p.ny, moved: false }; return; }
      // Khoảng trống → neo mới, push NGAY vào mảng (hiển thị ngay ở lần nhấp đầu).
      // Kéo (move) chỉnh tay điều khiển của chính node này in-place để preview sống.
      const i = this.inpaintPathPoints.push({ nx: p.nx, ny: p.ny, ox: 0, oy: 0, ix: 0, iy: 0, sym: true, kind: 'smooth' }) - 1;
      this._pathDrag = { type: 'pending', i, nx: p.nx, ny: p.ny, ox: 0, oy: 0, ix: 0, iy: 0, sym: true, kind: 'smooth', sx: p.nx, sy: p.ny, moved: false };
    },
    pathMove(e) {
      if (this.inpaintMaskMode !== 'path' || !this._pathDrag) return;
      const p = this.inpaintMaskPointer(e); if (!p) return;
      const dr = this._pathDrag;
      if (dr.type === 'close') {
        // Kéo >4px → chuyển thành di chuyển node đầu (không đóng nữa).
        if (this._pathScreenDist(p.nx, p.ny, dr.sx, dr.sy) > 4) dr.moved = true;
        if (dr.moved) dr.type = 'node';
        else return;
      }
      if (dr.type === 'pending') {
        const nd = this.inpaintPathPoints[dr.i]; if (!nd) return;
        nd.ox = (p.nx - dr.nx) * 0.5; nd.oy = (p.ny - dr.ny) * 0.5;
        nd.ix = -nd.ox; nd.iy = -nd.oy; nd.sym = true;
        return;
      }
      if (dr.type === 'node') { const nd = this.inpaintPathPoints[dr.i]; if (nd) { nd.nx = p.nx; nd.ny = p.ny; } return; }
      if (dr.type === 'handle') {
        const nd = this.inpaintPathPoints[dr.i]; if (!nd) return;
        const dx = p.nx - nd.nx, dy = p.ny - nd.ny;
        if (e.altKey) dr.broke = true; // Alt = phá đối xứng (asymmetric)
        if (dr.side === 'out') { nd.ox = dx; nd.oy = dy; if (nd.sym !== false && !dr.broke) { nd.ix = -dx; nd.iy = -dy; } }
        else { nd.ix = dx; nd.iy = dy; if (nd.sym !== false && !dr.broke) { nd.ox = -dx; nd.oy = -dy; } }
        if (dr.broke) nd.sym = false;
      }
    },
    pathUp() {
      if (this.inpaintMaskMode !== 'path') return;
      const dr = this._pathDrag; this._pathDrag = null;
      // Nhấp (không kéo) gần điểm BẮT ĐẦU → đóng kín vùng chọn, rồi bắt đầu vùng mới.
      if (dr && dr.type === 'close' && !dr.moved) { this.pathClose(); return; }
      // Neo đã push ở pathDown; chỉ kết thúc kéo (giữ node + tay điều khiển vừa chỉnh).
    },
    // ── Undo/Redo riêng cho mask path ──
    _snapshotInpaintPath() {
      this._inpaintPathUndo = this._inpaintPathUndo || [];
      this._inpaintPathRedo = [];
      this._inpaintPathUndo.push({ points: this.inpaintPathPoints.map((p) => ({ ...p })), regions: this.inpaintPathRegions.map((r) => r.map((p) => ({ ...p }))) });
      if (this._inpaintPathUndo.length > 30) this._inpaintPathUndo.shift();
    },
    _applyInpaintSnap(snap) {
      this.inpaintPathPoints = snap.points.map((p) => ({ ...p }));
      this.inpaintPathRegions = snap.regions.map((r) => r.map((p) => ({ ...p })));
      this._pathEditingRegion = -1;
      this._rebakePathRegions();
      this._finalizeInpaintBrush();
    },
    inpaintPathUndo() {
      if (this.inpaintMaskMode !== 'path') return;
      const snap = (this._inpaintPathUndo || []).pop();
      if (!snap) { this.toast('Không còn gì để hoàn tác.', 'info'); return; }
      this._inpaintPathRedo = this._inpaintPathRedo || [];
      this._inpaintPathRedo.push({ points: this.inpaintPathPoints.map((p) => ({ ...p })), regions: this.inpaintPathRegions.map((r) => r.map((p) => ({ ...p }))) });
      this._applyInpaintSnap(snap);
      this.toast('Đã hoàn tác.');
    },
    inpaintPathRedo() {
      if (this.inpaintMaskMode !== 'path') return;
      const snap = (this._inpaintPathRedo || []).pop();
      if (!snap) { this.toast('Không còn gì để làm lại.', 'info'); return; }
      this._inpaintPathUndo = this._inpaintPathUndo || [];
      this._inpaintPathUndo.push({ points: this.inpaintPathPoints.map((p) => ({ ...p })), regions: this.inpaintPathRegions.map((r) => r.map((p) => ({ ...p }))) });
      this._applyInpaintSnap(snap);
      this.toast('Đã làm lại.');
    },
    pathDeleteNode(i) { if (this.inpaintMaskMode !== 'path') return; this._snapshotInpaintPath(); if (i >= 0 && i < this.inpaintPathPoints.length) this.inpaintPathPoints.splice(i, 1); },
    pathUndoPoint() { if (this.inpaintMaskMode !== 'path') return; this._snapshotInpaintPath(); this.inpaintPathPoints.pop(); },
    pathClose() {
      if (this.inpaintMaskMode !== 'path') return;
      this.inpaintPathCloseHover = false;
      this._snapshotInpaintPath();
      const pts = this.inpaintPathPoints;
      if (pts.length < 3) { this.toast('Cần ít nhất 3 điểm để tạo vùng chọn.', 'error'); return; }
      const mode = this.inpaintSelectMode === 'subtract' ? 'subtract' : 'add';
      const clone = pts.map((p) => ({ nx: p.nx, ny: p.ny, ox: p.ox || 0, oy: p.oy || 0, ix: p.ix || 0, iy: p.iy || 0, sym: p.sym !== false, kind: p.kind || 'smooth' }));
      // Nếu đang chỉnh sửa lại vùng đã đóng → thay thế vùng đó (giữ nguyên mode gốc).
      let editing = this._pathEditingRegion != null && this._pathEditingRegion >= 0 && this._pathEditingRegion < this.inpaintPathRegions.length;
      // Chế độ 'new' luôn bắt đầu vùng mới (xóa sạch vùng cũ).
      if (this.inpaintSelectMode === 'new') { editing = false; this.inpaintPathRegions = []; this._pathEditingRegion = -1; }
      if (editing) {
        const j = this._pathEditingRegion;
        const old = this.inpaintPathRegions[j];
        clone._mode = (old && old._mode) || mode; // giữ chế độ gốc khi sửa
        this.inpaintPathRegions.splice(j, 1, clone);
      } else {
        clone._mode = mode;
        this.inpaintPathRegions.push(clone);
      }
      this._pathEditingRegion = -1;
      this.inpaintPathPoints = [];
      this._rebakePathRegions();
      if (this.inpaintSelectMode === 'new') this.inpaintSelectMode = 'add';
      // Card "Sửa ảnh" (source=inpaint): vẽ xong & ĐÓNG → LẤY NGAY vùng chọn làm mask (không cần bấm "Xong" riêng).
      if (this.inpaintMaskSource === 'inpaint') {
        this._inpaintMaskKind = 'brush';
        this.inpaintMaskDone = true;
        this.inpaintMaskMode = 'none';
        this._pathHoverRegion = -1;
        this._pathHoverPoint = null;
        this.toast('Đã lấy vùng chọn làm mask — bấm "Sửa ảnh" để xử lý.');
      } else {
        this.toast(editing ? 'Đã cập nhật vùng chọn Bezier.' : 'Đã tạo vùng chọn Bezier — vẽ tiếp hoặc bấm Xóa/Tô/Nhân đôi/Xong.');
      }
    },
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
        ctx.fillStyle = 'rgba(220,38,38,0.6)';
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
      c.fillStyle = erase ? 'rgba(0,0,0,1)' : 'rgba(220,38,38,0.6)'; // đỏ 60% — rõ mà không che ảnh
      c.beginPath(); c.arc(p.nx * w, p.ny * h, this._inpaintBrushRadius(), 0, Math.PI * 2); c.fill();
      c.globalCompositeOperation = 'source-over';
    },
    _drawInpaintBrushLine(from, to) {
      const c = this._inpaintMaskCtx; if (!c) return;
      const w = this._inpaintMaskCanvas.width, h = this._inpaintMaskCanvas.height;
      const erase = !!this.inpaintErase;
      c.globalCompositeOperation = erase ? 'destination-out' : 'source-over';
      c.strokeStyle = erase ? 'rgba(0,0,0,1)' : 'rgba(220,38,38,0.6)';
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
