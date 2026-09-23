// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — miền: thư viện ảnh · file tải lên · thư viện prompt & gợi ý.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt file gốc.
import { apiError, CSRF } from '../helpers.js';
export const libraryActions = {
    /**
     * MỞ THƯ VIỆN ẢNH trong chính SPA /studio — HÀM DUY NHẤT làm việc đó (2026-09-26).
     *
     * Vì sao đưa lên kho dữ liệu: việc "mở Thư viện" gồm HAI bước luôn phải đi cùng nhau — thoát công
     * cụ canvas trước (nếu đang crop/vẽ/mask mà nhảy sang Thư viện thì công cụ đó treo lại, quay về
     * canvas là thấy đang dở dang), rồi đổi cờ studioView. Trước đây hai bước đó được chép ở TỪNG nơi
     * gọi (StudioApp.vue · components/LibraryCard.vue) — thêm một lối vào thứ ba (thẻ «Thư viện» trong
     * khung chat, components/ChatModal.vue) là thêm bản sao thứ ba, và bản nào quên exitCanvasTools()
     * thì lối vào đó hành xử khác hai lối kia.
     *
     * KHÔNG tự đóng chat: người gọi quyết định (ChatModal đóng chat ngay sau đó để lớp phủ không che
     * Thư viện vừa mở).
     */
    openLibrary() {
      this.exitCanvasTools();
      this.studioView = 'library';
    },
    // ── Thư viện (/api/library) — quản lý + xóa ảnh cũ / ảnh rác ──
    async _libraryFetch(url, body = null) {
      const opts = body == null
        ? { method: 'GET', headers: { Accept: 'application/json' } }
        : { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) };
      const res = await fetch(url, opts);
      const d = await res.json().catch(() => ({}));
      if (!res.ok || res.redirected || !(res.headers.get('content-type') || '').includes('application/json')) {
        throw apiError(d, 'Có lỗi xảy ra.');
      }
      return d;
    },
    async loadLibrary(reset = true) {
      if (this.libraryLoading) return;
      this.libraryLoading = true;
      if (reset) { this.libraryItems = []; this.librarySelection = []; this.libraryFilters.page = 1; }
      try {
        const qs = new URLSearchParams();
        if (this.libraryFilters.type) qs.set('type', this.libraryFilters.type);
        if (this.libraryFilters.status) qs.set('status', this.libraryFilters.status);
        if (this.libraryFilters.project_id !== '' && this.libraryFilters.project_id != null) qs.set('project_id', String(this.libraryFilters.project_id));
        if (this.libraryFilters.q) qs.set('q', this.libraryFilters.q);
        qs.set('page', String(this.libraryFilters.page || 1));
        qs.set('per_page', String(this.libraryFilters.per_page || 48));
        qs.set('old_days', String(this.libraryFilters.old_days || 30));
        if (this.librarySort) qs.set('sort', this.librarySort);
        const d = await this._libraryFetch('/api/library/data?' + qs.toString());
        const items = Array.isArray(d.items) ? d.items : [];
        this.libraryItems = reset ? items : this.libraryItems.concat(items.filter(x => !this.libraryItems.some(y => y.id === x.id)));
        this.libraryTotal = d.total ?? items.length;
        this.libraryStats = { ...(this.libraryStats || {}), ...(d.stats || {}) };
        this.libraryHasMore = !!d.has_more;
        this.libraryFilters.page = d.current_page || (this.libraryFilters.page + 1);
      } catch (e) { this.failToast(e, 'Không tải được thư viện.'); }
      finally { this.libraryLoading = false; }
    },
    async loadMoreLibrary() {
      if (!this.libraryHasMore || this.libraryLoading) return;
      // M05: page CHỈ lấy từ response (loadLibrary set this.libraryFilters.page = d.current_page).
      // Trước đây tăng ở đây RỒI tăng lần nữa trong loadLibrary -> nhảy cóc trang khi backend
      // không trả current_page. Không tăng trước nữa.
      await this.loadLibrary(false);
    },
    setLibraryFilter(key, value) {
      this.libraryFilters[key] = value;
      this.loadLibrary(true);
    },
    async refreshLibraryScan() {
      if (this.libraryScanning) return;
      this.libraryScanning = true;
      try {
        const d = await this._libraryFetch('/api/library/scan', { old_days: this.libraryFilters.old_days || 30 });
        this.libraryStats = {
          ...(this.libraryStats || {}),
          junk_count: d.junk?.count ?? 0,
          junk_bytes: d.junk?.bytes ?? 0,
          old_count: d.old?.count ?? 0,
          old_bytes: d.old?.bytes ?? 0,
          orphan_count: d.orphans?.count ?? 0,
          orphan_bytes: d.orphans?.bytes ?? 0,
        };
        return d;
      } catch (e) { this.failToast(e, 'Không quét được thư viện.'); return null; }
      finally { this.libraryScanning = false; }
    },
    toggleLibrarySelect(id) {
      const i = this.librarySelection.indexOf(id);
      if (i >= 0) this.librarySelection.splice(i, 1);
      else this.librarySelection.push(id);
    },
    librarySelectAll() {
      this.librarySelection = this.libraryItems.map(g => g.id);
    },
    librarySelectNone() { this.librarySelection = []; },
    librarySelectJunk() {
      this.librarySelection = this.libraryItems.filter(g => g.status === 'failed' || g.status === 'cancelled').map(g => g.id);
    },
    librarySelectOld() {
      const days = Number(this.libraryFilters.old_days) || 30;
      const cutoff = Date.now() - days * 86400000;
      // §5.2: trước đây chỉ dựa created_ts; bản ghi thiếu created_ts bị LOẠI ÂM THẦM khỏi "chọn ảnh cũ"
      // (người dùng thấy danh sách thiếu mà không hiểu vì sao). Nay fallback created_at rồi tới id.
      const tsOf = (g) => {
        if (g.created_ts) return g.created_ts * 1000;
        const t = g.created_at ? Date.parse(g.created_at) : NaN;
        return Number.isFinite(t) ? t : null;
      };
      this.librarySelection = this.libraryItems
        .filter(g => {
          if (g.status !== 'completed' || !g.media_url) return false;
          const t = tsOf(g);
          return t !== null && t < cutoff;
        })
        .map(g => g.id);
    },
    async libraryBulkDelete() {
      const ids = this.librarySelection.filter(Boolean);
      if (!ids.length) { this.toast('Chưa chọn ảnh nào để xóa.', 'error'); return false; }
      if (this.libraryCleaning) return false;
      this.libraryCleaning = true;
      try {
        const d = await this._libraryFetch('/api/library/bulk-delete', { ids });
        this.librarySelection = [];
        this.toast('Đã xóa ' + (d.deleted || 0) + ' mục · giải phóng ' + this.formatBytes(d.freed_bytes || 0) + '.');
        await this.loadLibrary(true);
        return true;
      } catch (e) { this.failToast(e, 'Lỗi xóa hàng loạt.'); return false; }
      finally { this.libraryCleaning = false; }
    },
    async libraryCleanup(scope) {
      if (this.libraryCleaning) return false;
      this.libraryCleaning = true;
      try {
        const d = await this._libraryFetch('/api/library/cleanup', { scope, old_days: this.libraryFilters.old_days || 30 });
        this.librarySelection = [];
        this.toast('Đã dọn xong · giải phóng ' + this.formatBytes(d.freed_bytes || 0) + '.');
        await this.loadLibrary(true);
        await this.refreshLibraryScan();
        return true;
      } catch (e) { this.failToast(e, 'Lỗi dọn dẹp.'); return false; }
      finally { this.libraryCleaning = false; }
    },
    formatBytes(bytes) {
      const n = Number(bytes) || 0;
      if (n < 1024) return n + ' B';
      if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
      if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
      return (n / 1073741824).toFixed(2) + ' GB';
    },
    // ── Files đã tải lên — quản lý + dọn file mồ côi ──
    async loadUploads() {
      if (this.uploadLoading) return;
      this.uploadLoading = true;
      try {
        const d = await this._libraryFetch('/api/uploads');
        this.uploadItems = Array.isArray(d.items) ? d.items : [];
        this.uploadStats = d.stats || null;
      } catch (e) { this.failToast(e, 'Không tải được danh sách file.'); }
      finally { this.uploadLoading = false; }
    },
    /**
     * Gắn / bỏ gắn một ẢNH TẢI LÊN vào bộ sưu tập (dự án).
     *
     * nh tải lên là FILE trên đĩa (không có dòng trong CSDL) nên quan hệ này nằm ở bảng riêng
     * `upload_project_links` — xem POST /api/projects/{project}/uploads.
     *
     * Vì sao cần: hai loại ảnh nằm CÙNG một Thư viện nhưng trước đây chỉ ảnh do AI tạo mới vào được
     * bộ sưu tập; ảnh người dùng tự tải lên thì không, dù đó thường là ảnh gốc của cả bộ.
     */
    async attachUploadProject(projectId, rel, action = 'attach') {
      if (!rel) return false;
      if (action === 'attach' && !projectId) return false;
      try {
        const d = await this.api('/api/projects/' + projectId + '/uploads', { rel, action });
        const it = this.uploadItems.find(f => f.rel === rel);
        if (it) it.project_id = (d && d.project_id != null) ? d.project_id : null;
        this.toast(action === 'detach' ? 'Đã bỏ ảnh khỏi bộ sưu tập.' : 'Đã gắn ảnh vào bộ sưu tập.');
        return true;
      } catch (e) { this.failToast(e, 'Không gắn được ảnh vào bộ sưu tập.'); return false; }
    },
    toggleUploadSelect(rel) {
      const i = this.uploadSelection.indexOf(rel);
      if (i >= 0) this.uploadSelection.splice(i, 1);
      else this.uploadSelection.push(rel);
    },
    uploadSelectUnused() {
      this.uploadSelection = this.uploadItems.filter(f => !f.used).map(f => f.rel);
    },
    uploadSelectNone() { this.uploadSelection = []; },
    async uploadBulkDelete() {
      const rels = this.uploadSelection.filter(Boolean);
      if (!rels.length) { this.toast('Chưa chọn file nào để xóa.', 'error'); return false; }
      if (this.uploadCleaning) return false;
      this.uploadCleaning = true;
      try {
        const d = await this._libraryFetch('/api/uploads/delete', { rels });
        this.uploadSelection = [];
        this.toast('Đã xóa ' + (d.deleted || 0) + ' file · giải phóng ' + this.formatBytes(d.freed_bytes || 0) + '.');
        await this.loadUploads();
        return true;
      } catch (e) { this.failToast(e, 'Lỗi xóa file.'); return false; }
      finally { this.uploadCleaning = false; }
    },
    async deleteUpload(rel) {
      if (!rel) return false;
      if (this.uploadCleaning) return false;
      this.uploadCleaning = true;
      try {
        const d = await this._libraryFetch('/api/uploads/delete', { rels: [rel] });
        this.uploadSelection = this.uploadSelection.filter(r => r !== rel);
        this.toast('Đã xóa ' + (d.deleted || 0) + ' file · giải phóng ' + this.formatBytes(d.freed_bytes || 0) + '.');
        await this.loadUploads();
        return true;
      } catch (e) { this.failToast(e, 'Lỗi xóa file.'); return false; }
      finally { this.uploadCleaning = false; }
    },
    async uploadCleanup() {
      if (this.uploadCleaning) return false;
      this.uploadCleaning = true;
      try {
        const d = await this._libraryFetch('/api/uploads/cleanup', {});
        this.uploadSelection = [];
        this.toast('Đã dọn xong · giải phóng ' + this.formatBytes(d.freed_bytes || 0) + '.');
        await this.loadUploads();
        return true;
      } catch (e) { this.failToast(e, 'Lỗi dọn dẹp.'); return false; }
      finally { this.uploadCleaning = false; }
    },
    // ── Thư viện Prompt phân tích ( Gợi ý từ ảnh) — kế thừa pattern từ libraryItems ──
    async loadSuggestLib(reset = true) {
      if (this.suggestLibLoading) return;
      this.suggestLibLoading = true;
      if (reset) { this.suggestLibFilters.page = 1; }
      try {
        const q = new URLSearchParams();
        if (this.suggestLibFilters.q) q.set('q', this.suggestLibFilters.q);
        if (this.suggestLibFilters.garment_type) q.set('garment_type', this.suggestLibFilters.garment_type);
        if (this.suggestLibFilters.project_id) q.set('project_id', this.suggestLibFilters.project_id);
        q.set('page', String(this.suggestLibFilters.page));
        q.set('per_page', String(this.suggestLibFilters.per_page));
        if (this.suggestLibSort) q.set('sort', this.suggestLibSort);
        const d = await fetch('/api/suggest-library/data?' + q.toString(), { headers: { Accept: 'application/json' } });
        if (!d.ok) throw new Error('Không tải được thư viện prompt.');
        const data = await d.json();
        this.suggestLibItems = data.items || [];
        this.suggestLibTotal = data.total || 0;
        this.suggestLibStats = data.stats || null;
        this.suggestLibHasMore = data.has_more || false;
        this.suggestLibFilters.page = data.current_page || 1;
      } catch (e) { this.failToast(e, 'Lỗi tải thư viện prompt.'); }
      finally { this.suggestLibLoading = false; }
    },
    setSuggestLibFilter(key, value) {
      this.suggestLibFilters[key] = value;
      this.suggestLibSelection = [];
      this.loadSuggestLib(true);
    },
    setSuggestLibSort(v) {
      this.suggestLibSort = v;
      this.suggestLibSelection = [];
      this.loadSuggestLib(true);
    },
    setLibrarySort(v) {
      this.librarySort = v;
      this.librarySelection = [];
      this.loadLibrary(true);
    },
    setUploadSort(v) {
      this.uploadSort = v; // client-side — không cần gọi lại API
    },
    suggestLibNextPage() {
      if (!this.suggestLibHasMore || this.suggestLibLoading) return;
      this.suggestLibFilters.page++;
      this.loadSuggestLib(false);
    },
    toggleSuggestLibSelect(id) {
      const i = this.suggestLibSelection.indexOf(id);
      if (i >= 0) this.suggestLibSelection.splice(i, 1);
      else this.suggestLibSelection.push(id);
    },
    suggestLibSelectAll() {
      this.suggestLibSelection = this.suggestLibItems.map(x => x.id);
    },
    suggestLibSelectNone() { this.suggestLibSelection = []; },
    async suggestLibBulkDelete() {
      const ids = this.suggestLibSelection.filter(Boolean);
      if (!ids.length) { this.toast('Chưa chọn prompt nào để xóa.', 'error'); return false; }
      try {
        const res = await fetch('/api/suggest-library/bulk-delete', {
          method: 'POST',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ ids }),
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(d, 'Lỗi xóa.');
        this.suggestLibSelection = [];
        this.toast('Đã xóa ' + (d.deleted || 0) + ' prompt.');
        await this.loadSuggestLib(true);
        return true;
      } catch (e) { this.failToast(e, 'Lỗi xóa prompt.'); return false; }
    },
    // ── CRUD prompt trong Thư viện Prompt (thêm / sửa / xóa đơn lẻ) ──
    async savePrompt(payload) {
      try {
        const d = await this._libraryFetch('/api/suggest-library', payload);
        this.toast('Đã thêm prompt vào Thư viện Prompt.');
        await this.loadSuggestLib(true);
        return d;
      } catch (e) { this.failToast(e, 'Lỗi thêm prompt.'); return null; }
    },
    async updatePrompt(id, payload) {
      try {
        const res = await fetch('/api/suggest-library/' + id, {
          method: 'PUT',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(payload),
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(d, 'Lỗi cập nhật.');
        this.toast('Đã cập nhật prompt.');
        await this.loadSuggestLib(true);
        return d;
      } catch (e) { this.failToast(e, 'Lỗi cập nhật prompt.'); return null; }
    },
    async deletePrompt(id) {
      try {
        const res = await fetch('/api/suggest-library/' + id, {
          method: 'DELETE',
          headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' },
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(d, 'Lỗi xóa.');
        this.suggestLibSelection = this.suggestLibSelection.filter(x => x !== id);
        this.toast('Đã xóa prompt.');
        await this.loadSuggestLib(true);
        return true;
      } catch (e) { this.failToast(e, 'Lỗi xóa prompt.'); return false; }
    },
    async saveSuggestResult() {
      if (!this.suggestResult || !this.suggestResult.image_prompt_en) {
        this.toast('Chưa có kết quả phân tích để lưu.', 'error');
        return;
      }
      if (this.suggestSaving) return;
      this.suggestSaving = true;
      try {
        // _meta (provider/model/thời gian) là thông tin phiên chạy — không lưu vào thư viện.
        const { _meta, _restored_from, _reference_url, ...suggestFields } = this.suggestResult;
        const body = {
          // Kết quả nạp lại từ 'gần đây' giữ ẢNH GỐC của nó; kết quả vừa phân tích thì dùng ảnh đang chọn.
          reference_url: _reference_url || this.upscaleSrc || '',
          project_id: this.appliedProjectId() || null,
          ...suggestFields,
        };
        const res = await fetch('/api/suggest-library/save', {
          method: 'POST',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(body),
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(d, 'Lỗi lưu.');
        this.toast('Đã lưu prompt vào Thư viện Prompt.');
        this.loadSuggestRecent();   // danh sách 'gần đây' trong card cập nhật ngay
      } catch (e) { this.failToast(e, 'Lỗi lưu prompt.'); }
      finally { this.suggestSaving = false; }
    },
    async applySuggestPrompt(item) {
      if (!item || !item.image_prompt_en) {
        this.toast('Prompt trống, không thể áp dụng.', 'error');
        return;
      }
      // Đưa prompt vào ô Tạo ảnh
      this.imagePromptEn = item.image_prompt_en;
      if (item.creative_level != null) this.creativeLevel = Number(item.creative_level);
      if (item.texture != null) this.texture = Number(item.texture);
      if (item.negative_prompt) this.negativePromptEn = item.negative_prompt;
      this.promptOpen = true;
      // Đánh dấu đã áp dụng
      try {
        await fetch('/api/suggest-library/apply/' + item.id, {
          method: 'POST',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: '{}',
        });
        // Cập nhật local item
        const local = this.suggestLibItems.find(x => x.id === item.id);
        if (local) { local.apply_count = (local.apply_count || 0) + 1; local.applied_at = new Date().toLocaleString('vi-VN'); }
      } catch (e) { /* bỏ qua lỗi — prompt vẫn được áp dụng */ }
      this.toast('Đã áp dụng prompt vào ô Tạo ảnh.');
    },
};
