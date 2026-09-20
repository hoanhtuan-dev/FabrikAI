// TÁCH TIẾP từ layers.js (đợt tối ưu 2026-09-24) — miền: layer canvas: CRUD · chọn · nhóm · reorder · transform cơ bản · lật.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt bản gộp.
import { apiError, CSRF } from '../helpers.js';
export const layerCoreActions = {
    pushCanvasLayer(id, kind, name, image, genId) {
      if (!id || !image) return;
      if (this.canvasLayers.some(l => l.id === id)) return;
      const i = this.canvasLayers.length;
      const x = (i % 3) * 360, y = Math.floor(i / 3) * 460; // vị trí tạm, sẽ căn lại theo kích thước thật
      this.canvasLayers.push({ id, kind, name, image, genId, visible: true, locked: false, x, y, scale: 1, rotation: 0, opacity: 1, blend: 'normal', flipX: false, flipY: false });
      this.saveLayerLayout();
      this._positionByImageSize(id, image);
    },
    // Map tỷ lệ khung hình sang kích thước layer (base = 1024 cho cạnh DÀI hơn).
    ratioToSize(r) {
      const map = { '1:1': [1, 1], '4:3': [4, 3], '3:4': [3, 4], '9:16': [9, 16], '16:9': [16, 9], '4:5': [4, 5], '21:9': [21, 9], '2:3': [2, 3] };
      const [rw, rh] = map[r] || [1, 1];
      const base = 1024;
      const w = Math.round(rw >= rh ? base : base * (rw / rh));
      const h = Math.round(rh >= rw ? base : base * (rh / rw));
      return { w, h };
    },
    // Thêm 1 layer TRỐNG (trong suốt) để vẽ — nền tảng cho hiệu ứng sau (brush/vẽ tự do GIMP/PS).
    async addBlankLayer(bg = null, ratio = null) {
      this.pushHistory();
      const src = this.activeLayer;
      // Ưu tiên 1: nếu đang có ẢNH CHỌN (active layer có image) → tạo layer mới ĐÚNG KÍCH THƯỚC ảnh hiện tại.
      // Ưu tiên 2: tạo layer TRỐNG (không chọn ảnh) → tôn trọng preset tỷ lệ khung hình (imageRatio / ratio).
      let w = 0, h = 0;
      if (src && src.image) {
        try {
          const img = await this._loadImageSrc(src.image);
          if (img.naturalWidth && img.naturalHeight) { w = img.naturalWidth; h = img.naturalHeight; }
        } catch (e) { /* giữ mặc định (theo tỷ lệ) */ }
      }
      if (!w || !h) { const rt = this.ratioToSize(ratio || this.imageRatio); w = rt.w; h = rt.h; }
      // baseW/baseH = kích thước HIỂN THỊ (cạnh dài tối đa 512 — khớp CSS max-w/max-h của <img>).
      // Ảnh canvas vẫn giữ FULL w×h, chỉ có baseW/baseH được cap để overlay/mask/pointer khớp 1:1.
      const MAXB = 512;
      const bcap = Math.min(1, MAXB / w, MAXB / h);
      const bw = Math.max(1, Math.round(w * bcap));
      const bh = Math.max(1, Math.round(h * bcap));
      const canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      if (bg) {
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, w, h);
      }
      const url = canvas.toDataURL('image/png');
      const id = 'blank-' + Date.now();
      // Thêm TRÙNG KHỚP với layer active (cùng x/y/scale/rotation/blend), không flow ra chỗ khác.
      this.canvasLayers.push({
        id, kind: 'source', name: bg ? 'Layer màu' : 'Layer trong suốt', image: url, genId: null,
        visible: true, locked: false,
        x: src ? (src.x || 0) : 0, y: src ? (src.y || 0) : 0,
        scale: src ? (src.scale || 1) : 1, rotation: src ? (src.rotation || 0) : 0,
        opacity: src ? (src.opacity != null ? src.opacity : 1) : 1,
        blend: src ? (src.blend || 'normal') : 'normal', flipX: false, flipY: false,
        baseW: bw, baseH: bh,
      });
      this.saveLayerLayout();
      // Highlight layer mới (viền nổi bật) rồi tự tắt sau 2.5s hoặc khi người dùng chọn layer khác.
      this.highlightLayerId = id;
      clearTimeout(this._highlightTimer);
      this._highlightTimer = setTimeout(() => { if (this.highlightLayerId === id) this.highlightLayerId = ''; }, 2500);
      this.setActiveLayer(id);
      this.toast(bg ? 'Đã thêm layer màu (trùng vị trí layer đang chọn).' : 'Đã thêm layer trong suốt (trùng vị trí layer đang chọn).');
    },
    // Tô màu TOÀN BỘ layer đang chọn bằng màu hiện tại (inpaintFillColor).
    async fillActiveLayer() {
      const l = this.activeLayer;
      if (!l) { this.toast('Chưa có layer để tô — thêm 1 layer trước.', 'error'); return; }
      this.pushHistory();
      let w = 1024, h = 1024;
      if (l.image) {
        try {
          const img = await this._loadImageSrc(l.image);
          if (img.naturalWidth && img.naturalHeight) { w = img.naturalWidth; h = img.naturalHeight; }
        } catch (e) { /* giữ mặc định */ }
      }
      const canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = this.inpaintFillColor || '#ffffff';
      ctx.fillRect(0, 0, w, h);
      l.image = canvas.toDataURL('image/png');
      this.saveLayerLayout();
      this.toast('Đã tô màu toàn bộ layer.');
    },
    // Lưu layer active (kể cả layer vẽ/fill/duplicate chưa từng là generation) vào Output.
    async saveActiveLayerToOutput() {
      const l = this.activeLayer;
      if (!l || !l.image) { this.toast('Chưa có layer để lưu.', 'error'); return; }
      // KHÔNG lưu trùng: ảnh đã có trong Output thì dừng ngay, không gọi máy chủ (tránh rác Outputs).
      if (this.activeLayerInOutputs) { this.toast('Ảnh này đã có trong Output — không lưu trùng.', 'error'); return; }
      try {
        // Ảnh ĐÃ nằm trên máy chủ (layer lấy từ Output / ảnh nguồn) ⇒ gửi ĐƯỜNG DẪN, KHÔNG tải lại byte nào.
        // Trước đây luôn tải lên lại: đo thật layer 2K ≈ 0,9 MB và layer ghép 2400×2400 ≈ 4,4 MB cho MỘT
        // thao tác lưu — trên mạng thật là hàng chục giây chờ vô ích.
        const raw = String(l.image);
        const isServerUrl = raw.startsWith('/storage/') || raw.startsWith(location.origin + '/storage/');
        let d;
        if (isServerUrl) {
          d = await this.api('/api/layers/save', {
            source_url: raw.replace(location.origin, ''),
            name: l.name || '',
            ...this.projectField(),
          });
        } else {
          // Layer GHÉP trên canvas (data URL): chưa có trên máy chủ nên buộc phải tải lên.
          const blob = await (await fetch(raw)).blob();
          const fd = new FormData();
          fd.append('image', new File([blob], 'layer-' + Date.now() + '.png', { type: 'image/png' }));
          fd.append('name', l.name || '');
          const pid = this.appliedProjectId();
          if (pid) fd.append('project_id', String(pid));
          const res = await fetch('/api/layers/save', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' }, body: fd });
          d = await res.json().catch(() => ({}));
          if (!res.ok) throw apiError(d, 'Không lưu được.');
        }
        if (!d || !d.generation_id) throw apiError(d, 'Không lưu được.');
        // Bản ghi THẬT (id trong CSDL) ⇒ ảnh vào được Thư viện và còn nguyên sau khi tải lại trang.
        // Dùng unshift trực tiếp thay vì addGen: addGen đẩy thêm một layer canvas nữa, mà layer đang lưu
        // đã có sẵn trên canvas rồi (sẽ thành hai layer trùng nhau).
        this.generations.unshift({
          id: d.generation_id, type: 'image', status: 'completed', model: 'layer', provider: 'layer',
          media_url: d.media_url, prompt: 'Lưu layer từ canvas', error: null, credits_cost: 0,
          created_at: 'Vừa lưu', project_id: d.project_id ?? null,
          project: (d.project_id && this.appliedProject && Number(this.appliedProject.id) === Number(d.project_id)) ? this.appliedProject.name : null,
        });
        // Đánh dấu NGAY layer này đã có bản trong Output ⇒ nút "Lưu Output" hết sáng, và lần bấm sau
        // bị chặn bởi activeLayerInOutputs (layer ghép bằng data URL không đổi danh tính sau khi lưu,
        // nên nếu không đánh dấu thì người dùng vẫn thấy nút sáng và bấm lại sẽ tạo bản trùng).
        l.savedOutputId = d.generation_id;
        this.toast('Đã lưu layer vào Output và Thư viện.');
      } catch (e) { this.failToast(e, 'Không lưu được.'); }
    },
    // Đo kích thước thật của ảnh rồi xếp theo flow: hàng ngang (có gap), tự xuống hàng khi quá rộng.
    _positionByImageSize(id, image) {
      const MAX = 512, GAP = 24;
      const img = new Image();
      img.onload = () => {
        const l = this.canvasLayers.find((x) => x.id === id);
        if (!l) return;
        const base = Math.min(1, MAX / img.naturalWidth, MAX / img.naturalHeight);
        l.baseW = img.naturalWidth * base;
        l.baseH = img.naturalHeight * base;
        const prev = this.canvasLayers.filter((x) => x.id !== id && x.baseW != null);
        if (!prev.length) { l.x = 0; l.y = 0; }
        else {
          const last = prev[prev.length - 1];
          const lw = (last.baseW || MAX) * (last.scale || 1);
          const lh = (last.baseH || MAX) * (last.scale || 1);
          let nx = (last.x || 0) + lw / 2 + l.baseW / 2 + GAP;
          let ny = last.y || 0;
          const ROW_MAX = 3 * (MAX + GAP);
          if (nx > ROW_MAX) { nx = 0; ny = (last.y || 0) + lh + GAP; }
          l.x = nx; l.y = ny;
        }
        this.saveLayerLayout();
      };
      img.onerror = () => {};
      img.src = image;
    },
    _setActive(id) { if (!id) { this.activeLayerId = ''; this.editSource = null; this.previewId = null; this.preview = null; return; } const l = this.canvasLayers.find(x => x.id === id); if (!l) return; if (l.visible === false) l.visible = true; this.activeLayerId = id; if (this.highlightLayerId && this.highlightLayerId !== id) this.highlightLayerId = ''; if (l.kind === 'source') { this.editSource = { url: l.image, name: l.name }; this.previewId = null; this.preview = null; } else if (l.genId) { const g = this.generations.find(x => x.id === l.genId); if (g) { this.previewId = g.id; this.preview = { id: g.id, media_url: g.media_url, type: g.type || 'image', status: g.status || 'completed' }; } this.editSource = null; } },
    setActiveLayer(id) { this._setActive(id); this.selectedLayerIds = []; this.saveLayerLayout(); },
    // Shift+click: thêm/bỏ một layer vào nhóm chọn nhiều (không xáo trộn nhóm).
    isSelected(id) { return this.selectedIds.includes(id); },
    // Nhóm có đang là ĐỐI TƯỢNG được chọn trọn (để highlight folder thay vì layer thành viên).
    isGroupActive(gid) { const a = this.activeLayer; if (!a || a.groupId !== gid) return false; const g = this.layerGroups.find(x => x.id === gid); return !!g && g.layerIds.length > 1 && this.selectedIds.length === g.layerIds.length; },
    shiftSelectLayer(id) { const l = this.canvasLayers.find(x => x.id === id); if (!l) return; const g = l.groupId ? this.layerGroups.find(x => x.id === l.groupId) : null; const toggles = (g && g.layerIds.length > 1) ? g.layerIds.filter(xs => this.canvasLayers.some(l2 => l2.id === xs)) : [id]; const prev = this.activeLayerId; const already = toggles.some(xs => this.selectedIds.includes(xs)); if (already) { this.selectedLayerIds = this.selectedLayerIds.filter(xs => !toggles.includes(xs)); if (toggles.includes(prev)) { const rest = this.selectedIds.length ? this.selectedIds[this.selectedIds.length - 1] : ''; this._setActive(rest); } } else { this.selectedLayerIds = this.selectedLayerIds.filter(xs => !toggles.includes(xs)); if (prev && !this.selectedLayerIds.includes(prev)) this.selectedLayerIds.push(prev); toggles.forEach(xs => { if (xs !== prev && !this.selectedLayerIds.includes(xs)) this.selectedLayerIds.push(xs); }); this._setActive(toggles[toggles.length - 1]); } this.saveLayerLayout(); },
    clearSelection() { this.selectedLayerIds = []; if (this.activeLayerId) this.saveLayerLayout(); },
    // Trả về layer của một nhóm (nếu layer thuộc nhóm) cho thao tác "chọn cả nhóm".
    selectLayerWithGroup(id) {
      const lid = (id && typeof id === 'object') ? id.id : id; // chấp nhận cả object lẫn id
      const l = this.canvasLayers.find(x => x.id === lid); if (!l) return;
      const g = l.groupId ? this.layerGroups.find(x => x.id === l.groupId) : null;
      if (g && g.layerIds.length > 1 && g.layerIds.includes(id)) {
        this._setActive(id);
        this.selectedLayerIds = g.layerIds.filter(x => x !== id && this.canvasLayers.some(l2 => l2.id === x));
      } else {
        this.setActiveLayer(id);
      }
      this.saveLayerLayout();
    },
    // Tạo NHÓM từ các layer đang chọn (≥2) — tiền đề cho tính năng group đầy đủ sau.
    groupSelection() {
      const ids = this.selectedIds; if (ids.length < 2) { this.toast('Chọn ít nhất 2 layer để tạo nhóm.', 'error'); return; }
      if (ids.some(id => { const l = this.canvasLayers.find(x => x.id === id); return l && l.groupId; })) { this.toast('Đã có layer thuộc nhóm — chọn các layer chưa nhóm.', 'error'); return; }
      const gid = 'g-' + Date.now();
      this.layerGroups.push({ id: gid, name: 'Nhóm ' + (this.layerGroups.length + 1), layerIds: ids, rotation: 0, scale: 1 });
      this.canvasLayers.forEach(l => { if (ids.includes(l.id)) l.groupId = gid; });
      this._setActive(ids[0]);
      this.selectedLayerIds = ids.filter(x => x !== ids[0]);
      this.saveLayerLayout();
      this.toast('Đã tạo nhóm ' + ids.length + ' layer — click 1 layer trong nhóm sẽ chọn cả nhóm.');
    },
    // Tách nhóm: bỏ groupId của các layer đang chọn + xóa nhóm rỗng.
    ungroupSelection() {
      const gids = new Set();
      this.selectedIds.forEach(id => { const l = this.canvasLayers.find(x => x.id === id); if (l && l.groupId) gids.add(l.groupId); });
      if (!gids.size) { this.toast('Không có nhóm nào để tách.', 'error'); return; }
      this.canvasLayers.forEach(l => { if (l.groupId && gids.has(l.groupId)) l.groupId = ''; });
      this.layerGroups = this.layerGroups.filter(g => !gids.has(g.id));
      this.saveLayerLayout();
      this.toast('Đã tách nhóm — các layer trở về độc lập.');
    },
    // Chọn toàn bộ layer của một nhóm (khi nhấn folder).
    selectGroup(gid) { const g = this.layerGroups.find(x => x.id === gid); if (!g) return; const ids = g.layerIds.filter(id => this.canvasLayers.some(l => l.id === id)); if (!ids.length) return; this._setActive(ids[ids.length - 1]); this.selectedLayerIds = ids.filter(x => x !== ids[ids.length - 1]); this.saveLayerLayout(); },
    // Tách riêng một nhóm (nút trong dock thuộc tính).
    ungroupGroup(gid) { const g = this.layerGroups.find(x => x.id === gid); if (!g) return; this.canvasLayers.forEach(l => { if (l.groupId === gid) l.groupId = ''; }); this.layerGroups = this.layerGroups.filter(x => x.id !== gid); this.saveLayerLayout(); this.toast('Đã tách nhóm.'); },
    // ── Nhóm layer: khóa / nhân đôi / xóa (giống tính năng của layer) ──
    toggleGroupLock(gid) {
      const g = this.layerGroups.find(x => x.id === gid); if (!g) return;
      const members = this.canvasLayers.filter(l => g.layerIds.includes(l.id));
      if (!members.length) return;
      const anyUnlocked = members.some(l => !l.locked);
      members.forEach(l => { l.locked = anyUnlocked; });
      this.saveLayerLayout();
      this.toast(anyUnlocked ? 'Đã khóa nhóm.' : 'Đã mở khóa nhóm.');
    },
    duplicateGroup(gid) {
      const g = this.layerGroups.find(x => x.id === gid); if (!g) return;
      const originals = g.layerIds.map(id => this.canvasLayers.find(l => l.id === id)).filter(Boolean);
      if (!originals.length) return;
      this.pushHistory();
      const ts = Date.now(), gid2 = 'g2-' + ts, newIds = [];
      originals.forEach(l => {
        const cid = l.id + '-dup-' + ts + '-' + newIds.length;
        this.canvasLayers.push({ id: cid, kind: l.kind, name: (l.name || 'Ảnh') + ' (bản sao)', image: l.image, genId: l.genId, visible: true, locked: false, x: (l.x || 0) + 40, y: (l.y || 0) + 40, scale: l.scale || 1, rotation: l.rotation || 0, opacity: l.opacity != null ? l.opacity : 1, blend: l.blend || 'normal', flipX: !!l.flipX, flipY: !!l.flipY, baseW: l.baseW, baseH: l.baseH, groupId: gid2 });
        newIds.push(cid);
      });
      this.layerGroups.push({ id: gid2, name: 'Nhóm ' + (this.layerGroups.length + 1), layerIds: newIds, rotation: 0, scale: 1 });
      this._setActive(newIds[newIds.length - 1]);
      this.selectedLayerIds = newIds.filter(x => x !== newIds[newIds.length - 1]);
      this.saveLayerLayout();
      this.toast('Đã nhân đôi nhóm (' + newIds.length + ' layer).');
    },
    deleteGroup(gid) {
      const g = this.layerGroups.find(x => x.id === gid); if (!g) return;
      this.pushHistory();
      if (g.layerIds.includes(this.activeLayerId)) this._setActive('');
      this.canvasLayers = this.canvasLayers.filter(l => l.groupId !== gid);
      this.layerGroups = this.layerGroups.filter(x => x.id !== gid);
      this.selectedLayerIds = [];
      this.saveLayerLayout();
      this.toast('Đã xóa nhóm.');
    },
    // ── Tải ảnh một layer về máy (dùng cho tải hàng loạt) ──
    async _downloadLayerImage(l) {
      if (!l || !l.image) return false;
      try {
        const res = await fetch(l.image); if (!res.ok) throw new Error();
        const blob = await res.blob();
        const ext = blob.type === 'image/png' ? 'png' : blob.type === 'image/webp' ? 'webp' : blob.type === 'image/gif' ? 'gif' : 'jpg';
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = (((l.name || 'anh').replace(/\.[^.]+$/, '') || 'anh') + '.' + ext);
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        return true;
      } catch (e) { return false; }
    },
    // Tải HÀNG LOẠT các layer đang chọn (popup chọn nhiều).
    downloadSelection() {
      const sels = this.selection; if (!sels.length) { this.toast('Chưa chọn layer.', 'error'); return; }
      let ok = 0;
      sels.forEach((l, i) => setTimeout(async () => { if (await this._downloadLayerImage(l)) ok++; if (i === sels.length - 1) this.toast('Đã tải ' + ok + '/' + sels.length + ' layer.'); }, i * 300));
    },
    // Đổi tên nhóm (có thể rename trên canvas).
    renameGroup(gid, name) {
      const g = this.layerGroups.find(x => x.id === gid); if (!g) return;
      const n = (name || '').trim(); if (!n) return;
      g.name = n;
      this.saveLayerLayout();
    },
    // Tên nhóm của layer NẾU layer này là đại diện (trên cùng) của nhóm — để hiện badge canvas.
    groupLabel(l) {
      if (!l || !l.groupId) return '';
      const g = this.layerGroups.find(x => x.id === l.groupId); if (!g) return '';
      const members = this.canvasLayers.filter(x => x.groupId === l.groupId);
      const top = members[members.length - 1];
      return (top && top.id === l.id) ? (g.name || 'Nhóm') : '';
    },
    groupOf(id) { const l = this.canvasLayers.find(x => x.id === id); return l ? (this.layerGroups.find(g => g.id === l.groupId) || null) : null; },
    // Quét (marquee) chọn nhiều layer trong một hình chữ nhật — toạ độ canvas.
    selectInRect(x0, y0, x1, y1) {
      const mnx = Math.min(x0, x1), mxx = Math.max(x0, x1), mny = Math.min(y0, y1), mxy = Math.max(y0, y1);
      const hits = [];
      this.canvasLayers.forEach(l => {
        if (l.visible === false) return;
        const b = this._layerBox(l); const cx = l.x || 0, cy = l.y || 0;
        const lx = cx - b.w / 2, rx = cx + b.w / 2, ty = cy - b.h / 2, by = cy + b.h / 2;
        if (lx <= mxx && rx >= mnx && ty <= mxy && by >= mny) hits.push(l);
      });
      if (!hits.length) { this.deselectAll(); return; }
      const ids = new Set();
      hits.forEach(l => { ids.add(l.id); const g = l.groupId ? this.layerGroups.find(x => x.id === l.groupId) : null; if (g) g.layerIds.forEach(x => ids.add(x)); });
      const arr = [...ids];
      this._setActive(arr[arr.length - 1]);
      this.selectedLayerIds = arr.filter(x => x !== arr[arr.length - 1]);
      this.saveLayerLayout();
    },
    selectLayer(item) { if (!item) return; this.setActiveLayer(item.id); },
    // Gỡ layer KHỎI CANVAS (chỉ ảnh hưởng hiển thị) — KHÔNG xóa output/ảnh kết quả hay file nguồn.
    deleteLayer(item) {
      if (!item) return;
      if (item.locked) { this.toast('Layer đang khóa — mở khóa trước khi gỡ.', 'error'); return; }
      this.pushHistory();
      const wasSource = item.kind === 'source' || item.id === 'source';
      this.canvasLayers = this.canvasLayers.filter((l) => l.id !== item.id);
      if (wasSource) this.editSource = null;
      if (this.activeLayerId === item.id) {
        const next = this.canvasLayers.find((x) => x.visible !== false);
        if (next) this.selectLayer(next);
        else { this.activeLayerId = ''; this.editSource = null; this.previewId = null; this.preview = null; }
      }
      this.saveLayerLayout();
    },
    // Bỏ ảnh nguồn khỏi canvas (không xóa file/output).
    clearSource() {
      this.editSource = null;
      this.canvasLayers = this.canvasLayers.filter((l) => l.id !== 'source');
      if (this.activeLayerId === 'source') {
        const next = this.canvasLayers.find((x) => x.visible !== false);
        if (next) this.selectLayer(next);
        else { this.activeLayerId = ''; this.previewId = null; this.preview = null; }
      }
      this.saveLayerLayout();
    },
    // Bỏ ĐÚNG ảnh nguồn đang dùng (id 'source' HOẶC các layer src-* do thêm nhiều ảnh) khỏi canvas.
    // clearSource() cũ chỉ xoá layer id 'source' → với ảnh nguồn có id 'src-…' thì ảnh vẫn nằm
    // trên canvas dù nút "Bỏ ảnh nguồn" đã ẩn (trạng thái treo, gây nhầm).
    removeEditSource() {
      const src = this.editSource;
      if (!src) return;
      const pool = this.canvasLayers.filter((x) => x.kind === 'source' && x.image === src.url);
      if (!pool.length) { this.editSource = null; this.saveLayerLayout(); return; }
      // Ưu tiên layer đang active (nhiều layer có thể dùng chung URL sau duplicate).
      const l = pool.find((x) => x.id === this.activeLayerId) || pool[0];
      this.deleteLayer(l); // xoá layer + chuyển active đúng cách (tôn trọng lock)
    },
    // Mở popup xác nhận dọn canvas (LayersPanel) — tự reset sau 5s nếu không confirm.
    openClearCanvasConfirm() {
      this.confirmClearCanvasOpen = true;
      clearTimeout(this._clearCanvasTimer);
      this._clearCanvasTimer = setTimeout(() => { this.confirmClearCanvasOpen = false; }, 5000);
    },
    // Dọn sạch canvas + toàn bộ layer (chỉ xóa trạng thái hiển thị — KHÔNG xóa output/ảnh kết quả).
    cleanCanvas() {
      this.pushHistory();
      this.confirmClearCanvasOpen = false;
      this.previewId = null;
      this.preview = null;
      this.editSource = null;
      this.canvasLayers = [];
      this.layerGroups = [];
      this.selectedLayerIds = [];
      this.activeLayerId = '';
      this.palette = [];
      this.pan = { x: 0, y: 0 };
      this.zoom = 1;
      this.saveLayerLayout();
      this.toast('Đã dọn canvas — ảnh kết quả vẫn còn trong Kết quả/Thư viện.');
    },
    // Đổi tên layer. Với layer ảnh kết quả (gen), tên mới cũng được lưu vào generation
    // để hiển thị đồng bộ ở Output/Thư viện.
    renameLayer(id, name) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      if (l.locked) { this.toast('Layer đang khóa.', 'error'); return; }
      const n = (name || '').trim();
      if (!n) return;
      l.name = n;
      if (l.kind === 'source' && this.editSource) this.editSource.name = n;
      if (l.kind === 'gen' && l.genId) {
        const g = this.generations.find((x) => x.id === l.genId);
        if (g) { g.meta = Object.assign({}, g.meta || {}, { name: n }); }
        // §5.2: trước đây.catch(() => {}) nuốt lỗi rồi VẪN toast "Đã đổi tên layer." — UI báo
        // thành công kể cả khi server không lưu (hết phiên / 500). Nay phản ánh đúng kết quả.
        fetch('/api/generations/' + l.genId + '/rename', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ name: n }) })
          .then((r) => {
            this.saveLayerLayout();
            if (!r.ok) throw new Error('HTTP ' + r.status);
            this.toast('Đã đổi tên layer.');
          })
          .catch((e) => {
            console.error('studio rename generation failed', e);
            this.toast('Đã đổi tên trên canvas nhưng CHƯA lưu được lên máy chủ — thử lại sau khi tải lại trang.', 'error');
          });
        return;
      }
      this.saveLayerLayout();
      this.toast('Đã đổi tên layer.');
    },
    // Di chuyển layer lên/xuống TRONG NGĂN XẾP (không ảnh hưởng output).
    // Quy ước: mảng canvasLayers[0..n-1] = dưới→trên (zIndex = vị trí mảng); ngăn xếp HIỂN THỊ
    // front-first (layer trên cùng danh sách = đang ở TRƯỚC). Vì vậy:
    //   'up'   = lên đầu danh sách = RA PHÍA TRƯỚC (index +1)
    //   'down' = xuống cuối danh sách = VỀ PHÍA SAU (index -1)
    moveLayer(id, dir) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l || l.locked) return;
      this.pushHistory();
      const i = this.canvasLayers.findIndex((x) => x.id === id);
      const j = i + (dir === 'up' ? 1 : -1);
      if (i < 0 || j < 0 || j >= this.canvasLayers.length) return;
      const arr = this.canvasLayers.slice();
      const t = arr[i]; arr[i] = arr[j]; arr[j] = t;
      this.canvasLayers = arr;
      this.saveLayerLayout();
    },
    // ── Transform layer (vị trí / kích thước / xoay / opacity / blend) ──
    updateLayerTransform(id, patch) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      Object.assign(l, patch);
      this.saveLayerLayout();
    },
    resetLayerTransform(id) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      this.pushHistory();
      Object.assign(l, { x: 0, y: 0, scale: 1, rotation: 0, opacity: 1, blend: 'normal' });
      this.saveLayerLayout();
    },
    // Nhân đôi layer (giữ nguyên transform, tạo id + tên mới). Bản sao được đẩy lệch 12px để
    // phân biệt ngay với bản gốc (đang chồng khít) + viền highlight như layer mới.
    duplicateLayer(id) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      this.pushHistory();
      const copy = {
        ...l,
        id: id + '-copy-' + Date.now(),
        name: (l.name || 'Layer') + ' (bản sao)',
        x: (Number(l.x) || 0) + 12,
        y: (Number(l.y) || 0) + 12,
        groupId: '', // bản sao ĐƠN lẻ không kế thừa group nguồn
      };
      this.canvasLayers.push(copy);
      this.highlightLayerId = copy.id;
      clearTimeout(this._highlightTimer);
      this._highlightTimer = setTimeout(() => { if (this.highlightLayerId === copy.id) this.highlightLayerId = ''; }, 2500);
      this.setActiveLayer(copy.id);
      this.saveLayerLayout();
    },
    // Đưa layer lên trên cùng ('front') hoặc xuống dưới cùng ('back').
    bringLayerTo(id, where) {
      const i = this.canvasLayers.findIndex((x) => x.id === id);
      if (i < 0) return;
      this.pushHistory();
      const arr = this.canvasLayers.slice();
      const item = arr.splice(i, 1)[0];
      if (where === 'front') arr.push(item);
      else arr.unshift(item);
      this.canvasLayers = arr;
      this.saveLayerLayout();
    },
    // Đưa ĐƠN VỊ (group = nguyên nhóm, giữ thứ tự nội bộ) lên đầu / xuống đáy.
    bringUnitTo(id, where) {
      const l = this.canvasLayers.find((x) => x.id === id); if (!l) return;
      const ids = l.groupId ? this.canvasLayers.filter(x => x.groupId === l.groupId).map(x => x.id) : [id];
      this.pushHistory();
      const rest = this.canvasLayers.filter(x => !ids.includes(x.id));
      const unit = this.canvasLayers.filter(x => ids.includes(x.id));
      this.canvasLayers = where === 'front' ? [...rest, ...unit] : [...unit, ...rest];
      this.saveLayerLayout();
    },
    // Bật/tắt panel Layers dock (Designer Workspace) — CanvasStatusBar/LayersPanel header.
    toggleInspector() { this.inspectorOpen = !this.inspectorOpen; },
    toggleOutputDock() { this.outputDockOpen = !this.outputDockOpen; },
    // Kéo-thả sắp xếp: đặt layer 'id' NGAY TRƯỚC (placeAfter=false) hoặc NGAY SAU (true)
    // 'targetId' trong stack canvasLayers (index 0 = dưới cùng, cuối = trước nhất).
    // Layer bị khóa không cho kéo; thả quanh target khóa vẫn hợp lệ. No-op khi kéo lên chính nó.
    reorderLayer(id, targetId, placeAfter) {
      const arr = this.canvasLayers.slice();
      const i = arr.findIndex((x) => x.id === id);
      const t = arr.findIndex((x) => x.id === targetId);
      if (i < 0 || t < 0 || i === t) return;
      if (arr[i].locked) return;
      this.pushHistory();
      const item = arr.splice(i, 1)[0];
      let j = arr.findIndex((x) => x.id === targetId);
      if (placeAfter) j += 1;
      arr.splice(j, 0, item);
      this.canvasLayers = arr;
      this.saveLayerLayout();
    },
    // Lật ngang / lật dọc layer.
    toggleFlipX(id) { const l = this.canvasLayers.find((x) => x.id === id); if (!l) return; this.pushHistory(); l.flipX = !l.flipX; this.saveLayerLayout(); },
    toggleFlipY(id) { const l = this.canvasLayers.find((x) => x.id === id); if (!l) return; this.pushHistory(); l.flipY = !l.flipY; this.saveLayerLayout(); },
};
