<script setup>
/**
 * BƯỚC 1 — DNA SHOP, chia thành 3 VIỆC CON (2026-09-25; thêm việc 3 ngày 2026-09-26).
 *
 * Hồ sơ có 8 ô; trên điện thoại, một màn tám ô nhập liệu là quá dài để biết mình còn phải điền gì. Nay
 * tách theo đúng cách chủ shop nghĩ: "tôi bán cho ai" → "tôi làm ra cái gì" → "khi nào thì làm thế nào".
 * Hàng nút Lưu nằm NGOÀI các việc con nên lúc nào cũng bấm được — không phải quay lại việc 1 mới lưu được.
 *
 * VIỆC 3 là TRÍ NHỚ THỦ TỤC (GĐ2): DNA ở hai việc đầu là SỞ THÍCH PHẲNG (phong cách, màu, chất liệu, thứ
 * không làm). Nó KHÔNG diễn đạt được QUAN HỆ ĐIỀU KIỆN — câu *"khi làm đồ công sở thì ưu tiên màu trung
 * tính"* chỉ có nghĩa khi biết "khi nào". Việc 3 cho chủ shop nói thẳng các quy tắc đó, và chúng đi vào
 * chỉ dẫn của agent như CHỈ THỊ chứ không phải gợi ý.
 *
 * Mỗi việc con có HÀNG NÚT LƯU RIÊNG: DNA lưu vào /api/brand-dna, quy tắc lưu vào /api/brand-rules —
 * hai hồ sơ độc lập, gộp nút là lưu cái này ghi đè cái kia.
 */
import { inject, ref } from 'vue';
import { useStudioStore } from '../../store.js';
import StudioIcon from '../StudioIcon.vue';
import AgentSubSteps from './AgentSubSteps.vue';
import BrandMemoryPanel from '../BrandMemoryPanel.vue';
import DesignSearchPanel from '../DesignSearchPanel.vue';

const store = useStudioStore();
const sub = inject('sub');
const DNA_LISTS = inject('DNA_LISTS');
const dna = inject('dna');
const dnaDraft = inject('dnaDraft');
const dnaDirty = inject('dnaDirty');
/** Khối "Tìm thiết kế cũ" (việc #9) — mở theo yêu cầu, không gọi mạng lúc mở trang. */
const searchOpen = ref(false);

const dnaBlockReason = inject('dnaBlockReason');
const dnaEmpty = inject('dnaEmpty');
const dnaListMax = inject('dnaListMax');
const dnaListText = inject('dnaListText');
const setDnaText = inject('setDnaText');
const setDnaList = inject('setDnaList');

// ── Việc 3: QUY TẮC LÀM VIỆC (trí nhớ thủ tục) ────────────────────────────────────────────────
const brandRules = inject('brandRules');
const rulesDraft = inject('rulesDraft');
const rulesLimits = inject('rulesLimits');
const rulesDirty = inject('rulesDirty');
const rulesBlockReason = inject('rulesBlockReason');
const rulesEmpty = inject('rulesEmpty');
const RULES_MAX = inject('RULES_MAX');
const RULES_TRIGGER_MAX = inject('RULES_TRIGGER_MAX');
const RULES_ACTION_MAX = inject('RULES_ACTION_MAX');
const addRule = inject('addRule');
const removeRule = inject('removeRule');
const setRuleField = inject('setRuleField');

/** Tiêu đề từng việc con — ba việc, ba câu hỏi; giao diện không tự chế chữ ở nơi khác. */
const SUB_TITLE = {
  positioning: 'Bạn bán cho ai',
  style: 'Bạn làm ra cái gì',
  rules: 'Khi nào thì làm thế nào',
};
</script>

