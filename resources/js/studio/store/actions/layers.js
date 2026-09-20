// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — miền: layer canvas: CRUD · transform · erase/draw brush · căn chỉnh · composite · cài đặt thanh.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt file gốc.
import { apiError, CSRF } from '../helpers.js';
export const layersActions = {
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
    saveBarSettings() { try { localStorage.setItem('fabrikai.bar', JSON.stringify({ snapGrid: this.snapGrid, canvasBg: this.canvasBg, inspectorOpen: this.inspectorOpen, leftPanelOpen: this.leftPanelOpen, outputDockOpen: this.outputDockOpen, leftDockWidth: this.leftDockWidth, outputDockWidth: this.outputDockWidth, inspectorWidth: this.inspectorWidth })); } catch (e) { /* bỏ qua */ } },
    restoreBarSettings() { try { const d = JSON.parse(localStorage.getItem('fabrikai.bar') || 'null'); if (!d) return; if (d.snapGrid != null) this.snapGrid = Number(d.snapGrid) || 0; if (d.canvasBg) this.canvasBg = d.canvasBg; if (d.inspectorOpen != null) this.inspectorOpen = !!d.inspectorOpen; if (d.leftPanelOpen != null) this.leftPanelOpen = !!d.leftPanelOpen; if (d.outputDockOpen != null) this.outputDockOpen = !!d.outputDockOpen; if (d.leftDockWidth != null) this.leftDockWidth = Number(d.leftDockWidth) || this.leftDockWidth; if (d.outputDockWidth != null) this.outputDockWidth = Number(d.outputDockWidth) || this.outputDockWidth; if (d.inspectorWidth != null) this.inspectorWidth = Number(d.inspectorWidth) || this.inspectorWidth; } catch (e) { /* bỏ qua */ } },
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
