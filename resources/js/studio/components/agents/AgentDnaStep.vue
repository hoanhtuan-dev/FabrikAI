<script setup>
/**
 * BƯỚC 1 — DNA SHOP, chia thành 2 VIỆC CON (2026-09-25).
 *
 * Hồ sơ có 8 ô; trên điện thoại, một màn tám ô nhập liệu là quá dài để biết mình còn phải điền gì. Nay
 * tách theo đúng cách chủ shop nghĩ: "tôi bán cho ai" trước, "tôi làm ra cái gì" sau. Hàng nút Lưu nằm
 * NGOÀI hai việc con nên lúc nào cũng bấm được — không phải quay lại việc 1 mới lưu được.
 */
import { inject } from 'vue';
import { useStudioStore } from '../../store.js';
import StudioIcon from '../StudioIcon.vue';
import AgentSubSteps from './AgentSubSteps.vue';

const store = useStudioStore();
const sub = inject('sub');
const DNA_LISTS = inject('DNA_LISTS');
const dna = inject('dna');
const dnaDraft = inject('dnaDraft');
const dnaDirty = inject('dnaDirty');
const dnaBlockReason = inject('dnaBlockReason');
const dnaEmpty = inject('dnaEmpty');
const dnaListMax = inject('dnaListMax');
const dnaListText = inject('dnaListText');
const setDnaText = inject('setDnaText');
const setDnaList = inject('setDnaList');
</script>

