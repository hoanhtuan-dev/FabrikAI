// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — miền: AGENT STUDIO: kế hoạch sản xuất · dữ liệu shop · DNA · nguồn tin · radar · brief.
// Action dùng this.* trỏ cùng store instance ⇒ gọi chéo giữa các miền hoạt động y hệt file gốc.
import { apiError, userFacingError, PLAN_ASSUMPTION_DEFAULTS, CSRF } from '../helpers.js';
/**
 * CẬP NHẬT SỐ LIỆU MÀ GIỮ CHỮ — gộp bản brief vừa tính lại với phần CHỮ của bản trước.
 *
 * [LỖI THẬT — 2026-09-21] Nút "Cập nhật cơ cấu / Áp dụng (tức thì)" chạy tất định cho nhanh, nhưng
 * bản trả về KHÔNG có phần chữ nên `brief`, `prompt_vi/en`, caption mood board… bị thay bằng câu do bộ
 * quy tắc viết. Người dùng chỉ định đổi SỐ MÃ HÀNG mà mất luôn brief do AI viết — và bản brief bị đóng
 * dấu "chạy bằng bộ quy tắc" rồi theo vào phiên làm việc.
 *
 * Luật gộp: SỐ lấy từ bản mới, CHỮ lấy từ bản cũ. Chỉ giữ chữ khi bản cũ THẬT SỰ do AI viết — bản cũ
 * cũng tất định thì không có gì để giữ.
 */
