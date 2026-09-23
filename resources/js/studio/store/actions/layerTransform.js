// TÁCH TIẾP từ layers.js (đợt tối ưu 2026-09-24) — miền: flatten · kéo/nudge/snap · transform unit · căn chỉnh · composite · layout · cài đặt thanh.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt bản gộp.
import { apiError, CSRF } from '../helpers.js';
export const layerTransformActions = {
    // ── Chuẩn hoá transform layer (xoay/lật) trước khi sửa pixel ──
    // Overlay vẽ/xóa/lasso tính theo khung hiển thị (chưa kể rotation/flip) nên trên layer bị XOAY
    // hoặc LẬT, nét cọ/đường lasso và vùng áp dụng LỆCH khỏi nội dung người dùng nhìn thấy (khi
    // phóng to lệch càng rõ). Khi bật công cụ sửa pixel trên layer như vậy ta "rasterize" transform:
    // nội dung được xoay/lật vào 1 ảnh mới (giữ NGUYÊN vị trí/kích thước hiển thị) rồi reset
    // rotation/flip → mọi overlay chuẩn hoá theo ảnh hoạt động chính xác. Ctrl+Z hoàn lại bản gốc.
    _needsFlatten(l) {
      if (!l || !l.image) return false;
      const rot = Math.abs((Number(l.rotation) || 0) % 360);
      return (rot > 0.5 && rot < 359.5) || !!l.flipX || !!l.flipY;
    },
    // Gọi trước khi bật erase/draw/region-select. Trả true khi sẵn sàng sửa (đã flatten nếu cần).
    async flattenActiveLayerTransform() {
      if (this._flattenBusy) return false;
      const l = this.activeLayer;
      if (!this._needsFlatten(l)) return true;
      try {
        this._flattenBusy = true;
        const img = await this._loadImageSrc(l.image);
        const nw = img.naturalWidth, nh = img.naturalHeight;
        if (!nw || !nh) return false;
        const rot = ((Number(l.rotation) || 0) * Math.PI) / 180;
        const cosR = Math.abs(Math.cos(rot)), sinR = Math.abs(Math.sin(rot));
        const W = Math.max(1, Math.ceil(nw * cosR + nh * sinR));
        const H = Math.max(1, Math.ceil(nw * sinR + nh * cosR));
        const canvas = document.createElement('canvas');
        canvas.width = W; canvas.height = H;
        const ctx = canvas.getContext('2d');
        ctx.translate(W / 2, H / 2);
        // Khớp CSS `transform: rotate(θ) scale(sx,sy)` (scale trước rồi rotate) → ctx.rotate rồi scale.
        ctx.rotate(rot);
        ctx.scale(l.flipX ? -1 : 1, l.flipY ? -1 : 1);
        ctx.drawImage(img, -nw / 2, -nh / 2);
        const MAX = 512;
        const k = Math.min(1, MAX / W, MAX / H);
        const newBaseW = W * k, newBaseH = H * k;
        // Kích thước hiển thị CŨ (không gian base, trước zoom) để giữ layer KHÔNG nhảy cỡ.
        const kOld = Math.min(1, MAX / nw, MAX / nh);
        const oldBaseW = Number(l.baseW) || (nw * kOld);
        const oldBaseH = Number(l.baseH) || (nh * kOld);
        const sOld = Math.max(0.05, Math.min(8, Number(l.scale) || 1));
        const visW = oldBaseW * cosR + oldBaseH * sinR; // bbox hiển thị (CSS px, chưa zoom)
        const visH = oldBaseW * sinR + oldBaseH * cosR;
        // newBase tỉ lệ đúng visW/visH (đồng dạng) nên 1 hệ số scale giữ đúng cả 2 chiều.
        let sNew = (visW * sOld) / Math.max(1, newBaseW);
        sNew = Math.max(0.05, Math.min(8, sNew));
        this.pushHistory(); // undo = khôi phục layer gốc (ảnh + transform)
        l.image = canvas.toDataURL('image/png');
        l.rotation = 0; l.flipX = false; l.flipY = false;
        l.baseW = newBaseW; l.baseH = newBaseH;
        l.scale = sNew;
        // Pixel đã khác generation gốc (nếu layer là kết quả AI) → trở thành ảnh cục bộ để
        // download/lưu/inpaint không nhầm với file gốc trên server.
        if (l.kind === 'gen' && l.genId) {
          l.kind = 'source'; l.genId = null;
          if (this.activeLayerId === l.id) { this.editSource = { url: l.image, name: l.name }; this.previewId = null; this.preview = null; }
        }
        this.saveLayerLayout();
        this.toast('Đã áp xoay/lật vào ảnh để chỉnh sửa đúng vị trí — Ctrl+Z nếu muốn hoàn lại.');
        return true;
      } catch (e) {
        this.toast('Không chuẩn hoá được ảnh xoay/lật.', 'error');
        return false;
      } finally { this._flattenBusy = false; }
    },
    // Kéo layer trên canvas để di chuyển — hỗ trợ di chuyển NHIỀU layer cùng lúc (nhóm).
    beginLayerDrag(id, e) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l || l.locked) return;
      // Nếu layer bị kéo nằm trong NHÓM chọn nhiều → kéo cả nhóm; ngược lại chọn riêng nó.
      let ids = this.selectedIds;
      if (!ids.includes(id)) { this.setActiveLayer(id); ids = [id]; }
      const items = this.canvasLayers.filter(x => ids.includes(x.id) && x.visible !== false && !x.locked).map(x => ({ id: x.id, ox: Number(x.x) || 0, oy: Number(x.y) || 0 }));
      if (!items.length) return;
      // Snapshot lúc bắt đầu kéo (push vào history khi kết thúc) — tránh làm bẩn lịch sử.
      this._layerDrag = { id, ids, items, sx: e.clientX, sy: e.clientY, snap: this._snapshot() };
    },
    layerDragMove(e) {
      const d = this._layerDrag;
      if (!d) return;
      const prim = d.items.find(x => x.id === d.id) || d.items[0];
      if (!prim) return;
      let dx = (e.clientX - d.sx) / (this.zoom || 1);
      let dy = (e.clientY - d.sy) / (this.zoom || 1);
      const SNAP = 20 / (this.zoom || 1);  // ≈20px màn hình
      const G = ((this.snapGrid || 0) / (this.zoom || 1)); // lưới bắt điểm (preset)
      let tx = prim.ox + dx, ty = prim.oy + dy;
      let sx = null, sy = null;
      // Bắt điểm LƯỚI preset trước (ưu tiên rơi vào nút lưới).
      if (G > 0) { tx = Math.round(tx / G) * G; ty = Math.round(ty / G) * G; }
      // Bắt điểm tâm canvas (0,0) + cạnh các layer NGOÀI nhóm.
      if (Math.abs(tx) < SNAP) { tx = 0; sx = 0; }
      if (Math.abs(ty) < SNAP) { ty = 0; sy = 0; }
      this.canvasLayers.forEach((o) => {
        if (d.ids.includes(o.id) || o.visible === false) return;
        if (Math.abs(tx - (o.x || 0)) < SNAP) { tx = o.x || 0; sx = tx; }
        if (Math.abs(ty - (o.y || 0)) < SNAP) { ty = o.y || 0; sy = ty; }
      });
      // Áp delta đã bắt điểm cho toàn bộ nhóm.
      dx = tx - prim.ox; dy = ty - prim.oy;
      d.items.forEach(it => { const m = this.canvasLayers.find(x => x.id === it.id); if (m) { m.x = it.ox + dx; m.y = it.oy + dy; } });
      this.snapX = sx;
      this.snapY = sy;
    },
    endLayerDrag() {
      if (!this._layerDrag) return;
      const snap = this._layerDrag.snap;
      this._layerDrag = null;
      this.snapX = null;
      this.snapY = null;
      this.saveLayerLayout();
      if (snap) { this.undoStack.push(snap); if (this.undoStack.length > 50) this.undoStack.shift(); this.redoStack = []; }
    },
    // Di chuyển TOÀN BỘ layer đang chọn theo (dx,dy) — dùng phím mũi tên.
    nudgeSelection(dx, dy) {
      const ids = this.selectedIds; if (!ids.length) return;
      let moved = false;
      this.canvasLayers.forEach(l => { if (ids.includes(l.id) && !l.locked) { l.x = (l.x || 0) + dx; l.y = (l.y || 0) + dy; moved = true; } });
      if (moved) { this.saveLayerLayout(); }
    },
    setSnapGrid(v) { this.snapGrid = Number(v) || 0; },
    // Kích thước hiển thị (bbox) của layer — dùng cho căn/chia đều.
    _layerBox(l) { const w = Math.max(1, (Number(l.baseW) || 0) * (Number(l.scale) || 1)); const h = Math.max(1, (Number(l.baseH) || 0) * (Number(l.scale) || 1)); return { w, h, cx: (l.x || 0), cy: (l.y || 0) }; },
    // ĐƠN VỊ căn/chia đều: mỗi NHÓM = 1 khối cứng (gồm toàn bộ thành viên), layer đơn lẻ = 1 khối.
    _selectionUnits() {
      const sels = this.selection; const byGroup = {}; const singles = [];
      sels.forEach(l => { if (l.groupId) { (byGroup[l.groupId] = byGroup[l.groupId] || []).push(l); } else singles.push(l); });
      const units = [];
      Object.keys(byGroup).forEach(gid => {
        const all = this.canvasLayers.filter(x => x.groupId === gid && x.visible !== false);
        units.push({ type: 'group', gid, layers: all, box: this._unitBox(all) });
      });
      singles.forEach(l => units.push({ type: 'layer', id: l.id, layers: [l], box: this._layerBox(l) }));
      return units;
    },
    _unitBox(layers) { let L = Infinity, R = -Infinity, T = Infinity, B = -Infinity; layers.forEach(l => { const b = this._layerBox(l); const cx = l.x || 0, cy = l.y || 0; L = Math.min(L, cx - b.w / 2); R = Math.max(R, cx + b.w / 2); T = Math.min(T, cy - b.h / 2); B = Math.max(B, cy + b.h / 2); }); return { w: R - L, h: B - T, cx: (L + R) / 2, cy: (T + B) / 2 }; },
    _translateUnit(u, dx, dy) { u.layers.forEach(l => { if (dx) l.x = (l.x || 0) + dx; if (dy) l.y = (l.y || 0) + dy; }); },
    // ── Đơn vị đang được xử lý (group = 1 khối) cho scale/rotate/duplicate ──
    _editUnitLayers() {
      const a = this.activeLayer; if (!a) return [];
      if (a.groupId) {
        const g = this.layerGroups.find(x => x.id === a.groupId);
        if (g && g.layerIds.length > 1 && this.selectedIds.length === g.layerIds.length) {
          return this.canvasLayers.filter(l => l.groupId === g.id && l.visible !== false);
        }
      }
      return [a];
    },
    groupBox(gid) { const all = this.canvasLayers.filter(l => l.groupId === gid && l.visible !== false); if (!all.length) return null; const b = this._unitBox(all); return { x: b.cx, y: b.cy, w: b.w, h: b.h }; },
    _editUnitCenter() {
      const layers = this._editUnitLayers(); if (!layers.length) return null;
      const b = this._unitBox(layers); return { x: b.cx, y: b.cy };
    },
    // Scale CẢ đơn vị (nguyên nhóm) quanh tâm — factor k (không push history, gọi trong drag).
    scaleSelectionBy(k) {
      const layers = this._editUnitLayers(); if (!layers.length || !Number.isFinite(k) || k <= 0) return;
      const c = this._unitBox(layers);
      layers.forEach(l => { const dx = (l.x || 0) - c.cx, dy = (l.y || 0) - c.cy; l.x = c.cx + dx * k; l.y = c.cy + dy * k; l.scale = Math.max(0.05, Math.min(8, (l.scale || 1) * k)); });
      const g = (this.activeLayer && this.activeLayer.groupId) ? this.layerGroups.find(x => x.id === this.activeLayer.groupId) : null;
      if (g) g.scale = (g.scale || 1) * k;
      this.saveLayerLayout();
    },
    // Xoay CẢ đơn vị (nguyên nhóm) quanh tâm — deg (không push history, gọi trong drag).
    rotateSelectionBy(deg) {
      const layers = this._editUnitLayers(); if (!layers.length) return;
      const c = this._unitBox(layers); const a = (deg * Math.PI) / 180, cos = Math.cos(a), sin = Math.sin(a);
      layers.forEach(l => { const dx = (l.x || 0) - c.cx, dy = (l.y || 0) - c.cy; l.x = c.cx + dx * cos - dy * sin; l.y = c.cy + dx * sin + dy * cos; let r = ((l.rotation || 0) + deg) % 360; if (r > 180) r -= 360; if (r < -180) r += 360; l.rotation = Math.round(r); });
      const g = (this.activeLayer && this.activeLayer.groupId) ? this.layerGroups.find(x => x.id === this.activeLayer.groupId) : null;
      if (g) g.rotation = (((g.rotation || 0) + deg) % 360 + 360) % 360;
      this.saveLayerLayout();
    },
    // Cập nhật thuộc tính cho ĐƠN VỊ (group = áp dụng cả nhóm, thống nhất với handle canvas).
    updateUnitTransform(field, value) {
      const a = this.activeLayer; if (!a) return;
      const g = a.groupId ? this.layerGroups.find(x => x.id === a.groupId) : null;
      const isGroup = !!(g && g.layerIds.length > 1 && this.selectedIds.length === g.layerIds.length);
      if (field === 'rotation') {
        if (isGroup) { const delta = (Number(value) || 0) - (g.rotation || 0); this.rotateSelectionBy(delta); return; }
        this.updateLayerTransform(a.id, { rotation: Number(value) || 0 }); return;
      }
      if (field === 'scale') {
        if (isGroup) { const cur = g.scale || 1; const k = (Number(value) || 1) / cur; if (k > 0) this.scaleSelectionBy(k); return; }
        this.updateLayerTransform(a.id, { scale: Number(value) || 1 }); return;
      }
      const layers = this._editUnitLayers(); if (!layers.length) return;
      layers.forEach(l => { l[field] = value; });
      this.saveLayerLayout();
    },
    // Reset rotation cho ĐƠN VỊ: group = xoay vị trí thành viên NGƯỢC lại góc đã xoay (giữ layout nội bộ) + rotation=0.
    resetActiveUnitRotation() {
      const a = this.activeLayer; if (!a) return;
      const g = a.groupId ? this.layerGroups.find(x => x.id === a.groupId) : null;
      this.pushHistory();
      if (g && g.layerIds.length > 1) {
        const layers = this.canvasLayers.filter(l => l.groupId === g.id && l.visible !== false);
        const c = this._unitBox(layers);
        const deg = -(g.rotation || 0); const rad = deg * Math.PI / 180, cos = Math.cos(rad), sin = Math.sin(rad);
        layers.forEach(l => { const dx = (l.x || 0) - c.cx, dy = (l.y || 0) - c.cy; l.x = c.cx + dx * cos - dy * sin; l.y = c.cy + dx * sin + dy * cos; l.rotation = 0; });
        g.rotation = 0;
      } else {
        a.rotation = 0;
      }
      this.saveLayerLayout();
    },
    // Nhân đôi ĐƠN VỊ đang active: nếu group được chọn trọn → nhân đôi nhóm, ngược lại nhân đôi layer.
    duplicateActiveUnit() {
      const a = this.activeLayer; if (!a) return;
      const g = a.groupId ? this.layerGroups.find(x => x.id === a.groupId) : null;
      // GROUP = 1 đối tượng: nhân đôi TOÀN BỘ nhóm (không phụ thuộc số layer đang chọn).
      if (g) { this.duplicateGroup(g.id); return; }
      this.duplicateLayer(a.id);
    },
    // Căn lề theo ĐƠN VỊ (group là 1 khối): left/hcenter/right/top/vcenter/bottom.
    alignSelection(kind) {
      const units = this._selectionUnits(); if (units.length < 2) return;
      let L = Infinity, R = -Infinity, T = Infinity, B = -Infinity;
      units.forEach(u => { L = Math.min(L, u.box.cx - u.box.w / 2); R = Math.max(R, u.box.cx + u.box.w / 2); T = Math.min(T, u.box.cy - u.box.h / 2); B = Math.max(B, u.box.cy + u.box.h / 2); });
      const hc = (L + R) / 2, vc = (T + B) / 2;
      this.pushHistory();
      units.forEach(u => { let dx = 0, dy = 0; if (kind === 'left') dx = (L + u.box.w / 2) - u.box.cx; else if (kind === 'hcenter') dx = hc - u.box.cx; else if (kind === 'right') dx = (R - u.box.w / 2) - u.box.cx; else if (kind === 'top') dy = (T + u.box.h / 2) - u.box.cy; else if (kind === 'vcenter') dy = vc - u.box.cy; else if (kind === 'bottom') dy = (B - u.box.h / 2) - u.box.cy; this._translateUnit(u, dx, dy); });
      this.saveLayerLayout();
    },
    // Chia đều khoảng cách theo ĐƠN VỊ (group di chuyển nguyên khối, giữ VỊ TRÍ NỘI BỘ).
    distributeSelection(kind) {
      const units = this._selectionUnits(); if (units.length < 3) return;
      const prop = kind === 'y' ? 'cy' : 'cx';
      const sorted = units.slice().sort((a, b) => a.box[prop] - b.box[prop]);
      const first = sorted[0].box[prop], last = sorted[sorted.length - 1].box[prop];
      const step = (last - first) / (sorted.length - 1);
      this.pushHistory();
      sorted.forEach((u, i) => { if (i === 0 || i === sorted.length - 1) return; const target = first + step * i; this._translateUnit(u, kind === 'y' ? 0 : target - u.box[prop], kind === 'y' ? target - u.box[prop] : 0); });
      this.saveLayerLayout();
    },
    // Phím Delete → mở popup xác nhận xóa NHIỀU layer.
    deleteSelection() { if (this.selectionUnitCount) this.confirmDeleteOpen = true; },
    confirmDeleteSelection() {
      // GROUP = 1 đối tượng: xóa theo ĐƠN VỊ (nguyên nhóm + layer đơn), không phải từng layer.
      const units = this._selectionUnits(); if (!units.length) { this.confirmDeleteOpen = false; return; }
      // Layer KHÓA không bị xóa — đúng luật đã áp ở nút xóa trong bảng Lớp (disabled) và deleteLayer().
      // Trước đây đường xóa theo lựa chọn bỏ qua hoàn toàn khóa ⇒ phím Delete xóa được cả layer đã khóa.
      const locked = units.filter(u => u.layers.some(l => l.locked));
      const units2 = units.filter(u => !u.layers.some(l => l.locked));
      if (!units2.length) {
        this.confirmDeleteOpen = false;
        this.toast('Đối tượng đang KHÓA — mở khóa rồi mới xóa được.', 'error');
        return;
      }
      const ids = new Set(); const delGids = new Set();
      units2.forEach(u => { u.layers.forEach(l => ids.add(l.id)); if (u.type === 'group') delGids.add(u.gid); });
      const wasActive = this.activeLayerId;
      this.pushHistory();
      this.canvasLayers = this.canvasLayers.filter(l => !ids.has(l.id));
      this.layerGroups = this.layerGroups.filter(g => !delGids.has(g.id));
      const rest = this.canvasLayers.filter(l => l.visible !== false);
      const at = rest.findIndex(l => l.id === wasActive);
      const next = rest[at] || rest[rest.length - 1] || null;
      if (next) this._setActive(next.id); else this._setActive('');
      this.selectedLayerIds = [];
      this.confirmDeleteOpen = false;
      this.saveLayerLayout();
      this.toast('Đã xóa ' + units2.length + ' đối tượng.'
        + (locked.length ? ' Giữ lại ' + locked.length + ' đối tượng đang khóa.' : ''),
        locked.length ? 'error' : 'success');
    },
    // clearCanvas + confirmClearCanvas đã gộp vào cleanCanvas() (có pushHistory + confirm popup qua LayersPanel).
    // Bỏ chọn layer active (nhấp khoảng trống trên canvas).
    selectAll() {
      const visible = this.canvasLayers.filter(l => l.visible !== false && l.locked === false);
      if (!visible.length) { this.toast('Không có layer nào để chọn.', 'error'); return; }
      this._setActive(visible[visible.length - 1].id);
      this.selectedLayerIds = visible.filter(x => x.id !== visible[visible.length - 1].id).map(x => x.id);
      this.saveLayerLayout();
      this.toast('Đã chọn ' + visible.length + ' layer.', 'success');
    },
    deselectAll() {
      this.activeLayerId = '';
      this.editSource = null;
      this.previewId = null;
      this.preview = null;
      this.selectedLayerIds = [];
      this.saveLayerLayout();
    },
    // Gộp tất cả layer đang hiển thị thành 1 ảnh PNG (data URL) theo đúng transform/opacity/blend.
    async compositeVisible() {
      const layers = this.canvasLayers.filter((l) => l.visible !== false && l.image);
      if (!layers.length) throw new Error('Chưa có layer để gộp.');
      const MAX = 512; // khớp với max-h/max-w hiển thị trên canvas → tỷ lệ gộp khớp với màn hình
      const loaded = await Promise.all(layers.map((l) => new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve({ layer: l, img });
        img.onerror = () => resolve({ layer: l, img: null });
        img.src = l.image;
      })));
      const valid = loaded.filter((x) => x.img && x.img.naturalWidth);
      if (!valid.length) throw new Error('Không tải được ảnh layer.');
      // Dùng đúng kích thước hiển thị thật của mỗi layer (natural bị giới hạn về MAX giống canvas).
      const items = valid.map(({ layer: l, img }) => {
        const nw = img.naturalWidth, nh = img.naturalHeight;
        const base = Math.min(1, MAX / nw, MAX / nh);
        return { l, img, w: nw * base, h: nh * base };
      });
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      items.forEach(({ l, w, h }) => {
        const s = l.scale || 1;
        const rot = ((l.rotation || 0) * Math.PI) / 180;
        const cos = Math.cos(rot), sin = Math.sin(rot);
        const hw = (w * s) / 2, hh = (h * s) / 2;
        [[-hw, -hh], [hw, -hh], [hw, hh], [-hw, hh]].forEach(([cx, cy]) => {
          const px = cx * cos - cy * sin + (l.x || 0);
          const py = cx * sin + cy * cos + (l.y || 0);
          if (px < minX) minX = px; if (px > maxX) maxX = px;
          if (py < minY) minY = py; if (py > maxY) maxY = py;
        });
      });
      const pad = 8;
      const W = Math.max(1, Math.ceil(maxX - minX + pad * 2));
      const H = Math.max(1, Math.ceil(maxY - minY + pad * 2));
      const canvas = document.createElement('canvas');
      canvas.width = W; canvas.height = H;
      const ctx = canvas.getContext('2d');
      items.forEach(({ l, img, w, h }) => {
        ctx.save();
        ctx.translate(-minX + pad, -minY + pad);
        ctx.translate(l.x || 0, l.y || 0);
        ctx.rotate(((l.rotation || 0) * Math.PI) / 180);
        ctx.scale((l.scale || 1) * (l.flipX ? -1 : 1), (l.scale || 1) * (l.flipY ? -1 : 1));
        ctx.globalAlpha = l.opacity != null ? l.opacity : 1;
        ctx.globalCompositeOperation = (l.blend && l.blend !== 'normal') ? l.blend : 'source-over';
        ctx.drawImage(img, -w / 2, -h / 2, w, h);
        ctx.restore();
      });
      return canvas.toDataURL('image/png');
    },
    // Xuất ảnh gộp (tải xuống PNG).
    async exportComposite() {
      try {
        const url = await this.compositeVisible();
        const a = document.createElement('a');
        a.href = url;
        a.download = 'composite-' + Date.now() + '.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
        this.toast('Đã xuất ảnh gộp.');
      } catch (e) { this.failToast(e, 'Không gộp được.'); }
    },
    // Gộp layer thành 1 layer mới (upload lên server để lưu lâu dài).
    async flattenToLayer() {
      try {
        const url = await this.compositeVisible();
        const blob = await (await fetch(url)).blob();
        const fd = new FormData();
        fd.append('image', new File([blob], 'composite.png', { type: 'image/png' }));
        const res = await fetch('/api/upload-ref', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' }, body: fd });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(d, 'Không gộp được.');
        const id = 'flat-' + Date.now();
        this.pushCanvasLayer(id, 'source', 'Gộp layer', d.url);
        this.setActiveLayer(id);
        this.toast('Đã gộp layer thành ảnh mới.');
      } catch (e) { this.failToast(e, 'Không gộp được.'); }
    },
    // Tên hiển thị của một generation (dùng tên tuỳ chỉnh nếu có, ngược lại "Ảnh #id").
    genName(g) { return (g && g.meta && g.meta.name) ? g.meta.name : (g ? 'Ảnh #' + g.id : 'Ảnh'); },
    // (Đã gỡ downloadActive() cùng nút "Tải ảnh đang chọn" ở thanh trạng thái: việc tải ảnh đã có
    //  đường riêng — Xuất PNG ở bảng Lớp và nút tải ở Kết quả/Thư viện — giữ lại chỉ gây trùng.)
    // Ẩn/hiện layer (eye toggle). Ẩn layer đang active thì chuyển sang layer hiển thị kế tiếp.
    toggleLayerVisible(id) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      l.visible = !l.visible;
      // [2026-09-20] Ẩn layer KHÔNG chuyển "đang chọn" sang layer khác nữa — GIỮ nguyên layer đó.
      // Vì sao: người dùng vừa ẩn ĐÚNG layer đang làm việc; chuyển active đi thì tay cầm chỉnh kích cỡ
      // nhảy sang một layer khác (trông như "mất tay cầm"), và bật lại layer cũ cũng vẫn không có tay
      // cầm vì nó không còn là layer đang chọn. Các trình chỉnh ảnh (Photoshop…) cũng giữ layer đang
      // chọn khi ẩn. Hàng trong bảng Lớp vẫn sáng nên luôn biết đang chọn layer nào.
      this.saveLayerLayout();
    },
    // Khóa/mở khóa layer (chống xóa/đổi tên/di chuyển nhầm).
    toggleLayerLock(id) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      l.locked = !l.locked;
      this.saveLayerLayout();
    },
    // Lưu bố cục layer (danh sách + layer active) để khôi phục khi tải lại trang.
    saveLayerLayout() {
      // §5.2: canvasLayers chứa ảnh dạng data-URL (canvas.toDataURL) nên mỗi mutation ghi vài MB
      // vào localStorage (quota ~5MB) và được gọi ở 57 chỗ. QuotaExceededError TRƯỚC ĐÂY bị nuốt
      // (chỉ console.error) -> người dùng mất bố cục mà không biết vì sao. Nay báo rõ MỘT LẦN.
      // (Không tự ý bỏ data-URL khi lưu: bản khôi phục chưa hỗ trợ layer thiếu ảnh — cần làm cả 2 phía.)
      try {
        localStorage.setItem('fabrikai.layers', JSON.stringify({ layers: this.canvasLayers, activeLayerId: this.activeLayerId, selectedLayerIds: this.selectedLayerIds, layerGroups: this.layerGroups }));
      } catch (e) {
        console.error('studio saveLayerLayout failed (vượt quota localStorage?)', e);
        if (!this._layoutSaveWarned) {
          this._layoutSaveWarned = true;
          this.toast('Trang vẽ quá lớn để tự lưu vào trình duyệt — bố cục có thể mất khi tải lại. Hãy xuất/lưu bớt ảnh.', 'error');
        }
      }
    },
    // Lưu VẬT LÝ (nút Save): flush toàn bộ trạng thái + thông báo thành công.
    saveNow() { this.saveLayerLayout(); this.toast('Đã lưu trang.'); },
    // ── Cài đặt status bar (snap · nền canvas · inspector · bề rộng dock) ──
    saveBarSettings() { try { localStorage.setItem('fabrikai.bar', JSON.stringify({ mainView: this.mainView, snapGrid: this.snapGrid, canvasBg: this.canvasBg, inspectorOpen: this.inspectorOpen, leftPanelOpen: this.leftPanelOpen, outputDockOpen: this.outputDockOpen, leftDockWidth: this.leftDockWidth, outputDockWidth: this.outputDockWidth, inspectorWidth: this.inspectorWidth })); } catch (e) { /* bỏ qua */ } },
    restoreBarSettings() { try { const d = JSON.parse(localStorage.getItem('fabrikai.bar') || 'null'); if (!d) return; if (d.mainView === 'grid' || d.mainView === 'canvas') this.mainView = d.mainView; if (d.snapGrid != null) this.snapGrid = Number(d.snapGrid) || 0; if (d.canvasBg) this.canvasBg = d.canvasBg; if (d.inspectorOpen != null) this.inspectorOpen = !!d.inspectorOpen; if (d.leftPanelOpen != null) this.leftPanelOpen = !!d.leftPanelOpen; if (d.outputDockOpen != null) this.outputDockOpen = !!d.outputDockOpen; if (d.leftDockWidth != null) this.leftDockWidth = Number(d.leftDockWidth) || this.leftDockWidth; if (d.outputDockWidth != null) this.outputDockWidth = Number(d.outputDockWidth) || this.outputDockWidth; if (d.inspectorWidth != null) this.inspectorWidth = Number(d.inspectorWidth) || this.inspectorWidth; } catch (e) { /* bỏ qua */ } },
    // ── Tuỳ chọn XEM LƯỚI KẾT QUẢ (đợt 55) ──
    // Khoá RIÊNG, không nhét vào 'fabrikai.bar': bar settings là chrome của khung làm việc (dock,
    // bề rộng, nền canvas), còn đây là cách người dùng muốn NHÌN danh sách ảnh. Hai mối quan tâm
    // khác nhau, hai vòng đời khác nhau — nhét chung là mỗi lần đổi cỡ lưới lại ghi cả bề rộng dock.
    saveOutputPrefs() {
      try {
        localStorage.setItem('fabrikai.outputs', JSON.stringify({
          sortBy: this.outputSortBy, density: this.outputDensity, filterProject: this.outputFilterProject,
        }));
      } catch (e) { /* chế độ riêng tư: bỏ qua */ }
    },
    restoreOutputPrefs() {
      try {
        const d = JSON.parse(localStorage.getItem('fabrikai.outputs') || 'null');
        if (!d) return;
        if (['new', 'old', 'name', 'running'].includes(d.sortBy)) this.outputSortBy = d.sortBy;
        if (['s', 'm', 'l'].includes(d.density)) this.outputDensity = d.density;
        if (d.filterProject != null) this.outputFilterProject = !!d.filterProject;
      } catch (e) { /* dữ liệu hỏng: giữ mặc định */ }
    },

    // ── Ghi nhớ cài đặt prompt của người dùng (localStorage) — ƯU TIÊN hơn dữ liệu DB ──
    // Lưu negative prompt + prefix/suffix + 3 checkbox bật/tắt. Khi load lại trang,
    // giá trị local ghi đè lên defaults từ database (nếu đã từng chỉnh sửa ở đây).
    savePromptMemory() {
      try {
        localStorage.setItem('fabrikai.prompt-cfg', JSON.stringify({
          // Toàn bộ cài đặt người dùng trong Prompt Tạo Ảnh (tab Prompt + Nâng cao):
          imagePromptEn: this.imagePromptEn || '',
          creativeLevel: this.creativeLevel,
          texture: this.texture,
          variantCount: this.variantCount,
          imageRatio: this.imageRatio,
          imageRes: this.imageRes,
          imageSeed: this.imageSeed || '',
          negativePromptEn: this.negativePromptEn || '',
          promptPrefix: this.promptPrefix || '',
          promptSuffix: this.promptSuffix || '',
          promptUsePrefix: !!this.promptUsePrefix,
          promptUseSuffix: !!this.promptUseSuffix,
          promptUseNegative: !!this.promptUseNegative,
          bodyHeight: this.bodyHeight, bodyBuild: this.bodyBuild,
          bodyWaist: this.bodyWaist, bodyShoulders: this.bodyShoulders, bodyHips: this.bodyHips,
          hairStyle: this.hairStyle || '', hairColor: this.hairColor || '',
          imagePoseId: this.imagePoseId || '',
        }));
      } catch (e) { /* bỏ qua */ }
    },
    restorePromptMemory() {
      try {
        const d = JSON.parse(localStorage.getItem('fabrikai.prompt-cfg') || 'null');
        if (!d) return;
        if (typeof d.imagePromptEn === 'string') this.imagePromptEn = d.imagePromptEn;
        if (d.creativeLevel != null) this.creativeLevel = Number(d.creativeLevel);
        if (d.texture != null) this.texture = Number(d.texture);
        if (d.variantCount != null) this.variantCount = Number(d.variantCount);
        if (d.imageRatio) this.imageRatio = d.imageRatio;
        if (d.imageRes) this.imageRes = d.imageRes;
        if (d.imageSeed) this.imageSeed = d.imageSeed;
        if (typeof d.negativePromptEn === 'string') this.negativePromptEn = d.negativePromptEn;
        if (typeof d.promptPrefix === 'string') this.promptPrefix = d.promptPrefix;
        if (typeof d.promptSuffix === 'string') this.promptSuffix = d.promptSuffix;
        if (d.promptUsePrefix != null) this.promptUsePrefix = !!d.promptUsePrefix;
        if (d.promptUseSuffix != null) this.promptUseSuffix = !!d.promptUseSuffix;
        if (d.promptUseNegative != null) this.promptUseNegative = !!d.promptUseNegative;
        if (d.bodyHeight != null) this.bodyHeight = Number(d.bodyHeight);
        if (d.bodyBuild != null) this.bodyBuild = Number(d.bodyBuild);
        if (d.bodyWaist != null) this.bodyWaist = Number(d.bodyWaist);
        if (d.bodyShoulders != null) this.bodyShoulders = Number(d.bodyShoulders);
        if (d.bodyHips != null) this.bodyHips = Number(d.bodyHips);
        if (typeof d.hairStyle === 'string') this.hairStyle = d.hairStyle;
        if (typeof d.hairColor === 'string') this.hairColor = d.hairColor;
        if (d.imagePoseId) this.imagePoseId = d.imagePoseId;
      } catch (e) { /* bỏ qua */ }
    },
    clearPromptMemory() {
      try { localStorage.removeItem('fabrikai.prompt-cfg'); } catch (e) { /* bỏ qua */ }
      this.imagePromptEn = ''; this.promptPrefix = ''; this.promptSuffix = ''; this.negativePromptEn = '';
      this.imageSeed = ''; this.hairStyle = ''; this.hairColor = ''; this.imagePoseId = '';
      this.promptUsePrefix = true; this.promptUseSuffix = true; this.promptUseNegative = true;
    },
    /**
     * VÁ KÍCH THƯỚC cho layer thiếu baseW/baseH — dữ liệu lưu từ phiên bản TRƯỚC khi có hai trường này
     * sẽ khôi phục về với baseW/baseH = null. Hệ quả đo được (không có lỗi nào hiện ra):
     *   · tay cầm chỉnh kích cỡ KHÔNG hiện, vì vị trí tay cầm tính theo kích thước layer;
     *   · khung logic của layer thành 1×1px nên căn lề · chia đều · fit chọn đều sai.
     * Nay đo lại từ chính ảnh của layer (cạnh dài tối đa 512 — đúng quy ước của pushCanvasLayer) rồi
     * ghi vào: layer cũ TỰ LÀNH sau một lần tải, người dùng không phải xóa rồi thêm lại.
     * KHÔNG đụng tới vị trí/scale đã lưu (khác _positionByImageSize — hàm đó còn xếp lại chỗ đứng).
     */
    ensureLayerSizes() {
      const MAX = 512;
      this.canvasLayers.forEach((l) => {
        if (!l.image || (Number(l.baseW) > 0 && Number(l.baseH) > 0)) return;
        const img = new Image();
        img.onload = () => {
          const nw = img.naturalWidth || 0, nh = img.naturalHeight || 0;
          if (nw < 1 || nh < 1) return;
          const cap = Math.min(1, MAX / nw, MAX / nh);
          l.baseW = Math.max(1, Math.round(nw * cap));
          l.baseH = Math.max(1, Math.round(nh * cap));
          this.saveLayerLayout();
        };
        img.src = l.image;
      });
    },
    // Khôi phục bố cục layer; bỏ layer 'gen' đã bị xóa khỏi output, giữ layer 'source' (URL vẫn hợp lệ).
    restoreLayerLayout() {
      try {
        const raw = localStorage.getItem('fabrikai.layers');
        if (!raw) return;
        const d = JSON.parse(raw);
        const genIds = new Set((this.generations || []).map((g) => g.id));
        this.canvasLayers = (Array.isArray(d.layers) ? d.layers : [])
          .filter((l) => l && l.image)
          .filter((l) => l.kind !== 'gen' || (l.genId != null && genIds.has(Number(l.genId))))
          .map((l) => ({ id: l.id, kind: l.kind, name: l.name, image: l.image, genId: l.genId, visible: l.visible !== false, locked: !!l.locked, x: Number(l.x) || 0, y: Number(l.y) || 0, scale: (l.scale != null ? Number(l.scale) : 1) || 1, rotation: Number(l.rotation) || 0, opacity: l.opacity != null ? Number(l.opacity) : 1, blend: l.blend || 'normal', baseW: Number(l.baseW) || null, baseH: Number(l.baseH) || null, flipX: !!l.flipX, flipY: !!l.flipY, groupId: l.groupId || '' }));
        // Khôi phục NHÓM (giữ nhóm còn ≥2 thành viên; nhóm thiếu thành viên → tách).
        const ids = new Set(this.canvasLayers.map((l) => l.id));
        this.layerGroups = (Array.isArray(d.layerGroups) ? d.layerGroups : [])
          .map((g) => ({ id: g.id, name: g.name || 'Nhóm', layerIds: (Array.isArray(g.layerIds) ? g.layerIds : []).filter((id) => ids.has(id)), rotation: Number(g.rotation) || 0, scale: Number(g.scale) || 1 }))
          .filter((g) => g.layerIds.length >= 2);
        const gids = new Set(this.layerGroups.map((g) => g.id));
        this.canvasLayers.forEach((l) => { if (l.groupId && !gids.has(l.groupId)) l.groupId = ''; });
        const active = this.canvasLayers.find((l) => l.id === d.activeLayerId && l.visible !== false);
        if (active) this._setActive(active.id); else this._setActive('');
        this.selectedLayerIds = (Array.isArray(d.selectedLayerIds) ? d.selectedLayerIds : []).filter((id) => this.canvasLayers.some((l) => l.id === id) && id !== this.activeLayerId);
        // Vá kích thước cho layer CŨ rồi mới lưu (xem ensureLayerSizes — thiếu bước này thì layer khôi
        // phục từ phiên bản trước về với baseW/baseH = null và KHÔNG có tay cầm chỉnh kích cỡ).
        this.ensureLayerSizes();
        this.saveLayerLayout();
      } catch (e) { this.canvasLayers = []; this.activeLayerId = ''; this.saveLayerLayout(); }
    },
};