<template>
  <section id="agent-step-dna" role="region" aria-label="DNA shop" :aria-busy="sub === 'rules' ? store.brandRulesLoading : store.brandDnaLoading">
    <AgentSubSteps step-id="dna" />

    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(320px,400px)]">
      <div class="space-y-3">
        <div class="card p-3 sm:p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-display text-base font-semibold text-brand-300">DNA shop của bạn</h2>
            <div v-if="dna" class="flex items-center gap-1.5 text-tiny">
              <span class="rounded-full px-2 py-0.5 font-semibold" :class="dna.is_set ? 'bg-brand-500/20 text-brand-200' : 'bg-warn/15 text-warn'">
                {{ dna.is_set ? 'Đã khai' : 'Chưa khai' }}
              </span>
              <span v-if="dna?.updated_at" class="text-cream-400">{{ new Date(dna.updated_at).toLocaleDateString('vi-VN') }}</span>
            </div>
          </div>
          <p class="mt-1 text-body leading-5 text-cream-400">
            Phần <b class="text-cream-100">bạn tự khai</b> — agent dùng nó để viết brief, chọn nhóm hàng và loại bỏ những thứ bạn không làm.
            <details class="mt-1.5 inline-block">
              <summary class="cursor-pointer text-label text-brand-300 underline decoration-dotted">Điền thế nào?</summary>
              <p class="mt-1 max-w-md rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">
                <StudioIcon name="info" size="h-3.5 w-3.5" class="mr-1 inline-block align-[-2px]" />
                Điền càng cụ thể, brief càng sát shop. Bỏ trống cũng chạy được — AI dựa vào dự án và ảnh bạn đã làm.
              </p>
            </details>
          </p>
          <div v-if="sub === 'style' || sub === 'rules'" class="mt-2.5">
            <details>
              <summary class="cursor-pointer text-label font-semibold text-cream-300">Nguồn dữ liệu agent đang có</summary>
              <ul class="mt-1.5 space-y-1 text-label leading-5 text-cream-300">
                <li>· <b class="text-cream-100">DNA bạn khai</b> (màn hình này) — đáng tin nhất, sửa được bất cứ lúc nào.</li>
                <li>· <b class="text-cream-100">Quy tắc làm việc bạn đặt</b> (màn hình này) — agent coi là chỉ thị, không phải gợi ý.</li>
                <li>· <b class="text-cream-100">Gu đã học từ ảnh bạn duyệt/loại</b> — tự động, không cần nhập.</li>
                <li>· <b class="text-cream-100">Số bán của shop</b> (nhập ở bước Định hướng) — dữ liệu thật do bạn nhập.</li>
                <li>· <b class="text-cream-100">Dự án &amp; ảnh đã tạo</b> của chính tài khoản — dấu vết công việc.</li>
                <li>· <b class="text-cream-100">Danh mục xu hướng mẫu</b> — vẫn là dữ liệu MẪU, chưa nối sàn TMĐT.</li>
              </ul>
            </details>
          </div>

          <!-- TRÍ NHỚ ĐÃ HỌC: bài học agent tự rút từ ảnh bạn duyệt/loại. Đặt ngay cạnh phần khai báo
               để người dùng thấy được CẢ HAI loại trí nhớ: loại mình viết (DNA · quy tắc) và loại
               agent tự học. -->
          <BrandMemoryPanel />

          <!-- TÌM THIẾT KẾ CŨ (việc #9): kho tài liệu của chính tài khoản — dùng ngay lúc viết brief, khi câu
               hỏi "mùa trước mình làm gì rồi" có ích nhất. -->
          <button type="button" class="tool-btn btn-sm mt-2" :aria-expanded="searchOpen" @click="searchOpen = !searchOpen">
            <StudioIcon name="search" size="h-3.5 w-3.5" /> {{ searchOpen ? 'Đóng tìm kiếm' : 'Tìm thiết kế cũ' }}
          </button>
          <div v-if="searchOpen" class="mt-2 rounded-lg border border-ink-700 bg-ink-900/40">
            <DesignSearchPanel />
          </div>
        </div>
      </div>

      <div class="card p-3 sm:p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-display text-base font-semibold text-brand-300">{{ SUB_TITLE[sub] || 'DNA shop' }}</h3>
          <span v-if="(sub === 'rules' ? rulesDirty : dnaDirty)" class="flex items-center gap-1 text-tiny text-warn"><span class="h-1.5 w-1.5 rounded-full bg-warn"></span>Chưa lưu</span>
        </div>

        <!-- ══ VIỆC 3: QUY TẮC LÀM VIỆC ══ -->
        <template v-if="sub === 'rules'">
          <p class="mt-1 text-label leading-5 text-cream-400">
            Mỗi quy tắc gồm <b class="text-cream-100">khi nào</b> và <b class="text-cream-100">làm thế nào</b>. Khi brief rơi vào đúng tình huống đó, agent phải theo đúng cách bạn viết.
          </p>

          <div v-if="store.brandRulesLoading" class="mt-3 text-body text-cream-400">Đang tải quy tắc…</div>
          <p v-else-if="store.brandRulesError" role="alert" class="mt-3 rounded-lg border border-danger/40 bg-danger/15 px-3 py-2 text-body text-danger">{{ store.brandRulesError }}</p>

          <div v-else class="mt-3 grid gap-3">
            <p v-if="rulesEmpty && !rulesDraft.length" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">
              Chưa có quy tắc nào. Bỏ trống vẫn chạy — nhưng đây là chỗ duy nhất nói được “gặp tình huống này thì làm thế kia”.
            </p>

            <div v-for="(row, index) in rulesDraft" :key="row.id || 'new-' + index" class="rounded-lg border border-ink-700 bg-ink-900/60 p-2.5">
              <div class="flex items-center justify-between gap-2">
                <span class="text-tiny font-semibold text-cream-400">Quy tắc {{ index + 1 }}</span>
                <div class="flex items-center gap-1">
                  <span class="rounded-full px-2 py-0.5 text-tiny" :class="row.is_active === false ? 'bg-ink-700 text-cream-400' : 'bg-ok/15 text-ok'">
                    {{ row.is_active === false ? 'Đang tắt' : 'Đang bật' }}
                  </span>
                  <button type="button" class="icon-btn h-7 w-7" :title="row.is_active === false ? 'Bật quy tắc này' : 'Tắt tạm (giữ chữ đã viết)'" :aria-label="row.is_active === false ? 'Bật quy tắc' : 'Tắt quy tắc'" @click="setRuleField(index, 'is_active', row.is_active === false)">
                    <StudioIcon :name="row.is_active === false ? 'x' : 'check'" size="h-3.5 w-3.5" />
                  </button>
                  <button type="button" class="icon-btn h-7 w-7" title="Xoá quy tắc" aria-label="Xoá quy tắc" @click="removeRule(index)">
                    <StudioIcon name="trash" size="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>

              <div class="mt-2 grid gap-2">
                <div>
                  <label class="label" :for="'rule-trigger-' + index">Khi nào</label>
                  <input :id="'rule-trigger-' + index" class="input w-full" :maxlength="RULES_TRIGGER_MAX" :value="row.trigger || ''" placeholder="VD: làm đồ công sở" @input="setRuleField(index, 'trigger', $event.target.value)">
                </div>
                <div>
                  <label class="label" :for="'rule-action-' + index">Thì làm thế nào</label>
                  <input :id="'rule-action-' + index" class="input w-full" :maxlength="RULES_ACTION_MAX" :value="row.action || ''" placeholder="VD: ưu tiên màu trung tính, chất liệu ít nhăn" @input="setRuleField(index, 'action', $event.target.value)">
                </div>
                <div>
                  <label class="label" :for="'rule-weight-' + index">
                    Mức ưu tiên
                    <span class="font-normal normal-case tracking-normal text-cream-400">(1–{{ rulesLimits.weight?.max || 10 }} — cao hơn thì agent ưu tiên hơn khi hai quy tắc xung đột)</span>
                  </label>
                  <input :id="'rule-weight-' + index" class="input w-24" type="number" :min="rulesLimits.weight?.min || 1" :max="rulesLimits.weight?.max || 10" :value="row.weight || rulesLimits.weight?.default || 5" @input="setRuleField(index, 'weight', Number($event.target.value))">
                </div>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <button type="button" class="btn-ghost btn-sm" :disabled="rulesDraft.length >= RULES_MAX" @click="addRule">
                <StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm quy tắc
              </button>
              <span class="text-label text-cream-400">{{ rulesDraft.length }}/{{ RULES_MAX }}</span>
              <span v-if="rulesDraft.length >= RULES_MAX" class="text-label text-cream-400">↳ Đã đủ trần — xoá bớt một quy tắc để thêm.</span>
            </div>

            <!-- Hàng nút LƯU riêng của quy tắc -->
            <div class="flex flex-wrap items-center gap-2 border-t border-ink-700 pt-3">
              <button type="button" class="btn-brand btn-sm" :disabled="store.brandRulesSaving || !!rulesBlockReason" @click="store.saveBrandRules()">
                <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ store.brandRulesSaving ? 'Đang lưu…' : 'Lưu quy tắc' }}
              </button>
              <span v-if="rulesBlockReason" class="text-label text-cream-400">↳ {{ rulesBlockReason }}</span>
              <button type="button" class="btn-ghost btn-sm" :disabled="store.brandRulesSaving || !rulesDirty" @click="store.discardBrandRulesDraft()">Bỏ thay đổi</button>
              <button type="button" class="btn-ghost btn-sm" :disabled="store.brandRulesSaving || rulesEmpty" @click="store.resetBrandRules()">Xoá hết quy tắc</button>
              <span v-if="rulesEmpty && !store.brandRulesSaving" class="text-label text-cream-400">↳ Chưa có quy tắc nào để xoá.</span>
            </div>
          </div>
        </template>

        <!-- ══ VIỆC 1 & 2: DNA ══ -->
        <template v-else>
          <div v-if="store.brandDnaLoading" class="mt-3 text-body text-cream-400">Đang tải hồ sơ…</div>
          <p v-else-if="store.brandDnaError" role="alert" class="mt-3 rounded-lg border border-danger/40 bg-danger/15 px-3 py-2 text-body text-danger">{{ store.brandDnaError }}</p>

          <div v-else class="mt-3 grid gap-3.5">
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

            <!-- Hàng nút LƯU dùng chung cho cả hai việc con DNA -->
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
        </template>
      </div>
    </div>
  </section>
</template>
