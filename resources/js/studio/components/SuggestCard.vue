<script setup>
import { ref, computed, watch } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();
const lang = ref(store.suggestLang === 'vi' ? 'vi' : 'en');

const r = computed(() => store.suggestResult || {});
const palette = computed(() => (r.value.color_palette || []).filter(Boolean));
const keywords = computed(() => (r.value.keywords || []).filter(Boolean));
const adherenceLabel = computed(() => {
  const a = Number(r.value.adherence ?? 0);
  if (!a) return store.suggestAdherence ? adherenceText(Number(store.suggestAdherence)) : 'Tự động (theo sáng tạo)';
  return adherenceText(a);
});
function adherenceText(a) {
  if (a <= 3) return 'Thấp — sáng tạo lại';
  if (a <= 6) return 'Trung bình — tinh chỉnh tinh tế';
  return 'Cao — tái tạo chính xác gốc';
}

// ── Luồng "Tạo ảnh ngay": bấm → mở popup xác nhận → user bấm Tạo Ảnh → tiến trình đẹp mắt ──
// genFlow: 'idle' | 'armed' (chờ xác nhận popup) | 'generating' | 'done'
const genFlow = ref('idle');
// Bước stepper: [key, icon, label] — active khi tiến trình chạm tới bước đó.
const steps = [
  { key: 'suggest', icon: '💡', label: 'Đã gợi ý' },
  { key: 'confirm', icon: '✏️', label: 'Xác nhận' },
  { key: 'generate', icon: '🎨', label: 'Tạo ảnh' },
  { key: 'done', icon: '✅', label: 'Hoàn tất' },
];
// Bước đang active dựa trên genFlow + store.generateStage.
const activeStep = computed(() => {
  if (genFlow.value === 'armed') return 1; // đang chờ xác nhận popup
  if (genFlow.value === 'generating') {
    // generating chia 2 giai đoạn con theo store.generateStage
    return store.generateStage === 'done' ? 3 : 2;
  }
  if (genFlow.value === 'done') return 3;
  return 0;
});
// Phần trăm hiển thị — dùng progress thật từ store khi đang generate.
const flowPct = computed(() => {
  if (genFlow.value === 'idle') return 0;
  if (genFlow.value === 'armed') return 0; // chờ user xác nhận, chưa có tiến trình
  if (genFlow.value === 'generating') return store.generateProgress || 0;
  return 100;
});

const flowLabel = computed(() => {
  if (genFlow.value === 'armed') return 'Đang chờ xác nhận prompt ở popup…';
  if (genFlow.value === 'generating') {
    const s = store.generateStage;
    if (s === 'preparing') return 'Đang chuẩn bị…';
    if (s === 'enriching') return 'Đang làm giàu prompt…';
    if (s === 'rendering') return 'Đang tạo ảnh…';
    if (s === 'done') return 'Hoàn tất!';
    return 'Đang tạo ảnh…';
  }
  if (genFlow.value === 'done') return '✅ Đã tạo xong — xem kết quả ở Tạo Ảnh / Thư viện.';
  return '';
});

// Bấm nút → mở popup prompt để người dùng XÁC NHẬN / chỉnh sửa. Không tự tạo ngay.
// genFlow = 'armed' = đang chờ user bấm "Tạo Ảnh" trong popup. Khi popup đóng, huỷ armed.
async function generateNow() {
  if (genFlow.value !== 'idle' && genFlow.value !== 'done') return; // chống double-click
  const p = lang.value === 'vi' ? store.suggestResult?.prompt_vi : store.suggestResult?.image_prompt_en;
  const prompt = p || store.imagePromptEn;
  if (!prompt) { store.toast('Chưa có prompt — bấm "Gợi ý phong cách & prompt" hoặc nhập ở Tạo Ảnh.', 'error'); return; }
  store.imagePromptEn = prompt;     // điền prompt vào ô của popup
  store.promptOpen = true;           // mở popup xác nhận
  genFlow.value = 'armed';           // chờ xác nhận
}

// Khi popup đóng (user bấm Tạo Ảnh hoặc huỷ) → nếu vẫn armed mà chưa generate → huỷ.
watch(() => store.promptOpen, (open, wasOpen) => {
  if (!open && wasOpen && genFlow.value === 'armed' && !store.generating) {
    genFlow.value = 'idle';          // user đóng popup mà chưa tạo
  }
});

