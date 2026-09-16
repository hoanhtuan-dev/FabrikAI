import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import persist from '@alpinejs/persist';
import focus from '@alpinejs/focus';
import intersect from '@alpinejs/intersect';

Alpine.plugin(collapse);
Alpine.plugin(persist);
Alpine.plugin(focus);
Alpine.plugin(intersect);

window.Alpine = Alpine;

// ---------- Helpers ----------
function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

async function apiFetch(url, options = {}) {
    const opts = { ...options, headers: { ...(options.headers || {}) } };
    opts.headers['Accept'] = 'application/json';
    const csrf = csrfToken();
    if (csrf) opts.headers['X-CSRF-TOKEN'] = csrf;

    if (opts.body && typeof opts.body !== 'string') {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(opts.body);
    }

    const res = await fetch(url, opts);
    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        const msg = data.message || data.error || 'Đã có lỗi xảy ra.';
        const err = new Error(msg);
        err.status = res.status;
        err.data = data;
        throw err;
    }
    return data;
}

function formatMoney(value) {
    const n = Number(value || 0);
    return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(n) + '₫';
}

window.formatMoney = formatMoney;

Alpine.magic('money', () => (value) => formatMoney(value));

// ---------- Toast store ----------
Alpine.store('toast', {
    items: [],
    show(message, type = 'success') {
        const id = Date.now() + Math.random();
        this.items.push({ id, message, type });
        setTimeout(() => this.remove(id), 3500);
    },
    remove(id) {
        this.items = this.items.filter((t) => t.id !== id);
    },
});

// ---------- Cart store ----------
Alpine.store('cart', {
    open: false,
    loading: false,
    adding: false,
    initialized: false,
    items: [],
    coupon: null,
    subtotal: 0,
    discount: 0,
    shippingFee: 0,
    total: 0,
    shippingMethod: null,
    shippingMethods: [],

    get count() {
        return this.items.reduce((sum, i) => sum + Number(i.quantity || 0), 0);
    },

    get isEmpty() {
        return this.items.length === 0;
    },

    init() {
        this.fetch();
        window.addEventListener('cart:changed', () => this.fetch());
    },

    async fetch() {
        try {
            const data = await apiFetch('/api/cart');
            this.applyData(data);
            this.initialized = true;
        } catch (e) {
            console.error('cart fetch failed', e);
        }
    },

    applyData(data) {
        this.items = data.items || [];
        this.subtotal = Number(data.subtotal || 0);
        this.discount = Number(data.discount || 0);
        this.shippingFee = Number(data.shipping_fee || 0);
        this.total = Number(data.total || 0);
        this.coupon = data.coupon || null;
        this.shippingMethod = data.shipping_method || null;
        this.shippingMethods = data.shipping_methods || [];
        this.loading = false;
        this.adding = false;
    },

    async add(productId, variantId = null, quantity = 1) {
        this.adding = true;
        try {
            await apiFetch('/api/cart/add', {
                method: 'POST',
                body: { product_id: productId, variant_id: variantId, quantity },
            });
            await this.fetch();
            Alpine.store('toast').show('Đã thêm vào giỏ hàng');
            this.openDrawer();
        } catch (e) {
            Alpine.store('toast').show(e.message, 'error');
        } finally {
            this.adding = false;
        }
    },

    async updateQuantity(itemId, quantity) {
        if (quantity < 1) return this.remove(itemId);
        try {
            await apiFetch('/api/cart/update', { method: 'POST', body: { id: itemId, quantity } });
            await this.fetch();
        } catch (e) {
            Alpine.store('toast').show(e.message, 'error');
        }
    },

    async remove(itemId) {
        try {
            await apiFetch('/api/cart/remove', { method: 'POST', body: { id: itemId } });
            await this.fetch();
        } catch (e) {
            Alpine.store('toast').show(e.message, 'error');
        }
    },

    async clear() {
        try {
            await apiFetch('/api/cart/clear', { method: 'POST' });
            await this.fetch();
        } catch (e) {
            Alpine.store('toast').show(e.message, 'error');
        }
    },

    async applyCoupon(code) {
        try {
            await apiFetch('/api/coupon/apply', { method: 'POST', body: { code } });
            await this.fetch();
            Alpine.store('toast').show('Đã áp dụng mã giảm giá');
            return true;
        } catch (e) {
            Alpine.store('toast').show(e.message, 'error');
            return false;
        }
    },

    async removeCoupon() {
        try {
            await apiFetch('/api/coupon/remove', { method: 'DELETE' });
            await this.fetch();
        } catch (e) {
            Alpine.store('toast').show(e.message, 'error');
        }
    },

    async setShipping(code) {
        try {
            await apiFetch('/api/cart/shipping', { method: 'POST', body: { code } });
            await this.fetch();
        } catch (e) {
            Alpine.store('toast').show(e.message, 'error');
        }
    },

    openDrawer() { this.open = true; document.body.style.overflow = 'hidden'; },
    closeDrawer() { this.open = false; document.body.style.overflow = ''; },
});