function keepAiText(fresh, previous) {
  if (!fresh || !previous) return fresh;
  if ((previous.model && previous.model.mode) !== 'ai') return fresh;

  const merged = { ...fresh };
  ['brief', 'prompt_vi', 'prompt_en', 'brand_narrative', 'next_steps', 'canvas'].forEach((key) => {
    if (previous[key] !== undefined && previous[key] !== null && previous[key] !== '') merged[key] = previous[key];
  });

  // Chữ NẰM TRONG cấu trúc: gộp theo khoá, chỉ lấy phần chữ — con số vẫn của bản mới.
  const categories = (fresh.structure && fresh.structure.categories) || [];
  const oldCategories = new Map(((previous.structure && previous.structure.categories) || [])
    .map((row) => [String(row.category || ''), row.rationale]));
  if (categories.length && oldCategories.size) {
    merged.structure = {
      ...fresh.structure,
      categories: categories.map((row) => (oldCategories.get(String(row.category || ''))
        ? { ...row, rationale: oldCategories.get(String(row.category || '')) }
        : row)),
    };
  }

  const outfits = fresh.outfit_matching || [];
  const oldGoals = new Map(((previous.outfit_matching) || []).map((row) => [String(row.id || ''), row.goal]));
  if (outfits.length && oldGoals.size) {
    merged.outfit_matching = outfits.map((row) => (oldGoals.get(String(row.id || ''))
      ? { ...row, goal: oldGoals.get(String(row.id || '')) }
      : row));
  }

  const freshMood = (fresh.moodboard && fresh.moodboard.items) || [];
  const oldMood = (previous.moodboard && previous.moodboard.items) || [];
  if (freshMood.length && oldMood.length) {
    merged.moodboard = {
      ...fresh.moodboard,
      items: freshMood.map((row, index) => (oldMood[index] && oldMood[index].source !== 'owner'
        ? { ...row, caption: oldMood[index].caption }
        : row)),
    };
  }

  // NÓI ĐÚNG: phần chữ này do AI viết (giữ nguyên), phần số vừa được tính lại bằng bộ quy tắc.
  merged.ai_applied = previous.ai_applied;
  merged.model = {
    ...(fresh.model || {}),
    mode: 'ai',
    reason: null,
    note: 'Phần chữ giữ nguyên từ lượt AI trước; các con số (SKU · size · dải giá) vừa được tính lại.',
  };

  return merged;
}
export const agentStudioActions = {
    async loadPlan(payload = {}, opts = {}) {
      // silent = tự tính lại khi gõ (không bật planLoading để nút không nhảy "Đang tính…",
      // không xoá kết quả cũ khi lỗi để bố cục không bị nhảy).
      const silent = !!opts.silent;
      if (!silent) this.planLoading = true;
      else this.planRecalculating = true;
      this.planError = '';
      try {
        const data = await this.api('/api/design-agent/plan', {
          ...payload,
          assumptions: { ...this.planAssumptions },
        });
        this.plan = data?.plan || null;
        this.planBasis = data?.brief_basis || null;
        return data;
      } catch (error) {
        this.planError = userFacingError(error, 'Không tính được kế hoạch sản xuất.');
        // silent: giữ nguyên kết quả cũ để màn hình không "nhảy" mất nội dung khi đang gõ.
        if (!silent) this.plan = null;
        throw error;
      } finally {
        if (!silent) this.planLoading = false;
        else this.planRecalculating = false;
      }
    },
    setPlanAssumption(key, value) {
      if (!(key in PLAN_ASSUMPTION_DEFAULTS)) return;
      const number = Number(value);
      this.planAssumptions = {
        ...this.planAssumptions,
        [key]: Number.isFinite(number) ? Math.max(0, number) : PLAN_ASSUMPTION_DEFAULTS[key],
      };
    },
    resetPlanAssumptions() {
      this.planAssumptions = { ...PLAN_ASSUMPTION_DEFAULTS };
    },
    /**
     * Lưu dữ liệu bán hàng thật của shop. Càng nhiều kỳ dữ liệu, cơ cấu SKU và dải giá càng sát
     * thực tế — đây là phần khiến Agent Studio dùng càng lâu càng có giá trị.
     */
    async saveShopSignals(rows = [], source = 'manual') {
      this.shopSaving = true;
      try {
        const data = await this.api('/api/design-agent/shop-signals', { rows, source });
        this.shopRows = Array.isArray(data?.rows) ? data.rows : [];
        this.shopSignal = data?.shop || null;
        this.shopDataDirty = true;
        const saved = Number(data?.saved) || 0;
        this.toast(saved ? 'Đã lưu ' + saved + ' dòng dữ liệu shop.' : 'Đã xoá dữ liệu shop.');
        return data;
      } catch (error) {
        this.toast(error.message || 'Không lưu được dữ liệu shop.', 'error');
        throw error;
      } finally {
        this.shopSaving = false;
      }
    },
    /** Wizard Agent Studio: radar → brief → canvas. Giữ designAgentTab để tương thích code cũ. */
    setDesignAgentStep(step) {
      // 'dna' là bước 0: khai hồ sơ shop TRƯỚC khi đọc xu hướng — mọi bước sau đều dùng nó.
      const allowed = ['dna', 'radar', 'brief', 'canvas'];
      this.designAgentStep = allowed.includes(step) ? step : 'radar';
      this.designAgentTab = this.designAgentStep === 'brief' ? 'collection' : 'trend';
    },
    // ══════════════════ DNA THƯƠNG HIỆU (Đợt 22 — 2026-09-23) ══════════════════
    /**
     * Nạp hồ sơ DNA của chính người dùng. Gọi MỘT lần khi mở Agent Studio.
     *
     * Vì sao không cache ở client: đây là dữ liệu người dùng vừa sửa — hiển thị bản cũ sau khi lưu là
     * lỗi tệ nhất của loại màn hình này (người dùng tin là chưa lưu rồi nhập lại).
     */
    async loadBrandDna() {
      this.brandDnaLoading = true;
      this.brandDnaError = '';
      try {
        const res = await fetch('/api/brand-dna', { headers: { Accept: 'application/json' } });
        if (!res.ok) throw apiError(await res.json().catch(() => ({})), 'Không tải được DNA shop.');
        const d = await res.json();
        this.brandDna = d;
        this.brandDnaDraft = JSON.parse(JSON.stringify(d.dna || {}));
        return d;
      } catch (e) {
        this.brandDnaError = userFacingError(e, 'Không tải được DNA shop.');
        return null;
      } finally {
        this.brandDnaLoading = false;
      }
    },
    /** Ghi lại bản nháp vào hồ sơ (upsert theo tài khoản). Trả true khi lưu được. */
    async saveBrandDna() {
      this.brandDnaSaving = true;
      this.brandDnaError = '';
      try {
        const res = await fetch('/api/brand-dna', {
          method: 'PUT',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(this.brandDnaDraft || {}),
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(d, 'Không lưu được DNA shop.');
        this.brandDna = d;
        this.brandDnaDraft = JSON.parse(JSON.stringify(d.dna || {}));
        this.toast('Đã lưu DNA shop — agent sẽ dùng hồ sơ này cho các brief sau.', 'success');
        return true;
      } catch (e) {
        this.brandDnaError = userFacingError(e, 'Không lưu được DNA shop.');
        this.toast(this.brandDnaError, 'error');
        return false;
      } finally {
        this.brandDnaSaving = false;
      }
    },
    /** Xoá hồ sơ (về "chưa khai") — KHÔNG đụng dữ liệu bán hàng hay dự án. */
    async resetBrandDna() {
      this.brandDnaSaving = true;
      this.brandDnaError = '';
      try {
        const res = await fetch('/api/brand-dna', {
          method: 'DELETE',
          headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' },
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw apiError(d, 'Không xoá được DNA shop.');
        this.brandDna = d;
        this.brandDnaDraft = JSON.parse(JSON.stringify(d.dna || {}));
        this.toast('Đã xoá hồ sơ DNA — hệ thống quay về suy ra từ dữ liệu của bạn.', 'info');
        return true;
      } catch (e) {
        this.brandDnaError = userFacingError(e, 'Không xoá được DNA shop.');
        return false;
      } finally {
        this.brandDnaSaving = false;
      }
    },
    /** Bỏ thay đổi chưa lưu: quay về đúng bản đang có trên máy chủ. */
    discardBrandDnaDraft() {
      this.brandDnaDraft = JSON.parse(JSON.stringify(this.brandDna?.dna || {}));
    },
    /**
     * Nạp DANH SÁCH NGUỒN NGOÀI + tin đang được đưa vào prompt. `force = true` khi bấm "Làm mới nguồn".
     *
     * Không cache ở client: bấm làm mới mà vẫn thấy bản cũ là nói dối người dùng.
     */
    async loadWebSources(force = false, region = 'all') {
      this.webSourcesLoading = true;
      this.webSourcesError = '';
      try {
        const q = new URLSearchParams({ region });
        if (force) q.set('force', '1');
        const res = await fetch('/api/design-agent/sources?' + q.toString(), { headers: { Accept: 'application/json' } });
        if (!res.ok) throw apiError(await res.json().catch(() => ({})), 'Không tải được danh sách nguồn ngoài.');
        this.webSources = await res.json();
        return this.webSources;
      } catch (e) {
        this.webSourcesError = userFacingError(e, 'Không tải được danh sách nguồn ngoài.');
        return null;
      } finally {
        this.webSourcesLoading = false;
      }
    },
    /**
     * ĐO khả năng truy cập internet của agent (máy chủ + model). `force = true` khi người dùng bấm
     * "Kiểm tra lại" — kết quả được máy chủ cache 10 phút nên bấm liên tục không tạo bão request.
     */
    async loadWebAccess(force = false) {
      this.webAccessLoading = true;
      this.webAccessError = '';
      try {
        const res = await fetch('/api/design-agent/web-access' + (force ? '?force=1' : ''), { headers: { Accept: 'application/json' } });
        if (!res.ok) throw apiError(await res.json().catch(() => ({})), 'Không kiểm tra được khả năng truy cập internet.');
        this.webAccess = await res.json();
        return this.webAccess;
      } catch (e) {
        this.webAccessError = userFacingError(e, 'Không kiểm tra được khả năng truy cập internet.');
        return null;
      } finally {
        this.webAccessLoading = false;
      }
    },
    /** Bật/tắt suy luận AI của Agent Studio; đổi chế độ ⇒ bỏ cache radar (kết quả khác nhau). */
    setDesignAgentAi(enabled) {
      const next = !!enabled;
      if (next === this.designAgentAi) return;
      this.designAgentAi = next;
      this.trendRadarCache = {};
      if (this.designAgentAi) this.toast('Đã bật AI — phần phân tích sẽ do AI thực hiện.');
      else this.toast('Đã tắt AI — phần phân tích được dựng tự động từ dữ liệu mẫu.');
    },
    /**
     * Nạp TrendRadar cho một khu vực. Có cache theo vùng + chế độ AI + chống race:
     * đổi vùng liên tục thì kết quả cũ không được ghi đè kết quả mới.
     */
    async loadTrendRadar(region = 'all', { force = false } = {}) {
      const key = String(region || 'all');
      const cacheKey = key + '|' + (this.designAgentAi ? 'ai' : 'rule');
      if (!force && this.trendRadarCache[cacheKey]) {
        this.trendRadar = this.trendRadarCache[cacheKey];
        this.trendRadarError = '';
        return this.trendRadar;
      }

      const requestId = (this.trendRadarRequest || 0) + 1;
      this.trendRadarRequest = requestId;
      this.trendRadarLoading = true;
      this.trendRadarError = '';
      try {
        const data = await this.api('/api/design-agent/radar', { region: key, ai: this.designAgentAi });
        if (requestId !== this.trendRadarRequest) return null; // có request mới hơn đang chạy
        this.trendRadar = data || null;
        if (data) this.trendRadarCache = { ...this.trendRadarCache, [cacheKey]: data };
        // Nạp sẵn dữ liệu bán hàng của shop để chủ xưởng thấy ngay mình đã nhập gì (không cần
        // thêm endpoint đọc riêng: radar đã trả về tín hiệu nội bộ của chính tài khoản này).
        const signal = data?.internal_brand_signal;
        if (signal) {
          if (Array.isArray(signal.shop_rows)) this.shopRows = signal.shop_rows;
          if (signal.shop) this.shopSignal = signal.shop;
        }
        return data;
      } catch (error) {
        if (requestId === this.trendRadarRequest) {
          this.trendRadarError = userFacingError(error, 'Không tải được TrendRadar.');
          // KHÔNG xoá kết quả đang xem: một lần mạng lỗi không được biến màn hình đang có dữ liệu thành
          // trắng — người dùng mất luôn thứ họ đang đọc và không hiểu vì sao. Chỉ báo lỗi ở trên.
          if (!this.trendRadar) this.trendRadar = null;
          this.toast(this.trendRadarError, 'error');
        }
        throw error;
      } finally {
        if (requestId === this.trendRadarRequest) this.trendRadarLoading = false;
      }
    },
    /** Chuẩn hoá input để so sánh brief hiện tại với input đang nhập (phát hiện brief cũ). */
    /** Đầu vào của brief (để phát hiện brief cũ) — GỒM cả ảnh mẫu: đổi ảnh là phải tạo lại. */
    designBriefInput(payload = {}) {
      return {
        prompt: String(payload.prompt || '').trim(),
        region: String(payload.region || 'all'),
        trend_ids: [...new Set((payload.trend_ids || []).map(String))].sort(),
        // Đổi ảnh mẫu ⇒ brief cũ không còn đúng ⇒ phải tạo lại (nếu không sẽ hiện "brief đã sẵn sàng" sai).
        reference_images: [...(this.briefReferenceImages || [])].map(String).sort(),
        // Đổi BẢNG SIZE cũng làm brief cũ sai: cơ cấu SKU và phân bổ size tính từ đây, và khoá bộ đệm của
        // MÁY CHỦ có phần này. Thiếu ở client thì đổi preset xong giao diện vẫn nói "brief khớp".
        size_distribution: Object.entries(payload.size_distribution || {})
          .map(([size, count]) => size + ':' + count)
          .sort(),
        // [2026-09-25] BA lựa chọn mới của người dùng cũng làm brief cũ sai — và chúng nằm trong khoá
        // bộ đệm của MÁY CHỦ. Thiếu ở đây thì sửa bảng mood xong giao diện vẫn nói "brief khớp" trong
        // khi phần chữ của brief đang mô tả bảng mood cũ.
        sku_total: Number(payload.sku_total) || 0,
        // BẢNG CƠ CẤU nhóm hàng người dùng tự đặt: nhóm nào bao nhiêu mã là thứ đi vào lệnh cắt, nên
        // đổi nó là brief cũ sai y như đổi bảng size. Giữ ĐÚNG THỨ TỰ người dùng đặt (máy chủ cũng giữ
        // thứ tự đó) — sắp xếp lại ở đây là tự tay xoá mất một thay đổi có thật.
        structure: (payload.structure || [])
          .map((row) => String((row && row.category) || '').trim() + ':' + (Number(row && row.count) || 0)),

        palette: (payload.palette || []).map((row) => String(row && row.hex || '')).join(','),
        moodboard: (payload.moodboard || [])
          .map((row) => String((row && row.label) || '') + '~' + String((row && row.caption) || ''))
          .join('|'),
      };
    },
    /** Brief hiện tại đã cũ so với prompt/trend người dùng đang chọn? */
    collectionBriefStale(payload = {}) {
      if (!this.collectionBrief || !this.collectionBriefInput) return false;
      return JSON.stringify(this.designBriefInput(payload)) !== JSON.stringify(this.collectionBriefInput);
    },
    /**
     * Tạo brief bộ sưu tập. `opts.force = true` khi người dùng bấm "Tạo lại" — bỏ qua bộ đệm máy chủ.
     *
     * Mặc định KHÔNG force: cùng đầu vào + cùng DNA + cùng model thì máy chủ trả lại kết quả đã đệm
     * (lần chạy thật mất ~28 giây và tốn token — xem DesignAgentService::BRIEF_CACHE_VERSION).
     */
    async createCollectionBrief(payload, opts = {}) {
      this.collectionBriefLoading = true;
      this.collectionBriefError = '';
      try {
        const data = await this.api('/api/design-agent/collection', {
          ...(payload || {}),
          // `opts.ai` cho phép ÉP chạy tất định cho một lượt cụ thể — dùng khi người dùng vừa sửa bảng
          // mood/bảng size và chỉ cần phần chữ bám theo, không cần trả thêm ~28 giây và token cho AI.
          // Không có cờ này thì cách duy nhất để "áp dụng" là gọi AI, và người dùng sẽ ngại sửa.
          ai: opts.ai === undefined ? this.designAgentAi : !!opts.ai,
          force: !!opts.force,
          // Ảnh mẫu đã chọn ở bước Định hướng — vai ĐỌC ẢNH dùng chúng để bám phong cách thật của shop.
          reference_images: (this.briefReferenceImages || []).slice(0, 3),
        });
        // `keepText` = lượt CẬP NHẬT SỐ LIỆU (chạy tất định): giữ phần chữ của bản trước, chỉ lấy số mới.
        this.collectionBrief = opts.keepText ? keepAiText(data, this.collectionBrief) : (data || null);
        this.collectionBriefInput = this.designBriefInput(payload);
        this.plan = null;          // cấu trúc/SKU đổi ⇒ kế hoạch cũ không còn đúng
        this.planError = '';
        this.shopDataDirty = false;
        // Nói THẬT vì sao nhanh: bản lấy từ bộ đệm thì người dùng biết mà bấm "Tạo lại" nếu muốn bản mới.
        const cached = !!(data && data.model && data.model.cached);
        this.toast(cached
          ? 'Brief lấy từ bộ đệm (cùng đầu vào) — bấm "Tạo lại" nếu muốn chạy model mới.'
          : 'CollectionBot đã xây dựng brief bộ sưu tập.');
        return data;
      } catch (error) {
        this.collectionBriefError = userFacingError(error, 'Không tạo được brief bộ sưu tập.');
        // KHÔNG xoá bản brief đang có. Lượt cập nhật hỏng (mạng, máy chủ) mà xoá kết quả cũ thì người
        // dùng mất luôn thứ đang xem và phải chạy lại từ đầu — lỗi đã được báo bằng toast + dòng lỗi,
        // thêm một hình phạt nữa không giúp ai. Không có bản cũ thì vốn đã là null.
        this.toast(this.collectionBriefError, 'error');
        throw error;
      } finally {
        this.collectionBriefLoading = false;
      }
    },
    /**
     * Đưa prompt + gợi ý cấu hình Canvas (tỉ lệ, số biến thể, negative prompt) vào ô Tạo Ảnh.
     * Chỉ áp các giá trị hợp lệ; KHÔNG tự đổi resolution để tránh tăng chi phí ngoài ý muốn.
     */
    applyAgentPrompt(prompt, settings = {}) {
      const value = String(prompt || '').trim();
      if (!value) {
        this.toast('Chưa có prompt để áp dụng vào Canvas.', 'error');
        return false;
      }
      const applied = [];
      this.imagePromptEn = value;
      if (/^\d{1,2}:\d{1,2}$/.test(String(settings.ratio || ''))) {
        this.imageRatio = settings.ratio;
        applied.push('tỉ lệ ' + settings.ratio);
      }
      const variants = Number(settings.variant_count) || 0;
      if (variants > 0) {
        this.variantCount = Math.max(1, Math.min(4, variants));
        applied.push(this.variantCount + ' biến thể');
      }
      const negative = String(settings.negative_prompt || '').trim();
      // Chỉ điền negative prompt gợi ý khi người dùng CHƯA có cấu hình riêng — không ghi đè lựa chọn cũ.
      if (negative && !String(this.negativePromptEn || '').trim()) {
        this.negativePromptEn = negative;
        this.promptUseNegative = true;
        applied.push('negative prompt');
      }
      this.promptOpen = true;
      this.designAgentOpen = false;
      this.toast('Đã áp dụng prompt vào ô Tạo Ảnh'
        + (applied.length ? ' · ' + applied.join(' · ') : '') + '.');
      return true;
    },
    async createCollectionFromBrief(payload) {
      const data = await this.createProject(payload || {});
      if (data) this.designAgentOpen = false;
      return data;
    },
};