<template>
  <section id="agent-step-dna" role="region" aria-label="DNA shop" :aria-busy="store.brandDnaLoading">
    <AgentSubSteps step-id="dna" />

    <div class="grid gap-4 lg:grid-cols-[1fr_minmax(320px,420px)]">
      <div class="space-y-4">
        <div class="card p-4">
          <h2 class="font-display text-base font-semibold text-brand-300">DNA shop của bạn</h2>
          <p class="mt-1 text-body leading-5 text-cream-400">
            Đây là phần <b class="text-cream-100">bạn tự khai</b> — agent dùng nó để viết brief, chọn nhóm hàng và loại bỏ những thứ bạn không làm.
          </p>
          <p class="mt-2 rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">
            <StudioIcon name="info" size="h-3.5 w-3.5" class="mr-1 inline-block align-[-2px]" />
            Điền càng cụ thể, brief càng sát shop của bạn. Bỏ trống cũng chạy được — khi đó AI dựa vào dự án và ảnh bạn đã làm.
          </p>
          <div v-if="dna" class="mt-3 flex flex-wrap items-center gap-2 text-label">
            <span class="rounded-full px-2 py-0.5 font-semibold" :class="dna.is_set ? 'bg-brand-500/20 text-brand-200' : 'bg-warn/15 text-warn'">
              {{ dna.is_set ? 'Đã khai' : 'Chưa khai' }}
            </span>
            <span class="text-cream-400">Bản đang dùng khi chạy: <b class="text-cream-200">{{ dna.summary ? 'DNA bạn khai' : 'suy ra từ dữ liệu tài khoản' }}</b></span>
          </div>
          <p v-if="dna?.updated_at" class="mt-1 text-label text-cream-400">Cập nhật lần cuối: {{ new Date(dna.updated_at).toLocaleString('vi-VN') }}</p>
        </div>

        <div v-if="sub === 'style'" class="card p-4">
          <h3 class="font-display text-base font-semibold text-brand-300">Nguồn dữ liệu agent đang có</h3>
          <ul class="mt-2 space-y-1.5 text-label leading-5 text-cream-300">
            <li>· <b class="text-cream-100">DNA bạn khai</b> (màn hình này) — đáng tin nhất, sửa được bất cứ lúc nào.</li>
            <li>· <b class="text-cream-100">Số bán của shop</b> (nhập ở bước Định hướng) — dữ liệu thật do bạn nhập.</li>
            <li>· <b class="text-cream-100">Dự án &amp; ảnh đã tạo</b> của chính tài khoản — dấu vết công việc.</li>
            <li>· <b class="text-cream-100">Danh mục xu hướng mẫu</b> — vẫn là dữ liệu MẪU, chưa nối sàn TMĐT.</li>
          </ul>
        </div>
      </div>

      <div class="card p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-display text-base font-semibold text-brand-300">{{ sub === 'positioning' ? 'Bạn bán cho ai' : 'Bạn làm ra cái gì' }}</h3>
          <span v-if="dnaDirty" class="text-label text-warn">Có thay đổi chưa lưu</span>
        </div>

        <div v-if="store.brandDnaLoading" class="mt-3 text-body text-cream-400">Đang tải hồ sơ…</div>
        <p v-else-if="store.brandDnaError" role="alert" class="mt-3 rounded-lg border border-danger/40 bg-danger/15 px-3 py-2 text-body text-danger">{{ store.brandDnaError }}</p>

        <div v-else class="mt-3 grid gap-4">
          <!-- VIỆC 1 — ĐỊNH VỊ KHÁCH HÀNG -->
          <template v-if="sub === 'positioning'">
            <div>
              <label class="label" for="dna-positioning">Định vị (1 câu)</label>
              <input id="dna-positioning" class="input w-full" maxlength="200" :value="dnaDraft.positioning || ''" placeholder="VD: Thời trang nữ công sở tối giản, may tại xưởng nhà" @input="setDnaText('positioning', $event.target.value)">
              <p class="mt-1 text-label leading-4 text-cream-400">Câu này đi thẳng vào phần mở đầu của brief.</p>
            </div>
            <div>
              <label class="label" for="dna-customer">Khách hàng mục tiêu</label>
              <input id="dna-customer" class="input w-full" maxlength="200" :value="dnaDraft.customer || ''" placeholder="VD: nữ 25–35 tuổi, đi làm văn phòng, ngân sách 400–800k" @input="setDnaText('customer', $event.target.value)">
            </div>
            <div>
              <span class="label">Dải giá</span>
              <div class="mt-1 flex flex-wrap gap-1.5">
                <button v-for="band in (dna?.price_bands || [])" :key="band.id" type="button" class="tool-btn state-layer" :class="{ 'is-active': (dnaDraft.price_band || '') === band.id }" :aria-pressed="(dnaDraft.price_band || '') === band.id" @click="setDnaText('price_band', band.id)">{{ band.label }}</button>
              </div>
            </div>
          </template>

          <!-- VIỆC 2 — PHONG CÁCH & CHẤT LIỆU -->
          <template v-else>
            <div v-for="field in DNA_LISTS" :key="field.key">
              <label class="label" :for="'dna-' + field.key">{{ field.label }} <span class="font-normal normal-case tracking-normal text-cream-400">(cách nhau bằng dấu phẩy, tối đa {{ dnaListMax(field.key) }})</span></label>
              <input :id="'dna-' + field.key" class="input w-full" :value="dnaListText(field.key)" :placeholder="field.hint" @input="setDnaList(field.key, $event.target.value)">
            </div>
            <div>
              <label class="label" for="dna-notes">Ghi chú thêm</label>
              <textarea id="dna-notes" class="input w-full" rows="3" maxlength="500" :value="dnaDraft.notes || ''" placeholder="VD: chỉ dùng vải nội địa, không nhận đơn dưới 20 cái" @input="setDnaText('notes', $event.target.value)"></textarea>
            </div>
          </template>

          <!-- Hàng nút LƯU dùng chung cho cả hai việc con -->
          <div class="flex flex-wrap items-center gap-2 border-t border-ink-700 pt-3">
            <button type="button" class="btn-brand btn-sm" :disabled="store.brandDnaSaving || !!dnaBlockReason" @click="store.saveBrandDna()">
              <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ store.brandDnaSaving ? 'Đang lưu…' : 'Lưu DNA' }}
            </button>
            <span v-if="dnaBlockReason" class="text-label text-cream-400">↳ {{ dnaBlockReason }}</span>
            <button type="button" class="btn-ghost btn-sm" :disabled="store.brandDnaSaving || !dnaDirty" @click="store.discardBrandDnaDraft()">Bỏ thay đổi</button>
            <span v-if="!dnaDirty && !store.brandDnaSaving" class="text-label text-cream-400">↳ Không có thay đổi nào để bỏ.</span>
            <button type="button" class="btn-ghost btn-sm" :disabled="store.brandDnaSaving || dnaEmpty" @click="store.resetBrandDna()">Xoá hồ sơ</button>
            <span v-if="dnaEmpty && !store.brandDnaSaving" class="text-label text-cream-400">↳ Chưa có hồ sơ nào để xoá.</span>
          </div>
          <!-- Câu này chỉ đúng khi KHÔNG đang lưu: lúc đang lưu thì bản nháp còn khác bản đã lưu. -->
          <p v-if="!dnaBlockReason && !store.brandDnaSaving && dna?.is_set" class="text-label text-cream-400">Bản đang lưu trùng với bản bạn đang thấy.</p>
        </div>
      </div>
    </div>
  </section>
</template>
