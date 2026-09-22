// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — miền: bộ sưu tập/dự án · chia sẻ · thống kê shots · mẫu việc · workspace.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt file gốc.
import { apiError, userFacingError, CSRF } from '../helpers.js';
export const projectsActions = {
    // ═══════════════════════════════════════════════════════════════════
    // Dự án thiết kế (Project Workspace) — CRUD + workflow cho Designer.
    // ═══════════════════════════════════════════════════════════════════
    async loadProjects() {
      // §5.2: khi đang tải mà có yêu cầu mới, hàm cũ bỏ qua luôn -> nơi gọi (toggleArchived/
      // togglePending) tưởng đã refresh nhưng danh sách vẫn là bản cũ tới lần tải kế tiếp.
      // Nay đánh dấu để chạy lại ngay sau khi lượt đang chạy xong.
      if (this.projectLoading) { this._projectReloadQueued = true; return this.projects; }
      this.projectLoading = true;
      // Hàng đợi duyệt (Super Admin): ?scope=pending — bỏ param archived.
      const mkQuery = () => this.projectScope === 'pending'
        ? new URLSearchParams({ scope: 'pending' }).toString()
        : new URLSearchParams({ archived: this.projectsArchived ? '1' : '0' }).toString();
      try {
        let res = await fetch('/api/projects?' + mkQuery(), { headers: { Accept: 'application/json' } });
        if (res.status === 403 && this.projectScope === 'pending') {
          // Không (còn) là Super Admin → quay về scope cá nhân rồi tải lại.
          const msg = (await res.json().catch(() => ({}))).message || 'Bạn không có quyền xem hàng đợi duyệt.';
          this.projectScope = 'own';
          this.projectCanReview = false;
          this.toast(msg, 'error');
          res = await fetch('/api/projects?' + mkQuery(), { headers: { Accept: 'application/json' } });
        }
        if (res.status === 401 || res.status === 403) { this.setAuthStatus(res.status); return []; }
        const d = await res.json();
        this.projects = Array.isArray(d.items) ? d.items : [];
        if (d.statuses) this.projectStatuses = d.statuses;
        this.projectCanReview = !!d.can_review;
        if (d.scope === 'pending' || d.scope === 'own') this.projectScope = d.scope;
        if (Array.isArray(d.assignable)) this.assignableUsers = d.assignable;
        this.projectLoaded = true;
        return this.projects;
      } catch (e) {
        this.failToast(e, 'Không tải được dự án.');
        return this.projects;
      } finally {
        this.projectLoading = false;
        if (this._projectReloadQueued) { this._projectReloadQueued = false; this.loadProjects(); }
      }
    },
    async loadProject(id, opts = {}) {
      // reviewOnly: mở dự án người khác (scope=pending) → chỉ xem, KHÔNG áp dụng
      // cho phiên tạo ảnh (tránh gắn output của reviewer vào dự án của designer).
      this.activeProjectReviewOnly = !!opts.reviewOnly;
      try {
        const res = await fetch('/api/projects/' + id, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw apiError(await res.json().catch(() => ({})), 'Không tải được dự án.');
        const d = await res.json();
        this.activeProject = d;
        this.activeProjectGenerations = d.generations || [];
        // Mở dự án của mình = đang làm việc trên nó → tự ÁP DỤNG cho phiên tạo ảnh/video.
        if (!this.activeProjectReviewOnly) this.appliedProject = d;
        return d;
      } catch (e) { this.failToast(e, 'Lỗi tải dự án.'); return null; }
    },
    // ── Chia sẻ bộ sưu tập cho khách duyệt (Đợt 4) ────────────────────────────────────────
    // Card trong sidebar KHÔNG tự gọi API (bất biến: một đường dữ liệu đi qua store) — ba action dưới
    // đây là đường duy nhất tới /api/projects/{id}/share.
    async loadShareStatus(projectId) {
      try {
        const r = await fetch('/api/projects/' + projectId + '/share', { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return await r.json();
      } catch (e) {
        this.failToast(e, 'Không tải được trạng thái chia sẻ.', { prefix: 'Không tải được trạng thái chia sẻ' });
        return null;
      }
    },
    async createShare(projectId, days = 30) {
      try {
        const d = await this.api('/api/projects/' + projectId + '/share', { days: Number(days) || 30 });
        await navigator.clipboard?.writeText(d.url).then(() => this.toast('Đã tạo link chia sẻ và copy vào bộ nhớ tạm.')).catch(() => this.toast('Đã tạo link chia sẻ — bấm «Copy link» để lấy.'));
        return d;
      } catch (e) {
        this.failToast(e, 'Không tạo được link.', { prefix: 'Không tạo được link' });
        return null;
      }
    },
    async revokeShare(projectId, token) {
      try {
        await this.api('/api/projects/' + projectId + '/share/' + token, { _method: 'DELETE' });
        this.toast('Đã thu hồi link — khách mở lại sẽ không xem được nữa.');
        return true;
      } catch (e) {
        this.failToast(e, 'Không thu hồi được.', { prefix: 'Không thu hồi được' });
        return false;
      }
    },
    /**
     * [Đợt 2] Thống kê một bộ sưu tập: số ảnh xong/đang chạy/lỗi · credit đã dùng · hạn còn lại · phản hồi.
     * Nạp theo yêu cầu và nhớ theo id (bấm qua lại giữa các bộ không gọi lại liên tục).
     */
    async loadProjectStats(projectId, force = false) {
      if (!projectId) return null;
      if (!force && this.projectStats[projectId]) return this.projectStats[projectId];
      try {
        const r = await fetch('/api/projects/' + projectId + '/stats', { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        this.projectStats = { ...this.projectStats, [projectId]: d };
        return d;
      } catch (e) {
        console.error('loadProjectStats failed', e);
        return null;
      }
    },
    /**
     * [Đợt 2] ẢNH của một bộ sưu tập kèm trạng thái duyệt — nguồn cho khối "Duyệt mẫu theo lô".
     *
     * Dùng lại GET /api/projects/{id} (đã trả generations) thay vì thêm endpoint đọc mới: dữ liệu
     * vốn có, chỉ thiếu đường tới giao diện. Lọc bỏ ảnh chưa tạo xong vì chưa có gì để duyệt.
     */
    async loadProjectShots(projectId, force = false) {
      if (!projectId) return [];
      const cached = this.projectShots[projectId];
      if (!force && cached && cached.items) return cached.items;
      try {
        const r = await fetch('/api/projects/' + projectId, { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        const items = (d.project?.generations || d.generations || [])
          .filter((g) => g.status === 'completed')
          .map((g) => ({
            id: g.id,
            thumb: g.media_url,
            shot_state: g.shot_state || 'drafted',
            shot_label: g.shot_label || 'Bản nháp',
            prompt: (g.prompt || '').slice(0, 120),
            created_at: g.created_at,
          }));
        this.projectShots = { ...this.projectShots, [projectId]: { items, loadedAt: Date.now() } };
        return items;
      } catch (e) {
        console.error('loadProjectShots failed', e);
        this.toast('Không nạp được danh sách ảnh của bộ sưu tập.', 'error');
        return [];
      }
    },
    /**
     * [Đợt 2] Duyệt/loại NHIỀU ảnh trong một lượt — trả về { reviewed, failed, results }.
     *
     * Máy chủ là nơi quyết định (quyền · ảnh thuộc bộ · ảnh đã xong · whitelist trạng thái); ở đây chỉ
     * cập nhật lại trạng thái của những ảnh ĐÃ đổi để giao diện khỏi phải nạp lại cả danh sách.
     */
    async reviewShots(projectId, ids, state, note = '') {
      if (!projectId || !ids || !ids.length) return null;
      try {
        const d = await this.api('/api/projects/' + projectId + '/shots/review', { ids, state, note });
        const entry = this.projectShots[projectId];
        if (entry && Array.isArray(entry.items)) {
          const changed = {};
          (d.results || []).forEach((r) => { if (r.ok) changed[r.id] = r.shot_state; });
          entry.items = entry.items.map((it) => (
            changed[it.id]
              ? { ...it, shot_state: changed[it.id], shot_label: this.shotLabel(changed[it.id]) }
              : it
          ));
          this.projectShots = { ...this.projectShots, [projectId]: entry };
        }
        return d;
      } catch (e) {
        this.failToast(e, 'Không duyệt được.', { prefix: 'Không duyệt được' });
        return null;
      }
    },
    /** Nhãn tiếng Việt của trạng thái duyệt ảnh — KHỚP Generation::SHOT_LABELS ở máy chủ. */
    shotLabel(state) {
      const map = {
        idea: 'Ý tưởng', drafted: 'Bản nháp', selected: 'Đã chọn', fitted: 'Đã lên phom',
        campaign_ready: 'Chờ duyệt', approved: 'Đã duyệt', rejected: 'Đã loại',
      };
      return map[state] || 'Bản nháp';
    },
    // ── Mẫu việc theo ngành (Đợt 2) ───────────────────────────────────────────────────────
    async loadJobTemplates() {
      if (this.jobTemplatesLoaded) return this.jobTemplates;
      try {
        const r = await fetch('/api/job-templates', { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        this.jobTemplates = Array.isArray(d.templates) ? d.templates : [];
        this.jobTemplatesLoaded = true;
      } catch (e) { console.error('loadJobTemplates failed', e); }
      return this.jobTemplates;
    },
    /**
     * Áp một MẪU VIỆC: đặt tỉ lệ + độ phân giải, giữ bảng size/ghi chú cho gói xuất xưởng,
     * và trả về danh sách prompt để card đổ vào ô "Hàng loạt".
     */
    applyJobTemplate(tpl) {
      if (!tpl) return [];
      if (tpl.ratio) this.imageRatio = tpl.ratio;
      if (tpl.resolution) this.imageRes = tpl.resolution;
      this.pendingExport = tpl.export
        ? { from: tpl.title, sizes: tpl.export.sizes || '', note: tpl.export.note || '' }
        : null;
      const n = (tpl.prompts || []).length;
      this.toast('Đã áp mẫu «' + tpl.title + '»: ' + n + ' mục · tỉ lệ ' + tpl.ratio + ' · ' + tpl.resolution
        + (tpl.export ? ' · kèm bảng size cho xưởng' : ''));
      return tpl.prompts || [];
    },
    /** Yêu cầu StudioApp mở workspace Dự án/Bộ sưu tập (gọi từ card trong sidebar). */
    requestWorkspace() { this.workspaceOpenRequest = (this.workspaceOpenRequest || 0) + 1; },
    /**
     * [Trục 3 — 2026-09-20] Yêu cầu StudioApp CHUYỂN sang một nhóm công cụ (activity).
     *
     * Vì sao cần: nút "Biến thể"/"Sửa ảnh" nằm ở dock Outputs (component riêng), mà activity lại do
     * StudioApp quản lý — card render bằng <component:is> không nhận được event. Cùng cách đã dùng
     * cho requestWorkspace(): đếm yêu cầu, StudioApp theo dõi và đổi panel.
     */
    requestActivity(id) {
      if (!id) return;
      this.activityRequest = { id, n: ((this.activityRequest && this.activityRequest.n) || 0) + 1 };
    },
    /**
     * [Trục 1 — 2026-09-20] Đưa danh sách prompt của một MẪU VIỆC vào tab "Hàng loạt" của ConceptCard.
     *
     * Vì sao cần kênh riêng: màn hình canvas trống cho bấm mẫu việc, nhưng ô dán danh sách nằm trong
     * state CỤC BỘ của ConceptCard. Trước đây không có cách nào với tới nó ngoài việc tự tìm DOM —
     * cách đó vỡ ngay khi card đổi cấu trúc. Nay card tự đăng ký hàm nhận, ai cần thì gọi.
     */
    requestBatchPrompts(prompts, meta = {}) {
      const list = (prompts || []).map((p) => String(p).trim()).filter(Boolean);
      if (!list.length) return;
      this.batchFillRequest = { prompts: list, meta, n: ((this.batchFillRequest && this.batchFillRequest.n) || 0) + 1 };
      this.requestActivity('concept');
    },
    /**
     * Tính LẠI kế hoạch sản xuất theo đơn giá hiện tại. Tất định nên nhanh và không tốn lượt gọi AI.
     * Truyền đúng đầu vào của brief để cấu trúc danh mục / bảng size / dải giá luôn khớp màn hình.
     */
    async createProject(payload) {
      try {
        const d = await this.api('/api/projects/new', payload);
        this.projects.unshift(d);
        this.toast('Đã tạo dự án "' + d.name + '".');
        return d;
      } catch (e) { this.failToast(e, 'Lỗi tạo dự án.'); return null; }
    },
    async updateProject(id, payload) {
      try {
        const d = await this.api('/api/projects/' + id, { ...payload, _method: 'PUT' });
        const i = this.projects.findIndex(p => p.id === id);
        if (i >= 0) this.projects.splice(i, 1, d);
        if (this.activeProject && this.activeProject.id === id) this.activeProject = d;
        this.toast('Đã cập nhật dự án.');
        return d;
      } catch (e) { this.failToast(e, 'Lỗi cập nhật.'); return null; }
    },
    async deleteProject(id) {
      try {
        await this.api('/api/projects/' + id, { _method: 'DELETE' });
        this.projects = this.projects.filter(p => p.id !== id);
        if (this.activeProject && this.activeProject.id === id) { this.activeProject = null; this.activeProjectGenerations = []; this.activeProjectReviewOnly = false; }
        if (this.appliedProject && this.appliedProject.id === id) this.appliedProject = null;
        this.toast('Đã xóa dự án (output được giữ lại).');
        return true;
      } catch (e) { this.failToast(e, 'Lỗi xóa dự án.'); return false; }
    },
    async transitionProject(id, to, note = '') {
      try {
        const d = await this.api('/api/projects/' + id + '/transition', { to, note });
        const i = this.projects.findIndex(p => p.id === id);
        if (i >= 0) this.projects.splice(i, 1, d);
        if (this.activeProject && this.activeProject.id === id) this.activeProject = d;
        this.toast('Dự án → ' + (d.status_label || d.status) + '.');
        return d;
      } catch (e) { this.failToast(e, 'Không thể chuyển trạng thái.'); return null; }
    },
    // Gắn/gỡ 1 HOẶC NHIỀU generation khỏi dự án + ĐỒNG BỘ mọi state liên quan (library, outputs
    // Studio, workspace đang mở, bộ đếm) — một nơi duy nhất để UI gọi.
    async attachGenerationToProject(projectId, generationId, action = 'attach') {
      const ids = Array.isArray(generationId) ? generationId : [generationId];
      const single = ids.length === 1;
      try {
        const body = single ? { generation_id: ids[0], action } : { ids, action };
        const res = await this.api('/api/projects/' + projectId + '/generations', body);
        const changed = (res && res.changed) || ids.length;
        const failed = (res && res.failed) || 0;
        const syncOne = (gid, newPid) => {
          const proj = this.projects.find(p => Number(p.id) === Number(newPid))
            || (this.appliedProject && Number(this.appliedProject.id) === Number(newPid) ? this.appliedProject : null)
            || (this.activeProject && Number(this.activeProject.id) === Number(newPid) ? this.activeProject : null);
          const pname = proj ? proj.name : null;
          const sync = (g) => { if (g && Number(g.id) === Number(gid)) { g.project_id = newPid; g.project = pname; } };
          (this.libraryItems || []).forEach(sync);
          (this.generations || []).forEach(sync);
          if (this.viewer && Number(this.viewer.id) === Number(gid)) { this.viewer.project_id = newPid; this.viewer.project = pname; }
          if (this.activeProject) {
            const inDetail = this.activeProjectGenerations.some(g => Number(g.id) === Number(gid));
            if (inDetail && Number(this.activeProject.id) !== Number(newPid)) {
              this.activeProjectGenerations = this.activeProjectGenerations.filter(g => Number(g.id) !== Number(gid));
              this.activeProject.generations_count = Math.max(0, (this.activeProject.generations_count || 1) - 1);
              if (this.viewerList) this.viewerList = this.viewerList.filter(g => Number(g.id) !== Number(gid));
            }
          }
          const bump = (pid, d) => { const p = this.projects.find(x => Number(x.id) === Number(pid)); if (p) p.generations_count = Math.max(0, (p.generations_count || 0) + d); };
          if (action === 'detach') bump(projectId, -1); else bump(newPid, +1);
        };
        if (single) {
          const newPid = (res && res.generation) ? res.generation.project_id : (action === 'detach' ? null : projectId);
          syncOne(ids[0], newPid);
          this.toast(action === 'attach' ? 'Đã gắn ảnh vào bộ sưu tập.' : 'Đã gỡ ảnh khỏi bộ sưu tập.');
        } else {
          const results = Array.isArray(res.results) ? res.results : ids.map(id => ({ id, ok: true }));
          results.forEach(r => {
            const newPid = r.ok ? (action === 'attach' ? projectId : null) : null;
            syncOne(r.id, newPid);
          });
          const done = results.filter(r => r.ok).length;
          if (failed) {
            this.toast('Đã xử lý ' + done + '/' + ids.length + ' ảnh. ' + failed + ' ảnh không thể ' + (action === 'attach' ? 'gắn' : 'gỡ') + ' (không đủ quyền hoặc không thuộc bộ).', 'error');
          } else {
            this.toast('Đã ' + (action === 'attach' ? 'gắn' : 'gỡ') + ' ' + done + ' ảnh vào bộ sưu tập.');
          }
        }
        return { ok: true, changed, failed };
      } catch (e) { this.failToast(e, 'Lỗi gắn ảnh.'); return { ok: false, changed: 0, failed: ids.length }; }
    },
    // id dự án đang được ÁP DỤNG cho phiên tạo ảnh/video (Dự án hiện tại).
    // Tách khỏi activeProject: áp dụng tồn tại độc lập, không mất khi đóng workspace.
    appliedProjectId() {
      return this.appliedProject ? (this.appliedProject.id || null) : null;
    },
    // ÁP DỤNG 1 bộ sưu tập cụ thể cho phiên tạo ảnh/video — cơ chế tường minh,
    // dùng được từ workspace / header / thư viện mà không cần mở detail.
    applyProject(p) {
      if (!p || !p.id) return false;
      if (this.user && p.user_id && Number(p.user_id) !== Number(this.user.id)) {
        this.toast('Chỉ áp dụng được bộ sưu tập của chính bạn.', 'error');
        return false;
      }
      this.appliedProject = p;
      if (typeof localStorage !== 'undefined') {
        try { localStorage.setItem('studio_applied_project', JSON.stringify({ id: p.id, name: p.name })); } catch (e) {}
      }
      this.toast('Đã áp dụng bộ sưu tập "' + (p.name || ('#' + p.id)) + '" — ảnh/video tạo mới sẽ tự gắn vào.');
      return true;
    },
    // Ngắt "Bộ sưu tập hiện tại" — gỡ khỏi phiên tạo ảnh, quay về không áp dụng bộ nào.
    unapplyProject() {
      if (!this.appliedProject) return;
      this.appliedProject = null;
      this.outputFilterProject = false;
      if (typeof localStorage !== 'undefined') {
        try { localStorage.removeItem('studio_applied_project'); } catch (e) {}
      }
      this.toast('Đã ngắt bộ sưu tập hiện tại.');
    },
    // [P0.4] Khôi phục bộ sưu tập đang áp dụng từ localStorage sau khi tải lại trang (trước đây mất hết
    // khi F5 — người dùng mất hẳn ngữ cảnh "đang làm bộ nào").
    async restoreAppliedProject() {
      if (typeof localStorage === 'undefined') return;
      try {
        const raw = localStorage.getItem('studio_applied_project');
        if (!raw) return;
        const saved = JSON.parse(raw);
        if (!saved || !saved.id) return;
        if (!this.projects.length && !this.projectLoaded) await this.loadProjects();
        const p = this.projects.find(x => Number(x.id) === Number(saved.id));
        if (p) {
          this.appliedProject = p;
        } else {
          localStorage.removeItem('studio_applied_project');
        }
      } catch (e) { /* ignore */ }
    },
    // Đóng detail trong workspace — CHỈ thoát chế độ xem, GIỮ dự án đang áp dụng.
    clearActiveProject() {
      this.activeProject = null;
      this.activeProjectGenerations = [];
      this.activeProjectReviewOnly = false;
    },
    // Mở GalleryModal với ngữ cảnh danh sách rõ ràng (mặc định: thư viện / outputs).
    openViewer(g, list = null) {
      this.viewerList = list;
      this.viewer = g;
    },
    // Điều hướng chuẩn khi bấm "Chỉnh sửa" / "Tạo video" từ GalleryModal —
    // hoạt động ở MỌI nơi GalleryModal được mở (Studio 1 trang / Studio Library / …):
    //  - đang ở trang studio (có step 1/2/3): chọn ảnh + chuyển step ngay.
    //  - đang ở trang library (không có step): redirect về /studio?step=&id= để
    //    StudioApp (qua load()) khôi phục đúng bước + đúng ảnh đang chọn.
    goEditor(g, step) {
      if (!g) return;
      this.viewer = null;
      this.select(g);
      const onLibrary = window.location.pathname.includes('/library');
      if (onLibrary) {
        window.location.href = '/?step=' + step + '&id=' + g.id;
      } else {
        this.step = step;
      }
    },
    goEdit(g) { this.goEditor(g, 2); },
    goVideo(g) { this.goEditor(g, 3); },

    // ═══════════════════════════════════════════════════════════════════
    // PHIẾU KỸ THUẬT (tech pack) — Việc #3, 2026-09-26
    //
    // Vì sao nằm cùng miền "bộ sưu tập": phiếu là THUỘC TÍNH CỦA MỘT BỘ (cùng một bộ thì cùng một vải,
    // cùng một bảng thông số), không phải tài sản dùng chung như preset. Xoá bộ là mất phiếu theo (cascade).
    // ═══════════════════════════════════════════════════════════════════
    /**
     * Nạp phiếu kỹ thuật của MỘT bộ sưu tập. Nhớ theo id để mở lại không phải gọi mạng lần nữa
     * (trừ khi force = true, hoặc phiếu đang mở là của bộ KHÁC).
     */
    async loadTechPack(projectId, force = false) {
      const id = Number(projectId) || 0;
      if (!id) return null;
      if (!force && this.techPack && this.techPackProjectId === id) return this.techPack;

      this.techPackLoading = true;
      this.techPackError = '';
      try {
        const res = await fetch('/api/projects/' + id + '/tech-pack', { headers: { Accept: 'application/json' } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, 'Không tải được phiếu kỹ thuật.');
        this.techPack = data;
        this.techPackProjectId = id;
        this.techPackDraft = JSON.parse(JSON.stringify(data.tech_pack || null));
        return data;
      } catch (e) {
        this.techPackError = userFacingError(e, 'Không tải được phiếu kỹ thuật.');
        return null;
      } finally {
        this.techPackLoading = false;
      }
    },
    /** Lưu phiếu của bộ đang mở. Không có bộ nào đang mở ⇒ không làm gì (và nói ra). */
    async saveTechPack() {
      const id = this.techPackProjectId;
      if (!id || !this.techPackDraft) {
        this.techPackError = 'Chưa chọn bộ sưu tập nào để lưu phiếu.';
        return false;
      }

      this.techPackSaving = true;
      this.techPackError = '';
      try {
        const res = await fetch('/api/projects/' + id + '/tech-pack', {
          method: 'PUT',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(this.techPackDraft),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, 'Không lưu được phiếu kỹ thuật.');
        this.techPack = data;
        this.techPackDraft = JSON.parse(JSON.stringify(data.tech_pack || null));
        const c = data.completeness || {};
        this.toast(
          c.ready
            ? 'Đã lưu phiếu kỹ thuật — gói xuất cho xưởng sẽ dùng thông số này.'
            : 'Đã lưu phiếu kỹ thuật. Còn thiếu: ' + ((c.missing || []).join(' · ') || '—'),
          c.ready ? 'success' : 'info',
        );
        return true;
      } catch (e) {
        this.techPackError = userFacingError(e, 'Không lưu được phiếu kỹ thuật.');
        this.toast(this.techPackError, 'error');
        return false;
      } finally {
        this.techPackSaving = false;
      }
    },
    /** Xoá phiếu (về "chưa lập") — KHÔNG đụng ảnh, bộ sưu tập hay dữ liệu khác. */
    async resetTechPack() {
      const id = this.techPackProjectId;
      if (!id) return false;

      this.techPackSaving = true;
      this.techPackError = '';
      try {
        const res = await fetch('/api/projects/' + id + '/tech-pack', {
          method: 'DELETE',
          headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, 'Không xoá được phiếu kỹ thuật.');
        this.techPack = data;
        this.techPackDraft = JSON.parse(JSON.stringify(data.tech_pack || null));
        this.toast('Đã xoá phiếu kỹ thuật của bộ sưu tập.', 'info');
        return true;
      } catch (e) {
        this.techPackError = userFacingError(e, 'Không xoá được phiếu kỹ thuật.');
        return false;
      } finally {
        this.techPackSaving = false;
      }
    },
    /** Bỏ thay đổi chưa lưu: quay về đúng bản đang có trên máy chủ. */
    discardTechPackDraft() {
      this.techPackDraft = JSON.parse(JSON.stringify(this.techPack?.tech_pack || null));
    },
    /** Có thay đổi chưa lưu không? (một nguồn cho cả trạng thái nút Lưu lẫn nhãn "Chưa lưu"). */
    techPackDirty() {
      if (!this.techPackDraft) return false;
      return JSON.stringify(this.techPackDraft) !== JSON.stringify(this.techPack?.tech_pack || null);
    },

    // ═══════════════════════════════════════════════════════════════════
    // MẪU VẬT LÝ (fit · PP · TOP) — Việc #4, 2026-09-26
    //
    // Vì sao nằm cùng miền bộ sưu tập: mẫu là con của MỘT bộ (cùng bộ thì cùng xưởng, cùng mã hàng),
    // và vòng đời của nó NỐI TIẾP vòng đời ảnh — duyệt ảnh xong mới đặt xưởng làm mẫu thật.
    // ═══════════════════════════════════════════════════════════════════
    /** Nạp bảng theo dõi của MỘT bộ. Nhớ theo id để mở lại không phải gọi mạng (trừ khi force). */
    async loadSamples(projectId, force = false) {
      const id = Number(projectId) || 0;
      if (!id) return null;
      if (!force && this.samples && this.samplesProjectId === id) return this.samples;

      this.samplesLoading = true;
      this.samplesError = '';
      try {
        const res = await fetch('/api/projects/' + id + '/samples', { headers: { Accept: 'application/json' } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, 'Không tải được bảng theo dõi mẫu.');
        this.samples = data;
        this.samplesProjectId = id;
        return data;
      } catch (e) {
        this.samplesError = userFacingError(e, 'Không tải được bảng theo dõi mẫu.');
        return null;
      } finally {
        this.samplesLoading = false;
      }
    },
    /**
     * Gọi một đường GHI rồi cất bảng mới vào store — bốn thao tác dùng CHUNG một đường.
     *
     * Vì sao không chép bốn lần: mỗi lần chép là một chỗ để quên cập nhật cờ đang-lưu hoặc quên đọc
     * lỗi từ máy chủ — và bảng theo dõi sẽ hiện số cũ trong khi máy chủ đã đổi.
     */
    async writeSample(projectId, path, options, fallbackMessage) {
      this.samplesSaving = true;
      this.samplesError = '';
      try {
        const res = await fetch('/api/projects/' + projectId + path, {
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          ...options,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, fallbackMessage);
        this.samples = data;
        this.samplesProjectId = Number(projectId) || null;
        return data;
      } catch (e) {
        this.samplesError = userFacingError(e, fallbackMessage);
        this.toast(this.samplesError, 'error');
        return null;
      } finally {
        this.samplesSaving = false;
      }
    },
    async createSample(projectId, payload) {
      const data = await this.writeSample(projectId, '/samples', { method: 'POST', body: JSON.stringify(payload) }, 'Không thêm được mẫu.');
      if (data) this.toast('Đã thêm mẫu vào bảng theo dõi.', 'success');
      return data;
    },
    async updateSample(projectId, sampleId, payload) {
      return this.writeSample(projectId, '/samples/' + sampleId, { method: 'PATCH', body: JSON.stringify(payload) }, 'Không lưu được thông tin mẫu.');
    },
    /** Chuyển giai đoạn — máy chủ TỪ CHỐI bước nhảy cóc và trả 422 kèm các bước được phép. */
    async transitionSample(projectId, sampleId, stage, note = '') {
      return this.writeSample(
        projectId,
        '/samples/' + sampleId + '/stage',
        { method: 'POST', body: JSON.stringify(note ? { stage, note } : { stage }) },
        'Không chuyển được giai đoạn của mẫu.',
      );
    },
    async deleteSample(projectId, sampleId) {
      const data = await this.writeSample(projectId, '/samples/' + sampleId, { method: 'DELETE' }, 'Không xoá được mẫu.');
      if (data) this.toast('Đã xoá mẫu khỏi bảng theo dõi.', 'info');
      return data;
    },

    // ═══════════════════════════════════════════════════════════════════
    // KIỂM TRA CHẤT LƯỢNG (QC) — Việc #6, 2026-09-26
    //
    // Vì sao nằm cùng miền bộ sưu tập: biên bản QC gắn với MỘT lô của MỘT bộ (cùng mã hàng, cùng
    // phiếu kỹ thuật), và nó là mắt cuối của chuỗi: thiết kế → mẫu → sản xuất → KIỂM → giao.
    // ═══════════════════════════════════════════════════════════════════
    /** Nạp bảng biên bản của MỘT bộ. Nhớ theo id để mở lại không phải gọi mạng (trừ khi force). */
    async loadQc(projectId, force = false) {
      const id = Number(projectId) || 0;
      if (!id) return null;
      if (!force && this.qc && this.qcProjectId === id) return this.qc;

      this.qcLoading = true;
      this.qcError = '';
      try {
        const res = await fetch('/api/projects/' + id + '/qc-inspections', { headers: { Accept: 'application/json' } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, 'Không tải được biên bản kiểm tra chất lượng.');
        this.qc = data;
        this.qcProjectId = id;
        return data;
      } catch (e) {
        this.qcError = userFacingError(e, 'Không tải được biên bản kiểm tra chất lượng.');
        return null;
      } finally {
        this.qcLoading = false;
      }
    },
    /**
     * Một đường GHI dùng chung cho bốn thao tác — máy chủ trả về CẢ bảng mới sau mỗi lần ghi, nên
     * giao diện không phải tự đoán lại số tổng (đoán là chỗ để số liệu lệch khỏi máy chủ).
     */
    async writeQc(projectId, path, options, fallbackMessage) {
      this.qcSaving = true;
      this.qcError = '';
      try {
        const res = await fetch('/api/projects/' + projectId + path, {
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          ...options,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, fallbackMessage);
        this.qc = data;
        this.qcProjectId = Number(projectId) || null;
        return data;
      } catch (e) {
        this.qcError = userFacingError(e, fallbackMessage);
        this.toast(this.qcError, 'error');
        return null;
      } finally {
        this.qcSaving = false;
      }
    },
    async createQcInspection(projectId, payload) {
      const data = await this.writeQc(projectId, '/qc-inspections', { method: 'POST', body: JSON.stringify(payload) }, 'Không mở được biên bản kiểm tra.');
      if (data) this.toast('Đã mở biên bản và chốt kế hoạch lấy mẫu.', 'success');
      return data;
    },
    /** Ghi kết quả kiểm. Máy chủ TÍNH LẠI kết luận (đạt/không đạt) — giao diện không gửi kết luận lên. */
    async updateQcInspection(projectId, inspectionId, payload) {
      return this.writeQc(projectId, '/qc-inspections/' + inspectionId, { method: 'PATCH', body: JSON.stringify(payload) }, 'Không lưu được kết quả kiểm.');
    },
    async deleteQcInspection(projectId, inspectionId) {
      const data = await this.writeQc(projectId, '/qc-inspections/' + inspectionId, { method: 'DELETE' }, 'Không xoá được biên bản.');
      if (data) this.toast('Đã xoá biên bản kiểm tra.', 'info');
      return data;
    },
    // ═══════════════════════════════════════════════════════════════════
    // BA CỔNG DUYỆT — Việc #7, 2026-09-26
    //
    // Vì sao nằm cùng miền bộ sưu tập: cổng duyệt là quyết định TRÊN một bộ sưu tập (thông số đi ra
    // xưởng · tiền · chất lượng), có vết ai-khi-nao, và cả ba cổng đều nằm trong hồ sơ của bộ đó.
    // ═══════════════════════════════════════════════════════════════════
    /** Nạp ba cổng của MỘT bộ. Nhớ theo id để mở lại không phải gọi mạng (trừ khi force). */
    async loadGates(projectId, force = false) {
      const id = Number(projectId) || 0;
      if (!id) return null;
      if (!force && this.gates && this.gatesProjectId === id) return this.gates;

      this.gatesLoading = true;
      this.gatesError = '';
      try {
        const res = await fetch('/api/projects/' + id + '/gates', { headers: { Accept: 'application/json' } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, 'Không tải được ba cổng duyệt.');
        this.gates = data;
        this.gatesProjectId = id;
        return data;
      } catch (e) {
        this.gatesError = userFacingError(e, 'Không tải được ba cổng duyệt.');
        return null;
      } finally {
        this.gatesLoading = false;
      }
    },
    /**
     * Ghi MỘT quyết định duyệt. Máy chủ trả về CẢ bảng mới (đã tính lại hiệu lực + cascade) nên giao
     * diện không phải tự suy ra cổng nào vừa mất hiệu lực — đó là chỗ dễ sai nhất của luật cascade.
     */
    async writeGate(projectId, gate, payload, fallbackMessage) {
      this.gatesSaving = true;
      this.gatesError = '';
      try {
        const res = await fetch('/api/projects/' + projectId + '/gates/' + gate, {
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          method: 'POST',
          body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, fallbackMessage);
        this.gates = data;
        this.gatesProjectId = Number(projectId) || null;
        return data;
      } catch (e) {
        this.gatesError = userFacingError(e, fallbackMessage);
        this.toast(this.gatesError, 'error');
        return null;
      } finally {
        this.gatesSaving = false;
      }
    },
    async decideGate(projectId, gate, decision, note = '') {
      const labels = { approved: 'duyệt', rejected: 'không duyệt', pending: 'rút lại' };
      const message = 'Không ghi được quyết định ' + (labels[decision] || decision) + '.';
      const data = await this.writeGate(projectId, gate, { decision, note }, message);
      if (data) {
        this.toast(
          decision === 'approved' ? 'Đã duyệt cổng này.'
            : (decision === 'rejected' ? 'Đã ghi không duyệt — kèm lý do.' : 'Đã rút lại quyết định.'),
          decision === 'rejected' ? 'info' : 'success',
        );
      }
      return data;
    },
    // ═══════════════════════════════════════════════════════════════════
    // TIẾN ĐỘ SẢN XUẤT — Việc #8, 2026-09-26
    //
    // Vì sao nằm cùng miền bộ sưu tập: sản lượng gắn với MỘT lô của MỘT bộ (cùng mã hàng, cùng kế
    // hoạch, cùng hạn), và nó là thứ duy nhất đối chiếu được KẾ HOẠCH với THỰC TẾ.
    // ═══════════════════════════════════════════════════════════════════
    /** Nạp tiến độ của MỘT bộ. Nhớ theo id để mở lại không phải gọi mạng (trừ khi force). */
    async loadProduction(projectId, force = false) {
      const id = Number(projectId) || 0;
      if (!id) return null;
      if (!force && this.production && this.productionProjectId === id) return this.production;

      this.productionLoading = true;
      this.productionError = '';
      try {
        const res = await fetch('/api/projects/' + id + '/production', { headers: { Accept: 'application/json' } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, 'Không tải được tiến độ sản xuất.');
        this.production = data;
        this.productionProjectId = id;
        return data;
      } catch (e) {
        this.productionError = userFacingError(e, 'Không tải được tiến độ sản xuất.');
        return null;
      } finally {
        this.productionLoading = false;
      }
    },
    /** Một đường GHI dùng chung — máy chủ trả về CẢ bảng mới nên số tổng luôn khớp máy chủ. */
    async writeProduction(projectId, path, options, fallbackMessage) {
      this.productionSaving = true;
      this.productionError = '';
      try {
        const res = await fetch('/api/projects/' + projectId + '/production' + path, {
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          ...options,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, fallbackMessage);
        this.production = data;
        this.productionProjectId = Number(projectId) || null;
        return data;
      } catch (e) {
        this.productionError = userFacingError(e, fallbackMessage);
        this.toast(this.productionError, 'error');
        return null;
      } finally {
        this.productionSaving = false;
      }
    },
    /** Ghi (hoặc sửa) sản lượng MỘT ngày — ghi lại cùng ngày là cập nhật, không cộng thêm. */
    async logProduction(projectId, payload) {
      const data = await this.writeProduction(projectId, '', { method: 'POST', body: JSON.stringify(payload) }, 'Không ghi được sản lượng.');
      if (data) this.toast('Đã ghi sản lượng — tiến độ tính lại từ số vừa nhập.', 'success');
      return data;
    },
    async deleteProduction(projectId, logId) {
      const data = await this.writeProduction(projectId, '/' + logId, { method: 'DELETE' }, 'Không xoá được ngày sản lượng.');
      if (data) this.toast('Đã xoá ngày sản lượng — mọi con số tính lại.', 'info');
      return data;
    },
    // ═══════════════════════════════════════════════════════════════════
    // TÌM THIẾT KẾ CŨ — FileSearch (Việc #9, 2026-09-26)
    //
    // Vì sao nằm ở tài khoản chứ không ở bộ sưu tập: câu hỏi hay gặp là "mùa trước mình làm gì rồi" —
    // nó KHÔNG thuộc về một bộ nào, và kho tài liệu gồm cả ảnh · brief · bài học · phiếu kỹ thuật.
    // ═══════════════════════════════════════════════════════════════════
    /** Tìm trong kho của chính mình. Không cache kết quả: mỗi câu hỏi là một câu hỏi khác. */
    async runDesignSearch(query) {
      const q = String(query || '').trim();
      if (!q) {
        this.searchError = 'Nhập nội dung cần tìm — VD: áo khoác màu be mùa trước.';
        return null;
      }

      this.searchLoading = true;
      this.searchError = '';
      try {
        const res = await fetch('/api/design-search?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, 'Không tìm được trong kho thiết kế cũ.');
        this.search = data;
        return data;
      } catch (e) {
        this.searchError = userFacingError(e, 'Không tìm được trong kho thiết kế cũ.');
        return null;
      } finally {
        this.searchLoading = false;
      }
    },
    /** Tình trạng chỉ mục: bao nhiêu tài liệu đã nhúng / tổng, và CÓ nhúng được hay không. */
    async loadDesignSearchStatus() {
      try {
        const res = await fetch('/api/design-search/status', { headers: { Accept: 'application/json' } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, 'Không đọc được tình trạng chỉ mục.');
        this.searchStatus = data;
        return data;
      } catch (e) {
        this.searchError = userFacingError(e, 'Không đọc được tình trạng chỉ mục.');
        return null;
      }
    },
    /**
     * LẬP CHỈ MỤC NGAY (có trần): mỗi tài liệu là một phần của một lời gọi ra NGOÀI nên không được chạy
     * không giới hạn sau một cú bấm. Trả về số đã nhúng để giao diện nói thật là đã làm được gì.
     */
    async indexDesignSearch(limit = 50) {
      this.searchBusy = true;
      this.searchError = '';
      try {
        const res = await fetch('/api/design-search/index', {
          method: 'POST',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ limit }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(data, 'Không lập được chỉ mục tìm kiếm.');
        this.searchStatus = { stats: data.stats, shape: data.shape };
        const indexed = Number(data.result?.indexed || 0);
        if (data.result?.unavailable) {
          this.toast('Chưa có nhà cung cấp nhúng được văn bản — tìm kiếm vẫn chạy ở chế độ từ khoá.', 'warn');
        } else if (indexed === 0) {
          this.toast('Chỉ mục đã đầy đủ — không có tài liệu nào cần nhúng thêm.', 'info');
        } else {
          this.toast('Đã nhúng ' + indexed + ' tài liệu' + (data.result?.pending ? ' · còn ' + data.result.pending + ' tài liệu chờ lượt sau.' : '.'), 'success');
        }
        return data;
      } catch (e) {
        this.searchError = userFacingError(e, 'Không lập được chỉ mục tìm kiếm.');
        this.toast(this.searchError, 'error');
        return null;
      } finally {
        this.searchBusy = false;
      }
    },
  };
