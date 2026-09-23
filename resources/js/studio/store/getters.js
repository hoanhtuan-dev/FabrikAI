// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — khối getters của studio store.

export const studioGetters = {
    /** Tên gói đang dùng (hiển thị cạnh số credit). */
    planName() { return (this.planStatus && this.planStatus.plan) ? this.planStatus.plan.name : ''; },
    /** Chi phí credit theo GÓI (server trả) — fallback về giá trị mặc định đã nạp. */
    planCostImage() { return (this.planStatus && this.planStatus.costs && this.planStatus.costs.image) || this.imageCreditCost || 1; },

    /**
     * GIÁ CỦA MỘT LƯỢT CỤ THỂ theo model + cỡ + tỉ lệ — TRA bảng máy chủ gửi xuống.
     *
     * VÌ SAO CẦN: "1 ảnh = 1 credit" không còn đúng. Tạo ảnh nhanh 1K tốn 1 credit, nhưng sửa ảnh
     * có mask ở 2K tốn tới 6–10 credit (fal tính megapixel LÀM TRÒN LÊN, và ảnh 1:1 tốn gấp đôi
     * ảnh 4:5). Khách bấm mà không thấy giá là bị trừ bất ngờ — đúng thứ nguyên tắc 3 cấm.
     *
     * Thứ tự tra PHẢI khớp ProviderCostService::creditsFor():
     *   (cỡ, tỉ lệ) → (cỡ, mọi tỉ lệ) → (mọi cỡ, mọi tỉ lệ) → giá của gói.
     *
     * @returns {number} số credit của lượt này (luôn ≥ 1)
     */
    costFor() {
      return (provider, model, resolution, ratio) => {
        const rows = this.modelCreditCosts || [];
        const p = String(provider || '').toLowerCase().trim();
        const m = String(model || '').trim();
        if (!p || !m || !rows.length) return this.planCostImage;

        const r = String(resolution || '').toUpperCase().trim();
        const t = String(ratio || '').trim();
        const find = (rr, tt) => rows.find((x) => x.p === p && x.m === m && x.r === rr && x.t === tt);
        const hit = find(r, t) || find(r, '') || find('', '');

        return hit && hit.c > 0 ? Number(hit.c) : this.planCostImage;
      };
    },
    planCostVideo() { return (this.planStatus && this.planStatus.costs && this.planStatus.costs.video) || 10; },
    /** Sắp cạn credit: còn ít hơn 3 thao tác ảnh ⇒ tô đậm nút để khách biết trước. */
    creditsLow() { return this.creditsLeft < this.planCostImage * 3; },
    upscaleSrc() { if (this.activeLayerId) { const l = this.canvasLayers.find(x => x.id === this.activeLayerId && x.visible !== false); if (l && l.image) return l.image; } return (this.editSource && this.editSource.url) || (this.preview && this.preview.media_url) || ''; },
    upscaleName() { if (this.activeLayerId) { const l = this.canvasLayers.find(x => x.id === this.activeLayerId); if (l) return l.name; } return (this.editSource && this.editSource.name) || (this.preview ? 'Ảnh kết quả #' + this.preview.id : 'Ảnh đang chọn'); },

    activeBatch() { return this.generations.filter(g => this.lastBatch.includes(g.id)); },
    // Nguồn ảnh cho GalleryModal: viewerList (context riêng, vd outputs của dự án) →
    // thư viện (nếu đang ở /api/library) → outputs Studio.
    viewerItems() {
      if (this.viewerList && this.viewerList.length) return this.viewerList.filter(g => g.media_url);
      return (this.libraryItems && this.libraryItems.length) ? this.libraryItems.filter(g => g.media_url) : this.generations.filter(g => g.media_url);
    },
    // Outputs hiển thị ở Studio: lọc theo dự án đang áp dụng khi bật toggle.
    visibleGenerations() {
      const pid = this.appliedProjectId();
      if (this.outputFilterProject && pid) return this.generations.filter(g => Number(g.project_id) === Number(pid));
      return this.generations;
    },
    activeLayer() { return this.canvasLayers.find(x => x.id === this.activeLayerId) || null; },
    // ── Chọn nhiều layer (shift+click) ──
    selectedIds() { const a = [this.activeLayerId, ...this.selectedLayerIds].filter(Boolean); return [...new Set(a)]; },
    selection() { return this.canvasLayers.filter(l => this.selectedIds.includes(l.id) && l.visible !== false); },
    selectionCount() { return this.selection.length; },
    selectionUnitCount() { return this._selectionUnits().length; },
    // Nhãn các ĐỐI TƯỢNG đang chọn (group = 1 đối tượng) — popup xác nhận xóa đọc từ đây để nói
    // RÕ sắp xóa cái gì, thay vì chỉ hỏi "xóa 3 đối tượng?" mà người dùng không biết là cái nào.
    selectionUnitLabels() {
      return this._selectionUnits().map((u) => {
        if (u.type === 'group') return 'Nhóm ' + (this.groupLabel(u.layers[0]) || 'không tên');
        const l = u.layers[0] || {};
        return l.name || 'Layer';
      });
    },
    // Số đối tượng đang chọn bị KHÓA — hiện trước khi bấm xóa để không "xóa mà im lặng bỏ qua".
    lockedSelectionCount() { return this._selectionUnits().filter((u) => u.layers.some((l) => l.locked)).length; },
    /**
     * Ảnh của layer ĐANG CHỌN đã có trong Output chưa?
     *
     * Vì sao cần: nút "Lưu Output" phải SÁNG khi có ảnh MỚI và phải im khi ảnh đã có — nếu không,
     * người dùng bấm Lưu nhiều lần cho cùng một ảnh và Outputs đầy bản trùng. Ba cách nhận biết, theo
     * đúng thứ tự danh tính của ảnh:
     *   1. Đã lưu trong phiên này (l.savedOutputId) → chắc chắn trùng, không cần đoán;
     *   2. Layer vốn là một ảnh KẾT QUẢ (l.genId) → trùng khi generation đó còn trong danh sách;
     *   3. Ảnh đã nằm trên máy chủ (/storage/…) → so ĐƯỜNG DẪN với media_url của các kết quả.
     * Layer ghép cục bộ (data URL) chưa từng lưu thì không thể trùng ⇒ trả false.
     */
    activeLayerInOutputs() {
      const l = this.activeLayer;
      if (!l || !l.image) return false;
      if (l.savedOutputId && this.generations.some((g) => Number(g.id) === Number(l.savedOutputId))) return true;
      if (l.genId && this.generations.some((g) => Number(g.id) === Number(l.genId))) return true;
      const img = String(l.image);
      const isServerUrl = img.startsWith('/storage/') || (typeof location !== 'undefined' && img.startsWith(location.origin + '/storage/'));
      if (!isServerUrl) return false;
      const rel = typeof location !== 'undefined' ? img.replace(location.origin, '') : img;
      return this.generations.some((g) => g.media_url && String(g.media_url).replace(typeof location !== 'undefined' ? location.origin : '', '') === rel);
    },
    // Có ảnh MỚI để lưu vào Output không (nút "Lưu Output" chỉ sáng khi true).
    canSaveActiveLayerToOutput() { return !!(this.activeLayer && this.activeLayer.image) && !this.activeLayerInOutputs; },
    visibleLayers() { return this.canvasLayers.filter(l => l.visible !== false); },
    // Danh sách layer hiển thị front-first (layer TRƯỚC NHẤT ở trên cùng) — chuẩn trình chỉnh
    // ảnh. canvasLayers giữ thứ tự vẽ (zIndex), getter này chỉ đảo để hiển thị panel.
    layersFrontFirst() { return this.canvasLayers.slice().reverse(); },
    // Kích thước LAYOUT (CSS px, trước transform) của <img> layer đang active — khớp thẻ hiển thị
    // (cạnh dài tối đa 512). Dùng làm khung toạ độ cho overlay preview neo vào layer.
    frameLayout() {
      const l = this.activeLayer;
      let w = l ? Number(l.baseW) : 0, h = l ? Number(l.baseH) : 0;
      if ((!w || w < 1 || !h || h < 1) && this.cvImg) {
        const nw = this.cvImg.naturalWidth || 0, nh = this.cvImg.naturalHeight || 0;
        if (nw > 1 && nh > 1) { const cap = Math.min(1, 512 / nw, 512 / nh); w = nw * cap; h = nh * cap; }
      }
      return (w > 0 && h > 0) ? { w, h } : null;
    },
  };