// Khi bắt đầu generate (user đã bấm Tạo Ảnh trong popup) → hiện tiến trình tại đây.
watch(() => store.generating, (g, old) => {
  if (g && !old && genFlow.value === 'armed') {
    genFlow.value = 'generating';    // chuyển sang tạo ảnh → hiện stepper
  }
  if (!g && old && genFlow.value === 'generating') {
    // generate xong → done, rồi reset.
    genFlow.value = store.generateStage === 'done' ? 'done' : 'idle';
    setTimeout(() => { if (genFlow.value === 'done') { genFlow.value = 'idle'; } }, 4000);
  }
});
</script>
<template>
  <div class="card p-5" style="background: linear-gradient(160deg, rgba(80,150,150,.13), rgba(74,122,144,.06));">
    <h2 class="flex items-center gap-2 font-display text-base font-semibold text-brand-300"><StudioIcon name="lightbulb" /> Gợi ý từ ảnh</h2>
    <div v-if="!store.suggestEnabled" class="mt-3 rounded-lg border border-red-500/40 bg-red-900/25 p-2.5 text-xs text-red-100">Tính năng đang tắt — bật lại trong <b>Cài đặt Studio → Gợi ý từ ảnh</b>.</div>
    <div v-if="store.upscaleSrc" class="mt-3 flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 p-2.5"><img :src="store.upscaleSrc" class="h-16 w-16 rounded-md bg-ink-900 object-cover"><span class="truncate text-xs text-cream-200">{{ store.upscaleName }}</span></div>
    <div v-else class="mt-3 text-xs text-cream-300/60">Chọn ảnh nguồn để gợi ý.</div>

    <!-- Điều khiển bám ảnh + mức chi tiết -->
    <div class="mt-3 space-y-2.5 rounded-lg border border-white/10 bg-white/5 p-2.5 text-[11px]">
      <div>
        <div class="mb-1 flex items-center justify-between text-cream-200">
          <span class="flex items-center gap-1 font-semibold"><StudioIcon name="target" size="h-3.5 w-3.5" /> Bám trang phục gốc</span>
          <span class="text-cream-300/70">{{ store.suggestAdherence === 0 ? 'Tự động' : store.suggestAdherence + '/10' }}</span>
        </div>
        <input type="range" min="0" max="10" step="1" v-model.number="store.suggestAdherence" class="w-full accent-brand-500" title="0 = tự theo mức sáng tạo; cao = tái tạo chính xác trang phục gốc (màu/đường may/hoạ tiết)" />
      </div>
      <div>
        <div class="mb-1 flex items-center justify-between text-cream-200">
          <span class="flex items-center gap-1 font-semibold"><StudioIcon name="search" size="h-3.5 w-3.5" /> Mức chi tiết phân tích</span>
          <span class="text-cream-300/70">{{ store.suggestDetailLevel }}/10</span>
        </div>
        <input type="range" min="1" max="10" step="1" v-model.number="store.suggestDetailLevel" class="w-full accent-brand-500" title="Cao = vision liệt kê đầy đủ màu/đường may/hoạ tiết/độ dài/cổ/tay" />
      </div>
    </div>

    <!-- Checkbox bỏ qua phân tích (mặc định bật = AI bỏ qua) -->
    <div class="mt-2 space-y-1.5 rounded-lg border border-white/10 bg-white/5 p-2.5 text-[11px]">
      <p class="mb-1 text-[10px] font-semibold text-cream-300/50">Bỏ qua phân tích</p>
      <label class="flex items-center gap-2 cursor-pointer text-cream-200 hover:text-cream-100">
        <input type="checkbox" v-model="store.suggestSkipLogo" class="h-3.5 w-3.5 accent-brand-500 rounded" />
        <span>Không phân tích logo, chữ, watermark</span>
      </label>
      <label class="flex items-center gap-2 cursor-pointer text-cream-200 hover:text-cream-100">
        <input type="checkbox" v-model="store.suggestSkipHair" class="h-3.5 w-3.5 accent-brand-500 rounded" />
        <span>Không phân tích kiểu tóc</span>
      </label>
      <label class="flex items-center gap-2 cursor-pointer text-cream-200 hover:text-cream-100">
        <input type="checkbox" v-model="store.suggestSkipBackground" class="h-3.5 w-3.5 accent-brand-500 rounded" />
        <span>Không phân tích bối cảnh</span>
      </label>
    </div>

    <button @click="store.suggestStyle(store.upscaleSrc)" :disabled="store.suggesting || !store.upscaleSrc" title="Phân tích ảnh và gợi ý phong cách, prompt" class="btn-brand mt-3 w-full">{{ store.suggesting ? 'Đang phân tích…' : 'Gợi ý phong cách & prompt' }}</button>

    <!-- 🚀 Tạo ảnh ngay — luôn hiện: điền prompt → mở popup xác nhận → bấm Tạo Ảnh → tiến trình tại đây -->
    <button @click="generateNow" :disabled="genFlow !== 'idle' && genFlow !== 'done'"
            title="Đưa prompt vào popup Tạo Ảnh để xác nhận / chỉnh sửa, rồi bấm Tạo Ảnh — tiến trình hiện tại đây"
            class="btn-genflow mt-1.5 w-full whitespace-nowrap">
      <span v-if="genFlow === 'idle' || genFlow === 'done'" class="flex items-center justify-center gap-1.5"><span>🚀</span> Tạo ảnh ngay</span>
      <span v-else-if="genFlow === 'armed'" class="flex items-center justify-center gap-1.5"><span>⏳</span> Chờ xác nhận ở popup…</span>
      <span v-else class="flex items-center justify-center gap-1.5"><span class="genflow-spinner"></span> {{ flowLabel }}</span>
    </button>

    <!-- Tiến trình đa giai đoạn (stepper + thanh gradient) — luôn hiện khi đang chạy -->
    <transition name="genfade">
      <div v-if="genFlow !== 'idle'" class="genflow-panel mt-2">
        <!-- Stepper 4 bước -->
        <div class="genflow-steps">
          <template v-for="(s, i) in steps" :key="s.key">
            <div class="genflow-step" :class="{ active: i <= activeStep, done: i < activeStep }">
              <span class="genflow-dot">
                <span v-if="i < activeStep" class="genflow-check">✓</span>
                <span v-else-if="i === activeStep && genFlow === 'generating'" class="genflow-pulse"></span>
                <span v-else>{{ s.icon }}</span>
              </span>
              <span class="genflow-step-label">{{ s.label }}</span>
            </div>
            <div v-if="i < steps.length - 1" class="genflow-connector" :class="{ filled: i < activeStep }"></div>
          </template>
        </div>
        <!-- Thanh gradient + % -->
        <div class="genflow-bar-wrap">
          <div class="genflow-bar" :style="{ width: flowPct + '%' }"></div>
        </div>
        <p class="genflow-pct">{{ Math.round(flowPct) }}% · {{ flowLabel }}</p>
      </div>
    </transition>

    <div v-if="store.suggestResult && (store.suggestResult.styles?.length || store.suggestResult.background || store.suggestResult.image_prompt_en)" class="relative mt-3 rounded-lg border border-emerald-500/40 bg-emerald-900/25 p-3 text-xs">
      <button @click="store.suggestResult = null; lang='en'" class="absolute right-2 top-2 grid h-6 w-6 place-items-center rounded-full bg-ink-700 text-cream-200 hover:bg-red-600" title="Xóa gợi ý"><StudioIcon name="x" size="h-3.5 w-3.5" /></button>

      <!-- Tóm tắt bám ảnh -->
      <div class="mb-2 flex flex-wrap items-center gap-1.5 text-[10px]">
        <span v-if="adherenceLabel" class="rounded-full bg-brand-600/30 px-2 py-0.5 font-semibold text-brand-200">🎯 Bám: {{ adherenceLabel }}</span>
        <span v-if="r.garment_type" class="rounded-full bg-ink-700/70 px-2 py-0.5 font-semibold text-cream-200">👕 {{ r.garment_type }}</span>
        <span v-if="r.embellishment" class="rounded-full bg-ink-700/70 px-2 py-0.5 font-semibold text-cream-200">✨ {{ r.embellishment }}</span>
      </div>

      <p v-if="store.suggestResult.styles?.length" class="mb-1"><span class="text-cream-300/60">Phong cách:</span> {{ store.suggestResult.styles.join(', ') }}</p>
      <p v-if="store.suggestResult.background" class="mb-1"><span class="text-cream-300/60">Bối cảnh:</span> {{ store.suggestResult.background }}</p>
      <p v-if="store.suggestResult.fabric" class="mb-1"><span class="text-cream-300/60">Chất liệu:</span> {{ store.suggestResult.fabric }}</p>
      <p v-if="store.suggestResult.silhouette" class="mb-1"><span class="text-cream-300/60">Dáng:</span> {{ store.suggestResult.silhouette }}</p>
      <p v-if="store.suggestResult.camera" class="mb-1"><span class="text-cream-300/60">Góc máy:</span> {{ store.suggestResult.camera }}</p>

      <!-- Bảng màu gốc -->
      <div v-if="palette.length" class="mb-2 mt-1">
        <span class="text-cream-300/60">Bảng màu:</span>
        <div class="mt-1 flex flex-wrap gap-1">
          <span v-for="c in palette" :key="c" class="rounded-full bg-ink-700/70 px-2 py-0.5 text-[10px] text-cream-200">{{ c }}</span>
        </div>
      </div>

      <!-- Ghi chú chi tiết gốc -->
      <p v-if="r.detail_notes" class="mb-2 mt-1 rounded-md border border-white/10 bg-white/5 p-2 leading-relaxed text-cream-100"><span class="text-cream-300/60">Chi tiết gốc:</span> {{ r.detail_notes }}</p>

      <!-- Từ khoá -->
      <div v-if="keywords.length" class="mb-2 mt-1 flex flex-wrap gap-1">
        <span v-for="k in keywords" :key="k" class="rounded-full bg-emerald-800/40 px-2 py-0.5 text-[10px] text-emerald-100">#{{ k }}</span>
      </div>

      <div v-if="store.suggestResult.image_prompt_en" class="mt-2">
        <div class="mb-1 flex items-center gap-1.5">
          <div class="seg w-28 shrink-0">
            <button @click="lang='en'" title="Hiển thị tiếng Anh" :class="lang === 'en' ? 'is-active' : ''" class="seg-btn">EN</button>
            <button @click="lang='vi'; if (!store.suggestResult.prompt_vi) store.translate(store.suggestResult.image_prompt_en)" title="Hiển thị tiếng Việt" :class="lang === 'vi' ? 'is-active' : ''" class="seg-btn">VI</button>
          </div>
        </div>
        <p class="max-h-36 overflow-y-auto rounded-md border border-white/10 bg-white/5 p-2 leading-relaxed text-cream-100">{{ lang === 'vi' ? (store.suggestResult.prompt_vi || 'Đang dịch…') : store.suggestResult.image_prompt_en }}</p>

        <button @click="store.saveSuggestResult()" :disabled="store.suggestSaving" title="Lưu kết quả vào Thư viện Prompt để dùng lại sau" class="btn-ghost btn-sm mt-1.5 w-full border border-emerald-500/30 text-emerald-200 hover:bg-emerald-900/30">{{ store.suggestSaving ? 'Đang lưu…' : '💾 Lưu vào Thư viện Prompt' }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ── Nút Tạo ảnh ngay — gradient động, pulse khi đang chạy ── */
.btn-genflow {
  position: relative;
  overflow: hidden;
  padding: 0.55rem 0.75rem;
  border-radius: 0.5rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #fff;
  background: linear-gradient(120deg, #4a7890, #5b9d6e, #4a7890);
  background-size: 200% 100%;
  animation: genflow-gradient 3s ease infinite;
  transition: transform 0.15s, opacity 0.2s;
  box-shadow: 0 4px 14px -4px rgba(91, 157, 110, 0.5);
}
.btn-genflow:hover:not(:disabled) { transform: translateY(-1px); }
.btn-genflow:disabled { opacity: 0.85; cursor: progress; background: linear-gradient(120deg, #3a5d70, #4a7d5a, #3a5d70); }
@keyframes genflow-gradient {
  0% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}
/* Spinner nhỏ trong nút khi đang chạy */
.genflow-spinner {
  display: inline-block;
  width: 14px; height: 14px;
  border: 2px solid rgba(255,255,255,0.35);
  border-top-color: #fff;
  border-radius: 50%;
  animation: genflow-spin 0.7s linear infinite;
}
@keyframes genflow-spin { to { transform: rotate(360deg); } }

/* ── Panel tiến trình ── */
.genflow-panel {
  border: 1px solid rgba(91, 157, 110, 0.35);
  background: linear-gradient(160deg, rgba(91,157,110,.12), rgba(74,122,144,.06));
  border-radius: 0.6rem;
  padding: 0.7rem 0.6rem 0.55rem;
}
/* Stepper */
.genflow-steps { display: flex; align-items: center; }
.genflow-step {
  display: flex; flex-direction: column; align-items: center; gap: 0.2rem;
  flex: 0 0 auto;
  opacity: 0.45; transition: opacity 0.35s, transform 0.35s;
}
.genflow-step.active { opacity: 1; transform: scale(1.06); }
.genflow-step.done { opacity: 0.85; }
.genflow-dot {
  display: grid; place-items: center;
  width: 26px; height: 26px; border-radius: 50%;
  font-size: 12px; line-height: 1;
  background: rgba(255,255,255,0.08);
  border: 1.5px solid rgba(255,255,255,0.2);
  transition: all 0.35s;
}
.genflow-step.active .genflow-dot {
  background: linear-gradient(135deg, #5b9d6e, #4a7890);
  border-color: #5b9d6e;
  box-shadow: 0 0 0 4px rgba(91,157,110,0.18);
}
.genflow-step.done .genflow-dot {
  background: #5b9d6e; border-color: #5b9d6e;
}
.genflow-check { color: #fff; font-size: 12px; font-weight: 700; }
.genflow-step-label { font-size: 9px; color: rgba(245,241,232,0.7); white-space: nowrap; }
.genflow-step.active .genflow-step-label { color: #bfe8c8; font-weight: 600; }

/* Chấm pulse ở bước đang chạy */
.genflow-pulse {
  display: block; width: 8px; height: 8px; border-radius: 50%;
  background: #fff;
  animation: genflow-pulse 1s ease-in-out infinite;
}
@keyframes genflow-pulse {
  0%, 100% { transform: scale(0.6); opacity: 0.6; }
  50% { transform: scale(1.2); opacity: 1; }
}

/* Đường nối giữa các bước */
.genflow-connector {
  flex: 1 1 auto; height: 2px; margin: 0 4px 14px;
  background: rgba(255,255,255,0.12); border-radius: 2px;
  position: relative; overflow: hidden;
}
.genflow-connector.filled::after {
  content: ''; position: absolute; inset: 0;
  background: linear-gradient(90deg, #5b9d6e, #4a7890);
  animation: genflow-fill 0.5s ease forwards;
}
@keyframes genflow-fill { from { transform: scaleX(0); transform-origin: left; } to { transform: scaleX(1); } }

/* Thanh tiến trình chính */
.genflow-bar-wrap {
  margin-top: 0.5rem; height: 6px; border-radius: 3px;
  background: rgba(255,255,255,0.1); overflow: hidden;
}
.genflow-bar {
  height: 100%; border-radius: 3px;
  background: linear-gradient(90deg, #5b9d6e, #6ec9a8, #4a7890);
  background-size: 200% 100%;
  animation: genflow-shimmer 1.8s linear infinite;
  transition: width 0.4s ease;
  box-shadow: 0 0 8px rgba(110,201,168,0.5);
}
@keyframes genflow-shimmer {
  0% { background-position: 0% 0; }
  100% { background-position: 200% 0; }
}
.genflow-pct { margin-top: 0.35rem; font-size: 10px; color: rgba(245,241,232,0.6); text-align: center; }

/* Transition xuất/biến mất của panel */
.genfade-enter-active, .genfade-leave-active { transition: opacity 0.3s ease, transform 0.3s ease; }
.genfade-enter-from, .genfade-leave-to { opacity: 0; transform: translateY(-6px); }
</style>