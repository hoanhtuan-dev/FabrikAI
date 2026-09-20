// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — miền: tài khoản · gói & credit · nhóm ghế · quyền module · toast/thông báo · giá trị mặc định.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt file gốc.
import { apiError, safeMessage, userFacingError, CSRF } from '../helpers.js';
export const accountActions = {
    setAuthStatus(status) {
      if (status === 403) {
        // Đã đăng nhập nhưng không đủ quyền — KHÁC hẳn "chưa đăng nhập".
        this.authState = 'unauthorized';
      } else if (status === 401) {
        // Có user trong boot nhưng bị 401 ⇒ phiên đã hết. Không có user ⇒ khách.
        this.authState = this.user ? 'expired' : 'guest';
      } else if (status === 200) {
        this.authState = 'ok';
      }
    },
    async api(url, body = {}, signal = null) {
      const res = await fetch(url, { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body), signal });
      const ct = res.headers.get('content-type') || '';
      const data = await res.json().catch(() => ({}));
      // M04: hết phiên -> Laravel redirect về /dang-nhap và trả HTML 200. Nếu chỉ check res.ok thì
      // HTML đó parse thành {} và MỌI caller mutation tưởng là thành công. Guard y như _libraryFetch
      // và deleteGen.
      if (!res.ok || res.redirected || !ct.includes('application/json')) {
        // redirected ⇒ Laravel đá về /dang-nhap và trả HTML ⇒ coi như HẾT PHIÊN, không phải 403.
        if (expired) this.setAuthStatus(401);
        else this.setAuthStatus(res.status);
        // [Q1 — 2026-09-19] Hết credit (402 code=out_of_credits) ⇒ MỞ THẲNG bảng nâng cấp kèm danh mục
        // gói, thay vì để khách đọc một câu lỗi rồi không biết bấm vào đâu. Xử lý ở MỘT chỗ này nên
        // mọi đường tạo ảnh/video (8 endpoint) đều có cùng trải nghiệm.
        // [Modules] 403 module_locked ⇒ mở bảng nâng cấp (giống hết cách xử lý hết credit).
        if (res.status === 403 && data && data.code === 'module_locked') {
          this.planOpen = true;
          this.planCatalogOpen = true;
          this.upgradeOpen = false;
          this.loadPlanStatus(true);
        }
        if (res.status === 402 && data && data.code === 'out_of_credits') {
          this.planOpen = true;
          this.planCatalogOpen = true;
          this.upgradeOpen = false;
          this.loadPlanStatus(true);
        }
        // PHÂN BIỆT hai tình huống rất khác nhau — trước đây gộp làm một câu nên khách đọc xong vẫn không
        // biết phải làm gì, còn hỗ trợ thì không biết lỗi ở đâu:
        //   · phiên đăng nhập đã hết (máy chủ đá về trang đăng nhập) ⇒ chỉ cần tải lại trang;
        //   · máy chủ trả về thứ không dùng được (lỗi/không phải JSON) ⇒ tải lại, nếu vẫn lỗi thì đọc mã tra cứu.
        // 419 = Laravel từ chối vì token phiên/CSRF đã cũ (tab mở lâu, hoặc máy ngủ rồi thức).
        // Đây CHÍNH LÀ "hết phiên" chứ không phải lỗi dữ liệu — trước đây rơi vào nhánh chung nên khách
        // nhận một câu mơ hồ (đúng ca khách báo mã L-P7CR).
        const expired = res.redirected || res.status === 419 || (res.url && res.url.includes('/dang-nhap'));
        const err = new Error(data.message || (expired
          ? 'Phiên làm việc đã hết. Hãy tải lại trang để đăng nhập lại.'
          : 'Không tải được dữ liệu. Hãy tải lại trang và thử lại.'));
        err.status = res.status;
        err.code = data && data.code;
        err.data = data;
        // Ngữ cảnh cho MÃ TRA CỨU: nhờ nó mà log nói được ĐÚNG endpoint nào hỏng, thay vì chỉ có câu lỗi
        // (lần khách báo mã L-P7CR, log không có đường dẫn nên phải đoán mò).
        err.api_context = 'api ' + url + ' → ' + (expired ? 'hết phiên' : 'HTTP ' + res.status);
        throw err;
      }
      return data;
    },
    /**
     * Dự án đang áp dụng — gửi kèm MỌI thao tác SINH ẢNH để kết quả nằm đúng bộ sưu tập.
     *
     * Vì sao: trước đây chỉ tạo ảnh mới và video gửi `project_id`; sửa ảnh · biến thể · xoá nền ·
     * ghép · nâng cấp · look · sửa vùng · cắt đều KHÔNG gửi, nên kết quả rơi ra ngoài bộ sưu tập
     * dù người dùng đang áp dụng một dự án.
     *
     * Gửi null khi không áp dụng dự án: server hiểu là "không chỉ định" và sẽ THỪA HƯỞNG dự án của
     * ảnh nguồn — sửa một ảnh thuộc bộ sưu tập nào thì kết quả ở lại bộ sưu tập đó.
     */
    projectField() { return { project_id: this.appliedProjectId() }; },
    addGen(g) {
      // Ảnh vừa tạo phải mang theo dự án NGAY, không chờ lần nạp lại kế tiếp: thiếu trường này thì
      // nó vô hình với bộ lọc "chỉ outputs của dự án đang áp dụng" cho tới khi /api/latest về.
      if (g.project_id === undefined) {
        const pid = this.appliedProjectId();
        if (pid) { g.project_id = pid; g.project = this.appliedProject ? this.appliedProject.name : null; }
      }
      const existing = this.generations.find(x => x.id === g.id);
      if (existing) Object.assign(existing, g);
      else this.generations.unshift(g);
      this.previewId = g.id;
      if (g.status === 'completed') { this.preview = { id: g.id, media_url: g.media_url, type: 'image', status: 'completed' }; }
      if (g.media_url) { this.pushCanvasLayer(String(g.id), 'gen', 'Ảnh #' + g.id, g.media_url, g.id); this.setActiveLayer(String(g.id)); }
    },
    setPreview(g) { if (g) { this.previewId = g.id; this.preview = { id: g.id, media_url: g.media_url, type: g.type || 'image', status: g.status || 'completed' }; } },
    /**
     * Nạp trạng thái GÓI của chính người dùng: gói · hạn mức · chi phí · danh mục gói.
     * Server cũng cấp credit theo chu kỳ ở đây (idempotent) nên số dư trả về luôn là số thật.
     */
    async loadPlanStatus(force = false) {
      // force=true: gọi lại sau khi gửi yêu cầu nâng cấp (yêu cầu đang mở phải hiện ngay).
      if (!this.user || (this._planLoading && !force)) return;
      this._planLoading = true;
      try {
        const res = await fetch('/api/plan/status', { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const d = await res.json();
        this.planStatus = d;
        if (d.credits && d.credits.balance != null) this.creditsLeft = Number(d.credits.balance);
        if (d.costs && d.costs.image) this.imageCreditCost = Number(d.costs.image);
      } catch (e) { console.error('loadPlanStatus failed', e); }
      finally { this._planLoading = false; }
    },
    togglePlanPopover() {
      this.planOpen = !this.planOpen;
      if (this.planOpen) { this.loadPlanStatus(); this.loadTeam(); }
      else { this.planCatalogOpen = false; }
    },
    /**
     * [Q2] Mở form YÊU CẦU NÂNG CẤP cho một gói (gói trả phí không tự kích hoạt được nữa).
     * Điền sẵn tên/SĐT nếu đã biết để khách không phải gõ lại.
     */
    openUpgrade(plan) {
      if (!plan) return;
      this.upgradePlanId = plan.id;
      this.upgradeResult = null;
      this.upgradeOpen = true;
      if (!this.upgradeForm.name) this.upgradeForm.name = this.user?.name || '';
      // Mốc mua đầu tiên của CHÍNH GÓI đó (gói tháng ⇒ 1 tháng, gói xưởng ⇒ 1 vụ).
      this.upgradeForm.units = (plan.units && plan.units.length) ? Number(plan.units[0]) : 1;
      this.upgradeForm.method = 'bank_transfer';
    },
    closeUpgrade() { this.upgradeOpen = false; this.upgradePlanId = null; },
    // ── [Q4] Nhóm làm việc theo số ghế ───────────────────────────────────────────────────
    /** Nạp tình trạng nhóm: tổng ghế · đã dùng · danh sách thành viên (một lời gọi duy nhất). */
    async loadTeam(force = false) {
      if (!this.user) return null;
      if (!force && this.team) return this.team;
      try {
        const r = await fetch('/api/team', { headers: { Accept: 'application/json' } });
        if (!r.ok) return null;
        const d = await r.json();
        this.team = d;
        return d;
      } catch (e) { console.error('loadTeam failed', e); return null; }
    },
    toggleTeam() {
      this.teamOpen = !this.teamOpen;
      if (this.teamOpen) { this.teamResult = null; this.loadTeam(true); }
    },
    /**
     * Mời một thành viên vào nhóm. Mật khẩu tạm do MÁY CHỦ sinh và chỉ trả về đúng lần này — giao diện
     * hiển thị để chủ nhóm gửi cho nhân viên, không lưu lại ở đâu khác.
     */
    async inviteMember() {
      if (this.teamBusy) return null;
      const email = String(this.teamForm.email || '').trim();
      if (!email) { this.toast('Nhập email của thành viên.', 'error'); return null; }
      this.teamBusy = true;
      try {
        const d = await this.api('/api/team/members', {
          email,
          name: this.teamForm.name || null,
          phone: this.teamForm.phone || null,
        });
        this.teamResult = { member: d.member, temp_password: d.temp_password };
        this.teamForm = { name: '', email: '', phone: '' };
        await this.loadTeam(true);
        if (d.seats) this.team = { ...(this.team || {}), seats: d.seats };
        this.toast(d.message || ('Đã thêm ' + (d.member?.name || '') + ' vào nhóm.'), 'success');
        return d;
      } catch (e) {
        this.failToast(e, 'Không thêm được thành viên.');
        return null;
      } finally { this.teamBusy = false; }
    },
    /** Bỏ một thành viên khỏi nhóm (giải phóng ghế). Tài khoản + ảnh đã tạo vẫn giữ nguyên. */
    async removeMember(id) {
      if (this.teamBusy || !id) return null;
      this.teamBusy = true;
      try {
        const d = await this.api('/api/team/members/' + id, { _method: 'DELETE' });
        await this.loadTeam(true);
        if (d.seats) this.team = { ...(this.team || {}), seats: d.seats };
        this.toast(d.message || 'Đã bỏ thành viên khỏi nhóm.', 'info');
        return d;
      } catch (e) {
        this.failToast(e, 'Không bỏ được thành viên.');
        return null;
      } finally { this.teamBusy = false; }
    },
    /**
     * Gửi yêu cầu nâng cấp. Máy chủ kiểm gói/số tháng/phương thức/SĐT và trả MÃ THEO DÕI; ở đây chỉ
     * hiển thị đúng những gì máy chủ trả về (không tự bịa mã, không tự bịa số tiền).
     */
    async submitUpgrade() {
      if (this.upgradeBusy || !this.upgradePlanId) return null;
      this.upgradeBusy = true;
      try {
        const d = await this.api('/api/billing/upgrade-request', {
          plan_id: this.upgradePlanId,
          units: Number(this.upgradeForm.units) || 1,
          method: this.upgradeForm.method,
          contact_name: this.upgradeForm.name || null,
          contact_phone: this.upgradeForm.phone,
          note: this.upgradeForm.note || null,
        });
        this.upgradeResult = d.request || null;
        if (d.payment) this.planStatus = { ...(this.planStatus || {}), payment: d.payment };
        await this.loadPlanStatus(true);
        this.toast(d.message || ('Đã gửi yêu cầu ' + (d.request?.code || '')), d.reused ? 'info' : 'success');
        return d;
      } catch (e) {
        this.failToast(e, 'Không gửi được yêu cầu nâng cấp.');
        return null;
      } finally {
        this.upgradeBusy = false;
      }
    },
    async subscribePlan(planId) {
      if (this.planBusy) return;
      this.planBusy = true;
      try {
        const res = await fetch('/api/billing/subscribe', {
          method: 'POST',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ plan_id: planId }),
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) {
          // [Q2] Gói trả phí không tự kích hoạt được nữa: máy chủ trả 402 payment_required ⇒ mở ngay
          // form YÊU CẦU NÂNG CẤP cho đúng gói khách vừa chọn (không bắt họ đi tìm lại).
          if (res.status === 402 && d.code === 'payment_required') {
            const plan = (this.planStatus?.catalog || []).find((x) => Number(x.id) === Number(planId));
            this.toast('Gói trả phí cần xác nhận thanh toán — điền thông tin để FabrikAI liên hệ.', 'info');
            this.openUpgrade(plan || { id: planId });
            return;
          }
          throw apiError(d, null, res);
        }
        await this.loadPlanStatus(true);
        this.toast('Đã chuyển sang gói ' + ((d.plan && d.plan.name) || ''), 'info');
      } catch (e) {
        this.failToast(e, 'Không đổi được gói.', { prefix: 'Không đổi được gói' });
      }
      this.planBusy = false;
    },
    // ── [Modules] Quyền theo gói ─────────────────────────────────────────────────────────
    /** Nhận danh sách quyền module từ máy chủ (/api/gui hoặc /api/boot). */
    setModuleAccess(status, allowedIds, catalog) {
      if (Array.isArray(status) && status.length) this.modulesStatus = status;
      else if (Array.isArray(allowedIds)) {
        // /api/boot chỉ trả id được phép ⇒ đánh dấu phần còn lại là bị khoá (chi tiết lấy sau từ /api/gui).
        this.modulesStatus = this.modulesStatus.length
          ? this.modulesStatus
          : allowedIds.map((id) => ({ id, name: id, allowed: true, reason: null, depends_on: [] }));
      }
      if (Array.isArray(catalog) && catalog.length) this.modulesCatalog = catalog;
    },
    /** Module có bị KHOÁ theo gói không? (chưa biết ⇒ coi như mở, để không chặn nhầm người dùng). */
    moduleLocked(id) {
      const row = this.modulesStatus.find((m) => m.id === id);
      return !!row && row.allowed === false;
    },
    moduleInfo(id) {
      return this.modulesStatus.find((m) => m.id === id) || this.modulesCatalog.find((m) => m.id === id) || null;
    },
    /** Gói nào đang cấp module này — lấy từ /api/plan/status (danh mục gói) để mời nâng cấp cho đúng. */
    plansWithModule(id) {
      const plans = (this.planStatus && this.planStatus.catalog) || [];
      return plans.filter((p) => Array.isArray(p.modules) && p.modules.includes(id));
    },
    /** Số module được cấp / tổng — hiển thị trong popup gói. */
    moduleCounts() {
      const total = this.modulesStatus.length || this.modulesCatalog.length || 0;
      const allowed = this.modulesStatus.filter((m) => m.allowed).length;
      return { allowed, total };
    },
    /**
     * Hiển thị lỗi BẮT ĐƯỢC từ một exception: câu người dùng hiểu + MÃ TRA CỨU.
     *
     * Vì sao cần: trước đây các khối catch gọi thẳng `this.toast(e.message, 'error')` nên câu hiện ra
     * KHÔNG có mã tra cứu — khách đọc cho tổng đài thì hỗ trợ không tra được gì. Mọi chỗ hiển thị lỗi từ
     * exception phải đi qua đây (test ClientErrorReportTest khoá bất biến này).
     */
    failToast(e, fallback, opts = {}) {
      this.toast(userFacingError(e, fallback, opts), 'error', opts);
    },

    toast(msg, type = 'info', opts = {}) {
      // ── CỬA CHẶN CUỐI CÙNG (docs/DESIGN_SYSTEM.md §6) ────────────────────────────────────
      // Mọi thông báo đều đi qua đây, nên đây là chỗ DUY NHẤT bảo đảm không có câu nào lọt ra
      // giao diện kèm chi tiết kỹ thuật — kể cả câu từ nơi khác chưa được sửa, hay từ server.
      // Câu chứa dấu hiệu kỹ thuật bị THAY bằng câu chung (lỗi) hoặc BỎ (thông tin), và bản gốc
      // được ghi ra console cho lập trình viên.
      const msg2 = safeMessage(msg, '');
      if (!msg2) {
        if (type === 'error') { this._pushToast('Có lỗi xảy ra. Vui lòng thử lại.', type, opts); }
        return;
      }
      this._pushToast(msg2, type, opts);
    },
    /** Phần hiển thị thật của toast (tách ra để cửa chặn ở trên luôn là nơi duy nhất kiểm tra nội dung). */
    _pushToast(msg, type = 'info', opts = {}) {
      // Use the Vue studio's own toast (works standalone); fall back to Alpine if present.
      this.flashMsg = msg; this.flashType = type;
      if (this._flashTimer) clearTimeout(this._flashTimer);
      this._flashTimer = setTimeout(() => { this.flashMsg = ''; }, 2600);
      if (window.Alpine?.store?.('toast')) window.Alpine.store('toast').show(msg, type);
      // [Trục 2] Đẩy vào hàng đợi thông báo. Lỗi giữ lâu hơn để kịp đọc và còn dấu vết sau khi tắt.
      this.notify(msg, type, opts);
    },
    /**
     * [Trục 2 — 2026-09-20] Thông báo kiểu VSCode: xếp chồng ở góc phải-dưới, tự tắt theo loại,
     * đóng tay được. Giữ tối đa 4 mục để không che canvas.
     */
    notify(msg, type = 'info', opts = {}) {
      // Lọc lại lần nữa: notify() cũng được gọi TRỰC TIẾP (không qua toast) ở vài nơi.
      const text = safeMessage(msg, '');
      if (!text) return null;
      const id = ++this._notifSeq;
      const ttl = opts.sticky ? 0 : (opts.ttl != null ? opts.ttl : (type === 'error' ? 8000 : 4200));
      this.notifications.push({ id, msg: text, type, ttl, action: opts.action || null, at: Date.now() });
      // Giữ 4 mục gần nhất — mục cũ nhất tự rụng (không để hàng đợi phình vô hạn).
      if (this.notifications.length > 4) this.notifications.splice(0, this.notifications.length - 4);
      return id;
    },
    dismissNotification(id) {
      const i = this.notifications.findIndex((n) => n.id === id);
      if (i !== -1) this.notifications.splice(i, 1);
    },
    clearNotifications() { this.notifications = []; },
    async loadDefaults() {
      // §5.2: trước đây catch nuốt lỗi hoàn toàn (không log) -> /api/defaults fail thì người dùng
      // chỉ thấy giá trị mặc định mà không có dấu vết nào để chẩn đoán.
      try {
        const cfg = await fetch('/api/defaults', { headers: { Accept: 'application/json' } });
        const defaults = await cfg.json();
        this._applyDefaultValues(defaults);
        this.defaultsLoaded = true;
        return defaults;
      } catch (e) { console.error('studio loadDefaults failed', e); return null; }
      finally {
        // ƯU TIÊN local (luôn chạy, kể cả khi fetch defaults lỗi): khôi phục cài đặt prompt
        // người dùng đã lưu — ghi đè giá trị từ database.
        this.restorePromptMemory();
      }
    },
    _applyDefaultValues(defaults) {
      if (!defaults) return;
      if (defaults.creative_level != null) this.creativeLevel = Number(defaults.creative_level);
      if (defaults.texture != null) this.texture = Number(defaults.texture);
      if (defaults.image_resolution) this.imageRes = defaults.image_resolution;
      if (defaults.image_ratio) this.imageRatio = defaults.image_ratio;
      if (defaults.video_duration) this.videoDuration = defaults.video_duration;
      if (defaults.video_resolution) this.videoRes = defaults.video_resolution;
      if (defaults.negative_prompt !== undefined) this.negativePromptEn = defaults.negative_prompt;
      if (defaults.prompt_prefix !== undefined) this.promptPrefix = defaults.prompt_prefix;
      if (defaults.prompt_suffix !== undefined) this.promptSuffix = defaults.prompt_suffix;
      //  Gợi ý từ ảnh — trạng thái + ngôn ngữ + độ bám/chi tiết mặc định.
      if (defaults.suggest_enabled !== undefined) this.suggestEnabled = !!defaults.suggest_enabled;
      if (defaults.suggest_default_lang) this.suggestLang = defaults.suggest_default_lang === 'vi' ? 'vi' : 'en';
      if (defaults.suggest_adherence != null) this.suggestAdherence = Number(defaults.suggest_adherence);
      if (defaults.suggest_detail_level != null) this.suggestDetailLevel = Number(defaults.suggest_detail_level);
      if (defaults.image_credits != null) this.imageCreditCost = Number(defaults.image_credits);
      // Card Sửa ảnh: danh sách model chỉnh sửa (mặc định đứng đầu).
      if (Array.isArray(defaults.inpaint_models)) this.inpaintModels = defaults.inpaint_models;
      // Task groups: model theo nhóm công việc — selector trên từng card (Cài đặt →  Nhóm công việc).
      if (defaults.task_groups && typeof defaults.task_groups === 'object') this.taskGroups = defaults.task_groups;
      // Card "Kịch bản quay": preset video_scene từ Prompt Templates (Cài đặt).
      if (Array.isArray(defaults.video_scenes)) this.videoScenes = defaults.video_scenes;
      // Card "Sửa ảnh": preset chỉnh sửa (category inpaint) từ Prompt Templates (Cài đặt).
      if (Array.isArray(defaults.inpaint_presets)) this.inpaintEditPresets = defaults.inpaint_presets;
    },
    applyDefaults() {
      // Re-fetch and apply default values (used by reset button)
      this.loadDefaults();
    },
};
