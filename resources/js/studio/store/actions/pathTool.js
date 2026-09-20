// TÁCH TIẾP từ selection.js (đợt tối ưu 2026-09-24) — miền: path/bezier (Krita pen) + undo/redo path.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt bản gộp.
export const pathToolActions = {
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
};
