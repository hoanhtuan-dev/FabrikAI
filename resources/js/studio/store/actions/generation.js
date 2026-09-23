// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — miền: nạp dữ liệu phiên · hàng đợi & tạo ảnh/video · poll trạng thái · inpaint (API).
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt file gốc.
import { apiError, userFacingError, CSRF } from '../helpers.js';
export const generationActions = {
    async load() {
      // Reset kết quả/canvas trước khi nạp lại để luôn phản ánh đúng dữ liệu server —
      // ảnh đã xóa sẽ không "sống lại" sau khi tải lại trang (khởi động lại).
      this.generations = [];
      this.previewId = null;
      this.preview = null;
      this.editSource = null;
      this.canvasLayers = [];
      this.activeLayerId = '';
      this.loadUpscaleMemory();
      // Load settings defaults from backend (set in Studio Settings page)
      await this.loadDefaults();
      // Chưa có người dùng đăng nhập (server không truyền user) → dừng sớm, hiển thị banner Đăng nhập.
      if (!this.user) { this.setAuthStatus(401); return; }
      try {
        const res = await fetch('/api/latest', { headers: { Accept: 'application/json' } });
        if (res.status === 403) { this.setAuthStatus(403); return; }
        if (res.status === 401 || res.redirected || (res.url && res.url.includes('/dang-nhap'))) { this.setAuthStatus(401); return; }
        this.setAuthStatus(200);
        // [Đợt 1 — 2026-09-19] Nạp gói & hạn mức credit (1 request, không chặn luồng chính).
        this.loadPlanStatus();
        const d = await res.json();
        const items = d.items || d.generations || [];
        if (Array.isArray(items)) this.generations = items;
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        // Khôi phục bố cục layer đã lưu (chỉ giữ layer còn hợp lệ) — thay cho việc tự chọn ảnh kết quả cũ.
        // KHÔNG tự in lại các kết quả cũ vào layer: việc này làm layer "sống lại" sau mỗi lần tải lại
        // và khiến người dùng không thể xóa chúng khỏi canvas. Kết quả vẫn hiển thị ở Output/Thư viện.
        this.restoreLayerLayout();
        this.restoreBarSettings();
        // Deep-link từ Studio Library: /studio?step=2|3&id=<genId> — khôi phục đúng bước + ảnh.
        const sp = new URLSearchParams(window.location.search);
        const stepParam = parseInt(sp.get('step') || '', 10);
        if (stepParam >= 1 && stepParam <= 3) this.step = stepParam;
        const idParam = parseInt(sp.get('id') || '', 10);
        if (idParam) {
          const target = this.generations.find(g => g.id === idParam);
          if (target) this.select(target);
        }
      } catch (e) { console.error('studio load failed', e); }
    },
    select(g) {
      if (!g) return;
      this.previewId = g.id;
      this.preview = { id: g.id, media_url: g.media_url, type: g.type || 'image', status: g.status || 'completed' };
      if (g.media_url) {
        // BƯỚC 5.1 (2026-09-26): đặt ẢNH ĐANG LÀM VIỆC tường minh TRƯỚC khi đẩy layer.
        // Hôm nay hai thứ trùng nhau nên hành vi KHÔNG đổi; khi bỏ canvas (5.4) thì đây là thứ
        // duy nhất còn lại, và 8 card đọc nó qua getter upscaleSrc mà không phải sửa gì.
        this.setWorkingImage({ id: g.id, media_url: g.media_url, type: g.type || 'image' }, 'generation');
        this.pushCanvasLayer(String(g.id), 'gen', 'Ảnh #' + g.id, g.media_url, g.id);
        this.setActiveLayer(String(g.id));
      }
    },

    /**
     * ĐẶT ẢNH ĐANG LÀM VIỆC — "tôi đang sửa ẢNH NÀO", độc lập với layer/canvas.
     *
     * VÌ SAO CẦN (bước 5.1 của kế hoạch bỏ canvas): hôm nay câu hỏi đó chỉ trả lời được gián tiếp
     * qua "layer nào đang chọn". Mọi công cụ một-ảnh đọc getter upscaleSrc, mà getter đó lấy từ
     * layer ⇒ bỏ layer là bỏ luôn ảnh nguồn của 8 card. Tách ra thành dữ liệu tường minh thì việc
     * bỏ canvas chỉ còn là XOÁ một nhánh trong getter, không phải viết lại card nào.
     *
     * Nhận: URL (chuỗi) hoặc một generation ({ id, media_url }).
     * kind: 'generation' (ảnh kết quả) | 'source' (ảnh nguồn/upload) | 'upload'.
     *
     * @returns {{id:*,url:string,name:string,kind:string,genId:*}|null}
     */
    setWorkingImage(img, kind = 'generation') {
      if (!img) { this.workingImage = null; return null; }

      const url = typeof img === 'string' ? img : (img.media_url || img.url || '');
      if (!url) return null;

      const id = (typeof img === 'object' && img.id != null) ? img.id : null;
      const name = (typeof img === 'object' && img.name)
        ? String(img.name)
        : (id != null ? 'Ảnh #' + id : 'Ảnh đang chọn');

      this.workingImage = {
        id,
        url: String(url),
        name,
        kind,
        genId: kind === 'generation' ? id : null,
      };

      return this.workingImage;
    },
    // Đảm bảo một ảnh kết quả được "in" vào layer canvas (id duy nhất, không trùng).
    syncLayerForGen(id, mediaUrl, name, setActive) {
      if (!id || !mediaUrl) return;
      const lid = String(id);
      if (this.canvasLayers.some((l) => l.id === lid)) return;
      this.pushCanvasLayer(lid, 'gen', name || 'Ảnh #' + id, mediaUrl, id);
      if (setActive) this.setActiveLayer(lid);
    },
    /**
     * ĐIỀN SẴN KHỐI XUẤT GÓI từ MẪU VIỆC đang chờ (store.pendingExport).
     *
     * Mẫu việc "Mẫu kỹ thuật gửi xưởng" có kèm bảng size + ghi chú kỹ thuật; người dùng bấm mẫu rồi mở
     * khối xuất gói thì KHÔNG phải gõ lại. Chỉ điền khi ô còn trống — không ghi đè thứ người dùng đã nhập.
     */
    applyPendingExport(form) {
      const tpl = this.pendingExport;
      if (!tpl || !form) return;
      if (!String(form.sizes || '').trim() && tpl.sizes) form.sizes = tpl.sizes;
      if (!String(form.note || '').trim() && tpl.note) form.note = tpl.note;
    },

    /**
     * XUẤT GÓI CHO XƯỞNG — MỘT đường dữ liệu duy nhất.
     *
     * Trước đây hàm này bị CHÉP HAI LẦN (card trong sidebar và trang /bo-suu-tap), mỗi bản tự gọi
     * fetch('/api/projects/{id}/export') và tự dựng thẻ <a download> — 26 dòng trùng nhau, sửa một bên
     * là hai bên lệch. Nay cả hai gọi hàm này; lỗi được NÉM RA để lớp giao diện tự nói câu phù hợp.
     *
     * @returns {Promise<string>} tên file đã tải (đọc từ Content-Disposition của server).
     */
    async exportProject(id, { sizes = '', note = '', channel = '' } = {}) {
      const q = new URLSearchParams();
      if (String(sizes).trim()) q.set('sizes', String(sizes).trim());
      if (String(note).trim()) q.set('note', String(note).trim());
      if (String(channel).trim()) q.set('channel', String(channel).trim());
      const res = await fetch('/api/projects/' + id + '/export' + (q.toString() ? '?' + q.toString() : ''), { headers: { Accept: 'application/zip' } });
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw apiError(err, 'Không tạo được gói xuất (' + res.status + ').');
      }
      const blob = await res.blob();
      const cd = res.headers.get('Content-Disposition') || '';
      const m = cd.match(/filename="?([^"]+)"?/);
      const name = m ? m[1] : ('fabrikai-' + id + '.zip');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = name;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(a.href);
      return name;
    },

    async processQueue() {
      try { await fetch('/api/process', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' } }); } catch (e) { console.error('studio operation failed', e); }
      // refresh the generations so the processed images appear
      try { const res = await fetch('/api/latest', { headers: { Accept: 'application/json' } }); const d = await res.json(); const items = d.items || d.generations || []; if (Array.isArray(items)) this.generations = items; } catch (e) { console.error('studio operation failed', e); }
    },
    /**
     * Bộ dựng payload cho MỘT lần tạo ảnh 2D — dùng CHUNG cho tạo lẻ (generateImage) và tạo HÀNG LOẠT
     * (generateBatch). Tách ra để hai đường không bao giờ lệch cấu hình (tỉ lệ, độ phân giải, phom
     * dáng, prefix/suffix, dự án đang áp dụng, model đang chọn…).
     */
    /**
     * TẠO HÀNG LOẠT — mỗi dòng là một sản phẩm/ý tưởng, dùng CHUNG cài đặt đang chọn trên card.
     *
     * Vì sao cần (tiết kiệm thời gian): việc thật của nhà thiết kế/chủ shop là ra ảnh cho CẢ BỘ —
     * 8 SKU × 2 bối cảnh = 16 ảnh. Trước đây phải sửa prompt rồi bấm tạo 16 lần.
     *
     * Cách làm: gọi tuần tự /api/generate cho từng mục (KHÔNG thêm endpoint mới) — nhờ vậy mỗi ảnh
     * vẫn đi đúng đường cũ: trừ credit THEO GÓI, ghi sổ cái, hạ độ phân giải theo cap của gói (trả
     * `notice`), và mục lỗi KHÔNG làm hỏng cả lượt (báo rõ mục nào lỗi).
     */
    async generateBatch(prompts, variantsPerItem = 1) {
      const list = (prompts || []).map((p) => String(p).trim()).filter(Boolean).slice(0, 12);
      if (!list.length || this.generating) return null;

      const per = Math.max(1, Math.min(4, Number(variantsPerItem) || 1));
      let sent = 0;
      let failed = 0;
      const allIds = [];
      let noticeShown = false;

      this.generating = true;
      this.generateStage = 'preparing';
      this.lastBatch = [];
      this.batchFailed = [];
      // Theo dõi TỪNG MỤC (prompt · xong/lỗi · id ảnh) để giao diện hiện tiến trình thật và cho phép
      // CHẠY LẠI CHỈ NHỮNG MỤC LỖI — trước đây mục lỗi chỉ hiện toast rồi mất, người dùng phải tự nhớ.
      this.batchSend = { total: list.length, done: 0, failed: 0, current: list[0], images: 0, items: [] };

      try {
        for (let i = 0; i < list.length; i++) {
          this.batchSend.current = list[i];
          const entry = { prompt: list[i], ok: false, error: null, ids: [] };
          this.batchSend.items.push(entry);
          try {
            const d = await this.api('/api/generate', this.imagePayload(list[i], per));
            const items = Array.isArray(d.items) ? d.items : (d.generation_id ? [d] : []);
            items.forEach((it) => this.addGen({ id: it.generation_id, type: 'image', status: it.status, model: it.model, provider: it.provider, media_url: it.media_url, error: it.error, credits_cost: 1, created_at: 'Hàng loạt' }));
            items.forEach((it) => { if (it.generation_id) { allIds.push(it.generation_id); entry.ids.push(it.generation_id); } });
            entry.ok = true;
            sent++;
            // Nói thật khi gói giới hạn độ phân giải (backend hạ cap) — chỉ báo MỘT lần cho cả lượt.
            if (!noticeShown && d.notice) { noticeShown = true; this.toast(d.notice, 'info'); }
            if (d.credit_warning) this.toast(d.credit_warning, 'error');
            if (d.credits_left != null) this.creditsLeft = d.credits_left;
          } catch (e) {
            failed++;
            entry.error = userFacingError(e, 'Không rõ nguyên nhân.');
            this.batchFailed.push(list[i]);
            this.toast('Mục ' + (i + 1) + ' lỗi: ' + entry.error, 'error');
          }
          this.batchSend.done = i + 1;
          this.batchSend.failed = failed;
          this.batchSend.images = allIds.length;
        }

        if (allIds.length) {
          this.setBatch(allIds);
          this.processQueue();
          this.generatedCount = allIds.length;
          this.syncBatchProgress();
          allIds.forEach((id) => this.pollGeneration(id));
        }
      } finally {
        this.generating = false;
        const failNote = failed ? ' · ' + failed + ' mục lỗi (bấm «Chạy lại mục lỗi»)' : '';
        this.toast('Đã gửi ' + sent + '/' + list.length + ' mục · ' + allIds.length + ' ảnh đang tạo' + failNote, failed && !sent ? 'error' : 'info');
        // Giữ danh sách mục lỗi lại (không xoá cùng batchSend) để còn chạy lại được.
        setTimeout(() => { if (this.batchSend) this.batchSend = null; }, 8000);
      }

      return allIds;
    },
    imagePayload(prompt, variants = 1) {
      return {
        prompt,
        creative_level: this.creativeLevel,
        texture: this.texture,
        // Model do người dùng chọn trên card ('' = default nhóm image — Cài đặt →  Nhóm công việc).
        ...(this.selectedTaskModel('image') ? { provider: this.selectedTaskModel('image').provider, model: this.selectedTaskModel('image').model } : {}),
        // Tôn trọng checkbox: tắt → gửi rỗng → backend bỏ qua prefix/suffix/negative.
        negative_prompt: this.promptUseNegative ? (this.negativePromptEn || '') : '',
        prompt_prefix: this.promptUsePrefix ? (this.promptPrefix || '') : '',
        prompt_suffix: this.promptUseSuffix ? (this.promptSuffix || '') : '',
        resolution: this.imageRes,
        ratio: this.imageRatio,
        variants: Math.max(1, Math.min(4, Number(variants) || 1)),
        // Phom dáng + tóc
        body_height: this.bodyHeight,
        body_build: this.bodyBuild,
        body_waist: this.bodyWaist,
        body_shoulders: this.bodyShoulders,
        body_hips: this.bodyHips,
        hair_style: this.hairStyle || '',
        hair_color: this.hairColor || '',
        pose_id: this.imagePoseId || '',
        // "Dự án hiện tại": ảnh tạo ra sẽ tự gắn vào dự án đang áp dụng
        // (null khi đang ở chế độ duyệt → không gắn vào dự án người khác).
        project_id: this.appliedProjectId(),
        // Gieo quẻ (seed): nếu có → gửi lên backend để tạo ảnh nhất quán
        seed: this.imageSeed || null,
      };
    },
    async generateImage() {
      if (!this.imagePromptEn || this.generating) return;
      this.generating = true;
      this.generateProgress = 0;
      this.generateStage = 'preparing';
      this.generatedCount = 0;
      this.lastBatch = [];
      const variants = Number(this.variantCount) || 1;
      // [Đợt 0.2] BỎ tiến trình mô phỏng: thanh % trước đây là bộ đếm lặp cộng ngẫu nhiên 4–12%,
      // khoá ở 90% rồi API trả về là set 100% + "Hoàn tất!" trong khi ảnh còn nằm 'pending' ở queue.
      // Người dùng bị "lừa" đúng lúc dễ bỏ đi nhất (xem STUDIO_REVIEW_PLAN.md Đợt 0.2). Tiến trình
      // BÂY GIỜ được cộng dồn từ TRẠNG THÁI THẬT của từng generation qua syncBatchProgress().
      try {
        // Payload dựng qua imagePayload() — DÙNG CHUNG với tạo hàng loạt, để hai đường không lệch
        // cấu hình (tỉ lệ, độ phân giải, phom dáng, prefix/suffix, dự án đang áp dụng, model đang chọn).
        const d = await this.api('/api/generate', this.imagePayload(this.imagePromptEn, variants));
        const items = Array.isArray(d.items) ? d.items : (d.generation_id ? [d] : []);
        items.forEach((it) => this.addGen({ id: it.generation_id, type: 'image', status: it.status, model: it.model, provider: it.provider, media_url: it.media_url, error: it.error, credits_cost: 1, created_at: 'Vừa gửi' }));
        this.setBatch(items.map(it => it.generation_id));
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        this.processQueue();
        // TIẾN TRÌNH THẬT: không tuyên bố "Hoàn tất!" khi backend mới trả 'pending' → theo dõi
        // từng ảnh; mỗi lần trạng thái đổi, syncBatchProgress() cộng dồn % theo số ảnh ĐÃ XONG.
        this.generatedCount = items.length;
        this.syncBatchProgress();
        items.forEach((it) => { if (it.generation_id) this.pollGeneration(it.generation_id); });
      } catch (e) { this.failToast(e, 'Lỗi tạo ảnh.'); }
      finally {
        this.generating = false;
      }
    },
    // i2i — Tạo lại ảnh từ ảnh cho trước (Reimagine / Variation)
    // process=true (mặc định) chạy processQueue ngay sau khi tạo. Render đa góc truyền process=false
    // để tạo TẤT CẢ góc trước rồi gọi processQueue MỘT lần — tránh nhiều processQueue chạy song song,
    // cùng "nhặt" các generation đang chờ và xử lý lặp/đè nhau (gây tốn quota + lỗi "chỉ ra 1 ảnh").
    // model=null dùng Qwen Edit cấu hình; truyền {provider,model} để tôn trọng model đang chọn trên card.
    async reimagine(image, prompt, similarity = 70, variants = 1, process = true, model = null) {
      if (!image || !(prompt || '').trim()) { this.toast('Chọn ảnh + nhập mô tả.', 'error'); return null; }
      try {
        const payload = { image, prompt, similarity: Number(similarity) || 70, variants: Number(variants) || 1, ...this.projectField() };
        if (model && model.provider && model.model) { payload.provider = model.provider; payload.model = model.model; }
        const d = await this.api('/api/reimagine', payload);
        const items = Array.isArray(d.items) ? d.items : (d.generation_id ? [d] : []);
        items.forEach((it) => this.addGen({ id: it.generation_id, type: 'image', status: it.status, model: it.model, provider: it.provider, media_url: it.media_url, error: it.error, credits_cost: 1, created_at: 'Vừa tạo lại ảnh' }));
        if (items.length) this.setBatch(items.map(it => it.generation_id));
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        if (process) this.processQueue();
        return items;
      } catch (e) { this.failToast(e, 'Lỗi tạo lại ảnh.'); return null; }
    },
    // i2i — Tạo ẢNH MỚI từ ảnh tham chiếu (card "Tạo ảnh mới từ ảnh mẫu", mode='refgen').
    // KHÁC reimagine/edit: dùng model SINH ẢNH (mặc định qwen-image-3.0-pro) + ảnh tham chiếu
    // làm base → tạo bức ảnh mới giống mẫu theo % tương đồng, không sửa trên ảnh gốc.
    // tryon=true (chip "Thử đồ"): gửi 1 ảnh trang phục → sinh ảnh người mẫu mặc đúng đồ đó
    // (rẻ hơn tryon-by-edit, dùng model sinh ảnh). Kế thừa body directive + khuôn mặt mẫu (faceModelId)
    // + pose mẫu (poseId — AI đọc ẢNH pose để tạo mô tả tư thế, không gửi ảnh pose vào model).
    // background (cả 2 chế độ) / angle (chỉ "Tạo ảnh mới"): gửi RIÊNG, không nối vào prompt.
    async refgen(image, prompt = '', similarity = 70, variants = 1, model = null, tryon = false, body = null, faceModelId = '', poseId = '', background = '', angle = '') {
      if (!image) { this.toast('Chọn ảnh tham chiếu.', 'error'); return null; }
      try {
        const payload = { image, prompt: prompt || '', similarity: Number(similarity) || 70, variants: Number(variants) || 1, ...this.projectField() };
        // Ưu tiên: model truyền từ card > default nhóm image (Cài đặt →  Nhóm công việc).
        const taskModel = this.selectedTaskModel('image');
        const eff = (model && model.provider && model.model) ? model : taskModel;
        if (eff) { payload.provider = eff.provider; payload.model = eff.model; }
        // Nền studio áp dụng cho cả 2 chế độ; góc chụp chỉ cho "Tạo ảnh mới".
        if (background) payload.background_prompt = background;
        if (!tryon && angle) payload.angle_prompt = angle;
        if (tryon) {
          payload.tryon = true;
          if (faceModelId) payload.face_model_id = faceModelId;
          if (poseId) payload.pose_id = poseId;
          // Kế thừa body directive (tạo ảnh 2D) — chỉ gửi khi người dùng đã chỉnh (khác default 5).
          if (body) {
            if (body.height != null) payload.body_height = Number(body.height);
            if (body.build != null) payload.body_build = Number(body.build);
            if (body.waist != null) payload.body_waist = Number(body.waist);
            if (body.shoulders != null) payload.body_shoulders = Number(body.shoulders);
            if (body.hips != null) payload.body_hips = Number(body.hips);
          }
        }
        const d = await this.api('/api/refgen', payload);
        const items = Array.isArray(d.items) ? d.items : (d.generation_id ? [d] : []);
        items.forEach((it) => this.addGen({ id: it.generation_id, type: 'image', status: it.status, model: it.model, provider: it.provider, media_url: it.media_url, error: it.error, credits_cost: 1, created_at: 'Vừa tạo ảnh mới' }));
        if (items.length) this.setBatch(items.map(it => it.generation_id));
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        this.processQueue();
        // Poll từng kết quả chưa hoàn tất để khi backend xong → tự "in" vào canvas (pushCanvasLayer)
        // + liệt kê vào Layers panel (setActiveLayer), giống cơ chế Inpaint/Swap. Mặc định
        // processQueue chỉ refresh /api/latest nên kết quả pending sẽ kẹt ở Output mà không lên canvas.
        items.forEach((it) => { if (it.generation_id && it.status !== 'completed') this.pollGeneration(it.generation_id); });
        return items;
      } catch (e) { this.failToast(e, 'Lỗi tạo ảnh từ ảnh mẫu.'); return null; }
    },
    // Lazy worker poll: theo dõi một generation qua /api/generations/{id} (backend tự xử lý
    // job đang pending và tự "heal" job kẹt). Single-flight: không bao giờ gửi 2 request song song.
    // opts.select=false (Render đa góc): chỉ cập nhật trạng thái trong this.generations, KHÔNG
    // tự select/đẩy layer canvas — để 4 góc không chèn 4 layer vào canvas đè lên nhau.
    pollGeneration(id, opts = {}) {
      const autoSelect = opts.select !== false;
      if (this._pollTimers[id]) return;
      // §5.2: trước đây poll 500ms KHÔNG có trần — generation kẹt 'processing' (job bị giết,
      // worker không chạy) sẽ poll vô hạn, tốn request và không bao giờ dừng. Nay có trần 10 phút
      // (backend tự "heal" job kẹt sau 6-8 phút, nên 10 phút là đủ rộng).
      const startedAt = Date.now();
      const MAX_POLL_MS = 10 * 60 * 1000;
      const tick = async () => {
        if (Date.now() - startedAt > MAX_POLL_MS) {
          delete this._pollTimers[id];
          console.error('studio pollGeneration: quá thời gian theo dõi', id);
          this.toast('Quá 10 phút chưa có kết quả — đã dừng theo dõi. Bấm "Xử lý ngay" hoặc tải lại trang để cập nhật.', 'error');
          return;
        }
        try {
          const res = await fetch('/api/generations/' + id, { headers: { Accept: 'application/json' } });
          if (!res.ok) { delete this._pollTimers[id]; return; }
          const g = await res.json();
          const item = this.generations.find(x => x.id === Number(g.id));
          if (item) { item.status = g.status; item.media_url = g.media_url; item.error = g.error; item.model = g.model; item.provider = g.provider; item.elapsed_ms = g.elapsed_ms; if (g.meta && typeof g.meta === 'object') item.meta = g.meta; item.is_demo = !!g.is_demo; item.demo_reason = g.demo_reason || null;
            // Dự án có thể đã đổi ở tab khác (gắn/bỏ gắn) — show() trả về nên đồng bộ lại.
            if (g.project_id !== undefined) { item.project_id = g.project_id; item.project = g.project; } }
          // [Đợt 0.2] trạng thái THẬT vừa đổi ⇒ cập nhật thanh tiến trình của lô (không mô phỏng).
          this.syncBatchProgress();
          if (['completed', 'failed', 'cancelled'].includes(g.status)) {
            delete this._pollTimers[id];
            const isInpaint = String(id) === String(this.inpaintGenId);
            const isCompose = this.composeGenIds.length && this.composeGenIds.includes(Number(id));
            if (g.status === 'completed' && g.media_url) {
              if (isInpaint) { this.inpaintStage = 'done'; this.toast('Đã sửa xong ảnh.'); }
              if (autoSelect) this.select({ id: g.id, media_url: g.media_url, type: 'image', status: 'completed' });
            } else if (g.status === 'failed') {
              if (isInpaint) { this.inpaintStage = 'error'; this.inpaintError = g.error || 'Sửa ảnh thất bại.'; this.toast(this.inpaintError, 'error'); }
            } else {
              if (isInpaint) { this.inpaintStage = 'cancelled'; this.toast('Đã hủy sửa ảnh.'); }
            }
            if (isCompose) this._checkComposeDone();
            return;
          }
        } catch (e) { delete this._pollTimers[id]; return; }
        this._pollTimers[id] = setTimeout(tick, 500);
      };
      this._pollTimers[id] = setTimeout(tick, 2000);
    },
    async inpaint(prompt) {
      // Nguồn ảnh = ẢNH ĐANG CHỌN TRÊN CANVAS (bất kỳ: upload / sản phẩm / kết quả / đã chỉnh sửa).
      const src = this.upscaleSrc || (this.preview && this.preview.media_url) || '';
      if (!src || this.inpainting) { this.toast('Chọn một ảnh trên canvas để sửa.', 'error'); return; }
      if (!(prompt || '').trim()) { this.toast('Nhập mô tả chỉnh sửa.', 'error'); return; }
      this.inpainting = true;
      this.inpaintError = '';
      this.inpaintStage = 'send';
      this.inpaintStartTs = Date.now();
      try {
        const body = { prompt, preserve_background: this.inpaintPreserveBg, preserve_face: this.inpaintPreserveFace, source_url: src, feather: Number(this.inpaintFeather) || 0, ...this.projectField() };
        // Model do người dùng CHỌN trên card Sửa ảnh ('' = mặc định: Qwen Edit trong Cài đặt).
        const selModel = this.inpaintModel ? this.inpaintModels.find(o => o.provider + ':' + o.model === this.inpaintModel) : null;
        if (selModel) { body.provider = selModel.provider; body.model = selModel.model; }
        // Gửi mask đã LƯU (bấm "Xong") — dù overlay công cụ đã tắt vẫn xử lý đúng vùng.
        if (this.inpaintMaskDone && this._inpaintMaskKind) {
          body.mask_mode = this._inpaintMaskKind;
          body.region = this.inpaintMaskBox;
          if (this._inpaintMaskKind === 'brush' && this.inpaintBrushData) {
            body.mask_data = this.inpaintBrushData;
          }
        }
        // Endpoint source-agnostic: nhận ẢNH BẤT KỲ (không phụ thuộc generation cha của Outputs).
        const d = await this.api('/api/inpaint', body);
        if (!d.generation_id) { throw apiError(d, 'Không tạo được yêu cầu sửa ảnh.'); }
        this.inpaintGenId = d.generation_id;
        this.inpaintStage = 'processing';
        this.addGen({ id: d.generation_id, type: 'image', status: d.status || 'pending', model: d.model || 'inpaint', provider: d.provider || 'qwen', media_url: d.media_url, error: d.error, credits_cost: d.credits_cost ?? 1, created_at: 'Vừa gửi' });
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        this.pollGeneration(d.generation_id);
      } catch (e) {
        this.inpaintError = userFacingError(e, 'Không sửa được ảnh. Vui lòng thử lại.');
        this.inpaintStage = 'error';
        this.toast(this.inpaintError, 'error');
      } finally { this.inpainting = false; }
    },
    async cancelInpaint() {
      if (!this.inpaintGenId || !this.inpaintStage) return;
      try { await this.api('/api/generations/' + this.inpaintGenId + '/cancel', {}); this.inpaintStage = 'cancelled'; this.toast('Đã hủy sửa ảnh.'); }
      catch (e) { this.failToast(e, 'Lỗi hủy.'); }
    },
    clearInpaintStatus() { this._inpaintStopDrag && this._inpaintStopDrag(); this.inpaintStage = ''; this.inpaintError = ''; this.inpaintGenId = null; this.inpaintStartTs = 0; this.inpaintMaskMode = 'none'; this.inpaintMaskDone = false; this._inpaintMaskKind = ''; this.inpaintBrushData = ''; this.inpaintErase = false; this._inpaintMaskCanvas = null; this._inpaintMaskCtx = null; this.inpaintMaskBox = { x: 0.425, y: 0.425, w: 0.15, h: 0.15 }; },
};