// ---------- Components ----------

document.addEventListener('alpine:init', () => {
    // Product gallery with variant switching
    Alpine.data('productGallery', (options = {}) => ({
        images: options.images || [],
        active: 0,
        thumbnails: [],
        select(i) { this.active = i; },
        next() { this.active = (this.active + 1) % this.images.length; },
        prev() { this.active = (this.active - 1 + this.images.length) % this.images.length; },
    }));

    // Quantity stepper
    Alpine.data('qtyStepper', (min = 1, max = 9999, initial = 1) => ({
        min,
        max,
        value: initial,
        init() { if (this.value < min) this.value = min; },
        inc() { if (this.value < this.max) this.value = Number(this.value) + 1; },
        dec() { if (this.value > this.min) this.value -= 1; },
    }));

    // Modal (generic confirm/dialog)
    Alpine.data('modal', () => ({
        open: false,
        title: '',
        content: '',
        confirmText: 'Xác nhận',
        onConfirm: null,
        show(title, content, confirmText = 'Xác nhận', onConfirm = null) {
            this.title = title;
            this.content = content;
            this.confirmText = confirmText;
            this.onConfirm = onConfirm;
            this.open = true;
        },
        close() { this.open = false; },
        confirm() {
            if (this.onConfirm) this.onConfirm();
            this.open = false;
        },
    }));

    // Sticky navbar shadow on scroll
    Alpine.data('navbar', () => ({
        scrolled: false,
        mobileOpen: false,
        init() {
            this.onScroll = () => { this.scrolled = window.scrollY > 8; };
            window.addEventListener('scroll', this.onScroll, { passive: true });
            this.onScroll();

            // Close the mobile menu when clicking outside the header.
            this.onClick = (e) => {
                if (this.mobileOpen && this.$el && !this.$el.contains(e.target)) {
                    this.mobileOpen = false;
                }
            };
            document.addEventListener('click', this.onClick);
        },
        destroy() { window.removeEventListener('scroll', this.onScroll); document.removeEventListener('click', this.onClick); },
    }));

    // Product card "quick add"
    Alpine.data('productCard', () => ({
        fav: false,
        init() {
            const store = Alpine.store('wishlist');
            if (store) this.fav = store.has(this.$el.dataset.productId);
        },
        toggleFav(e) {
            e.preventDefault();
            Alpine.store('wishlist').toggle(this.$el.dataset.productId);
        },
    }));

    // Wishlist store (persisted)
    Alpine.store('wishlist', {
        ids: Alpine.$persist([]).as('trillfa_wishlist'),
        has(id) { return this.ids.includes(String(id)); },
        toggle(id) {
            id = String(id);
            if (this.has(id)) {
                this.ids = this.ids.filter((x) => x !== id);
                Alpine.store('toast').show('Đã xóa khỏi danh sách yêu thích');
            } else {
                this.ids.push(id);
                Alpine.store('toast').show('Đã thêm vào danh sách yêu thích');
            }
        },
    });

    // Quick view / added-to-cart feedback
    Alpine.data('searchBox', () => ({
        open: false,
        query: '',
        results: [],
        loading: false,
        timer: null,
        init() {
            document.addEventListener('click', (e) => {
                if (!this.$el.contains(e.target)) this.open = false;
            });
        },
        onInput() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.search(), 250);
        },
        async search() {
            if (this.query.trim().length < 2) { this.results = []; this.open = false; return; }
            this.loading = true;
            try {
                const data = await apiFetch('/api/search?q=' + encodeURIComponent(this.query.trim()));
                this.results = data.products || [];
                this.open = true;
            } catch (e) {
                this.results = [];
            } finally {
                this.loading = false;
            }
        },
        go() {
            if (this.query.trim()) window.location.href = '/shop?q=' + encodeURIComponent(this.query.trim());
        },
        clear() { this.query = ''; this.results = []; this.open = false; },
    }));

    // ---------- Admin helpers: image / gallery / rich editor ----------

    // Single image upload with live preview
    Alpine.data('imageUploader', ({ existing = null, inputName = 'image' } = {}) => ({
        existing,
        inputName,
        preview: null,
        fileName: '',
        get displaySrc() { return this.preview || this.existing; },
        get hasImage() { return !!this.displaySrc; },
        onChange(e) {
            const f = e.target.files && e.target.files[0];
            if (!f) { this.clearPreview(); return; }
            if (this.preview) URL.revokeObjectURL(this.preview);
            this.preview = URL.createObjectURL(f);
            this.fileName = f.name;
        },
        clearPreview() {
            if (this.preview) URL.revokeObjectURL(this.preview);
            this.preview = null;
            this.fileName = '';
            this.$refs.input.value = '';
        },
        remove() { this.clearPreview(); },
    }));

    // Multi-image gallery upload with preview + per-item remove
    Alpine.data('galleryUploader', ({ existing = [], inputName = 'gallery', removeName = 'gallery_remove' } = {}) => ({
        items: existing.map((it) => ({ ...it, isNew: false, removed: false })),
        inputName,
        removeName,
        onChange(e) {
            const files = Array.from(e.target.files || []);
            for (const f of files) {
                if (f.type && !f.type.startsWith('image/')) continue;
                const holder = document.createElement('input');
                holder.type = 'file';
                holder.name = this.inputName + '[]';
                holder.setAttribute('class', 'hidden');
                const dt = new DataTransfer();
                dt.items.add(f);
                holder.files = dt.files;
                this.$refs.newFiles.appendChild(holder);
                this.items.push({ path: null, url: URL.createObjectURL(f), isNew: true, holder, removed: false });
            }
            e.target.value = '';
        },
        remove(index) {
            const it = this.items[index];
            if (it.isNew) {
                if (it.holder) it.holder.remove();
                try { URL.revokeObjectURL(it.url); } catch (err) {}
            } else {
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = this.removeName + '[]';
                h.value = it.path;
                this.$refs.removed.appendChild(h);
            }
            this.items.splice(index, 1);
        },
    }));

    // Lightweight rich-text editor (contenteditable + execCommand)
    Alpine.data('richEditor', ({ toolbar = true } = {}) => ({
        toolbar,
        init() {
            if (this.$refs.hidden && this.$refs.hidden.value) {
                this.$refs.editor.innerHTML = this.$refs.hidden.value;
            }
        },
        exec(cmd, val = null) {
            this.$refs.editor.focus();
            document.execCommand(cmd, false, val);
            this.sync();
        },
        addLink() {
            const url = window.prompt('Nhập địa chỉ liên kết (URL):');
            if (url) this.exec('createLink', url);
        },
        pickImage() {
            if (!this.imageInput) {
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/*';
                input.className = 'hidden';
                input.addEventListener('change', (e) => this.uploadImage(e));
                this.$refs.editor.parentNode.appendChild(input);
                this.imageInput = input;
            }
            this.imageInput.click();
        },
        async uploadImage(e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            e.target.value = '';
            this.savingImage = true;
            try {
                const form = new FormData();
                form.append('image', file);
                const res = await fetch('/admin/upload-image', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken() },
                    body: form,
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || 'Không thể tải ảnh.');
                this.$refs.editor.focus();
                document.execCommand('insertImage', false, data.url);
                this.sync();
            } catch (err) {
                Alpine.store('toast').show(err.message, 'error');
            } finally {
                this.savingImage = false;
            }
        },
        sync() { this.$refs.hidden.value = this.$refs.editor.innerHTML; },
        applyStyle(style) {
            this.$refs.editor.focus();
            document.execCommand('styleWithCSS', false, style);
        },
    }));

    // "Mở đầu (giới thiệu ngắn)" rich editor + ĐỒNG BỘ với "Nội dung mở rộng (HTML)".
    // Giữ Mở đầu là đoạn <p> đầu tiên của Nội dung mở rộng:
    //  - gõ trong Mở đầu → tự cập nhật đoạn đầu của Nội dung mở rộng (nếu đang khớp);
    //  - nút "Đồng bộ vào" → luôn ghi Mở đầu thành đoạn đầu của Nội dung mở rộng;
    //  - nút "Lấy từ" → kéo đoạn đầu của Nội dung mở rộng vào Mở đầu.
    Alpine.data('introEditor', () => ({
        lastSynced: '',
        // 'empty' | 'synced' | 'pending' — chip trạng thái hiển thị dựa vào biến này
        status: 'empty',
        init() {
            if (this.$refs.hidden && this.$refs.hidden.value) {
                this.$refs.editor.innerHTML = this.$refs.hidden.value;
            }
            this.bodyEl = document.getElementById('about-body-editor');
            this.bodyHidden = document.getElementById('about-body-hidden');
            // Body editor init chạy SAU component này trong cùng lượt quét DOM,
            // nên đánh dấu trạng thái đồng bộ sau khi cả hai đã được khởi tạo.
            setTimeout(() => {
                this.lastSynced = this.firstParagraphText();
                this.updateStatus();
            }, 0);
        },
        updateStatus() {
            if (!this.bodyEl || (this.bodyEl.innerHTML || '').trim() === '') {
                this.status = 'empty';
            } else if (this.lastSynced !== '' && this.firstParagraphText() === this.lastSynced) {
                this.status = 'synced';
            } else {
                this.status = 'pending';
            }
        },
        exec(cmd, val = null) {
            this.$refs.editor.focus();
            document.execCommand(cmd, false, val);
            this.sync();
        },
        addLink() {
            const url = window.prompt('Nhập địa chỉ liên kết (URL):');
            if (url) this.exec('createLink', url);
        },
        plainText(html) {
            const d = document.createElement('div');
            d.innerHTML = html || '';
            return (d.textContent || '').trim();
        },
        firstParagraphText() {
            if (!this.bodyEl) return '';
            const m = (this.bodyEl.innerHTML || '').match(/<p[^>]*>([\s\S]*?)<\/p>/);
            return this.plainText(m ? m[1] : '');
        },
        sync() {
            const html = this.$refs.editor.innerHTML;
            this.$refs.hidden.value = html;
            if (this.bodyEl && this.bodyHidden) {
                const body = this.bodyEl.innerHTML || '';
                let next = null;
                if (body.trim() === '') {
                    next = '<p>' + html + '</p>';
                } else if (this.lastSynced !== '' && this.firstParagraphText() === this.lastSynced) {
                    next = body.replace(/<p[^>]*>[\s\S]*?<\/p>/, '<p>' + html + '</p>');
                }
                if (next !== null) {
                    this.bodyEl.innerHTML = next;
                    this.bodyHidden.value = next;
                }
                this.lastSynced = this.plainText(html);
            }
            this.updateStatus();
        },
        syncToBody() {
            const html = this.$refs.editor.innerHTML;
            if (!this.bodyEl || !this.bodyHidden) return;
            const body = (this.bodyEl.innerHTML || '').trim();
            let next = body;
            if (/^<p[^>]*>[\s\S]*?<\/p>/.test(next)) {
                next = next.replace(/^<p[^>]*>[\s\S]*?<\/p>/, '<p>' + html + '</p>');
            } else {
                next = '<p>' + html + '</p>' + (body ? body : '');
            }
            this.bodyEl.innerHTML = next;
            this.bodyHidden.value = next;
            this.lastSynced = this.plainText(html);
            this.updateStatus();
            this.notify('Đã đồng bộ “Mở đầu” vào đoạn đầu của “Nội dung mở rộng”.');
        },
        pullFromBody() {
            const first = this.firstParagraphText();
            if (first) {
                this.$refs.editor.innerHTML = first;
                this.$refs.hidden.value = first;
                this.lastSynced = first;
                this.updateStatus();
                this.notify('Đã kéo đoạn mở đầu từ “Nội dung mở rộng” vào “Mở đầu”.');
            }
        },
        notify(message) {
            try {
                Alpine.store('toast').show(message, 'success');
            } catch (e) {
                window.alert(message);
            }
        },
    }));
});

    // Product collection picker with category filter (landing pages)
    Alpine.data('productPicker', (products, selectedIds, categories) => ({
        products,
        categories,
        selected: (selectedIds || []).map(Number),
        filterCat: '',
        shown() {
            if (!this.filterCat) return this.products;
            return this.products.filter((p) => String(p.category_id) === String(this.filterCat));
        },
        toggle(id) {
            id = Number(id);
            const i = this.selected.indexOf(id);
            if (i >= 0) this.selected.splice(i, 1);
            else this.selected.push(id);
        },
    }));

// PWA / Service Worker đã LOẠI BỎ: /sw.js (scope "/") từng được legacy Alpine pages đăng ký và
// kiểm soát cả /studio → preload JS bị "cross-world service worker resource mismatch" + phục vụ
// HTML/asset cũ qua cache (network-first nhưng vẫn gây lệch bản). Studio & storefront (Vue) đều
// tự unregister SW dư; không còn nơi nào đăng ký lại /sw.js nữa.

Alpine.start();