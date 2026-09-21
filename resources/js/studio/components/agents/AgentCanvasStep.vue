<script setup>
// TÁCH từ DesignAgents.vue (đợt tối ưu 2026-09-24) — BƯỚC CANVAS.
// Shell cung cấp toàn bộ trạng thái/logic qua provide(); component này chỉ inject đúng bề mặt nó dùng
// rồi giữ NGUYÊN VĂN template của bước. Xem shell để biết định nghĩa gốc.
import { inject } from 'vue';
import { useStudioStore } from '../../store.js';
import StudioIcon from '../StudioIcon.vue';
const store = useStudioStore();
const prompt = inject('prompt');
const collectionError = inject('collectionError');
const creatingCollection = inject('creatingCollection');
const canvasLang = inject('canvasLang');
const canvas = inject('canvas');
const RATIO_OPTIONS = inject('RATIO_OPTIONS');
const step = inject('step');
const collection = inject('collection');
const briefStale = inject('briefStale');
const canvasPrompt = inject('canvasPrompt');
const estimatedCredits = inject('estimatedCredits');
const setStep = inject('setStep');
const formatNumber = inject('formatNumber');
const applyCanvas = inject('applyCanvas');
const createCollection = inject('createCollection');
const copyText = inject('copyText');
</script>

<template>
          <section id="agent-step-canvas" role="region" aria-label="Thực thi">
            <div v-if="!collection" class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-10 text-center">
              <StudioIcon name="wand" size="h-8 w-8" class="mx-auto text-brand-300" />
              <p class="mt-3 text-sm font-semibold text-cream-100">Chưa có brief để chuyển sang Canvas</p>
              <p class="mt-1 text-xs text-cream-400">Quay lại bước Định hướng và tạo brief trước.</p>
              <button type="button" class="btn-brand btn-sm mt-4" @click="setStep('brief')">Quay lại Định hướng</button>
            </div>

            <template v-else>
              <div class="grid gap-5 xl:grid-cols-[1fr_minmax(300px,360px)]">
                <div class="space-y-4">
                  <div class="card p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <div><p class="text-label font-semibold uppercase tracking-wide text-brand-300">Canvas Kit</p><h2 class="mt-1 text-lg font-semibold text-cream-100">Prompt &amp; cấu hình tạo ảnh</h2></div>
                      <div class="seg" role="tablist" aria-label="Ngôn ngữ prompt">
                        <button type="button" role="tab" class="seg-btn" :class="{ 'is-active': canvasLang === 'vi' }" :aria-selected="canvasLang === 'vi'" @click="canvasLang = 'vi'">Tiếng Việt</button>
                        <button type="button" role="tab" class="seg-btn" :class="{ 'is-active': canvasLang === 'en' }" :aria-selected="canvasLang === 'en'" @click="canvasLang = 'en'">Tiếng Anh</button>
                      </div>
                    </div>
                    <pre class="mt-3 max-h-64 overflow-y-auto whitespace-pre-wrap rounded-lg border border-ink-700 bg-ink-800 p-4 text-body-lg leading-6 text-cream-100">{{ canvasPrompt }}</pre>
                    <div class="mt-2 flex flex-wrap gap-2">
                      <button type="button" class="tool-btn" @click="copyText(canvasPrompt, 'prompt')"><StudioIcon name="copy" size="h-3 w-3" /> Sao chép prompt</button>
                      <button type="button" class="tool-btn" @click="copyText(collection.brief, 'brief')"><StudioIcon name="copy" size="h-3 w-3" /> Sao chép brief</button>
                    </div>
                  </div>

                  <div class="card p-5">
                    <h3 class="font-display text-base font-semibold text-brand-300">Cấu hình tạo ảnh</h3>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                      <div>
                        <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Tỉ lệ khung</p>
                        <div class="seg mt-2" role="group" aria-label="Tỉ lệ khung ảnh">
                          <button v-for="ratio in RATIO_OPTIONS" :key="ratio" type="button" class="seg-btn" :class="{ 'is-active': canvas.ratio === ratio }" :aria-pressed="canvas.ratio === ratio" @click="canvas.ratio = ratio">{{ ratio }}</button>
                        </div>
                      </div>
                      <div>
                        <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Số biến thể</p>
                        <div class="seg mt-2" role="group" aria-label="Số biến thể ảnh">
                          <button v-for="n in [1, 2, 3, 4]" :key="n" type="button" class="seg-btn" :class="{ 'is-active': Number(canvas.variantCount) === n }" :aria-pressed="Number(canvas.variantCount) === n" @click="canvas.variantCount = n">{{ n }}</button>
                        </div>
                      </div>
                    </div>
                    <label class="mt-4 flex items-center gap-2 text-xs text-cream-200">
                      <input v-model="canvas.useNegative" type="checkbox" class="h-4 w-4 rounded border-ink-600 bg-ink-800">
                      Dùng negative prompt gợi ý (không ghi đè nếu bạn đã có cấu hình riêng)
                    </label>
                    <p class="mt-2 rounded-lg border border-ink-700 bg-ink-800 p-3 text-body leading-5 text-cream-400">{{ canvas.negativePrompt || 'Chưa có negative prompt gợi ý.' }}</p>
                  </div>
                </div>

                <aside class="space-y-4">
                  <div class="card p-5">
                    <h3 class="font-display text-base font-semibold text-brand-300">Sẵn sàng tạo ảnh</h3>
                    <ul class="mt-3 space-y-2 text-xs">
                      <li class="flex items-center gap-2"><StudioIcon name="check" size="h-3.5 w-3.5" class="text-ok" /><span class="text-cream-200">Prompt {{ canvasLang === 'en' ? 'tiếng Anh' : 'tiếng Việt' }} đã sẵn sàng</span></li>
                      <li class="flex items-center gap-2"><StudioIcon name="check" size="h-3.5 w-3.5" class="text-ok" /><span class="text-cream-200">Tỉ lệ {{ canvas.ratio }} · {{ canvas.variantCount }} biến thể</span></li>
                      <li class="flex items-center gap-2"><StudioIcon :name="briefStale ? 'alertTriangle' : 'check'" size="h-3.5 w-3.5" :class="briefStale ? 'text-warn' : 'text-ok'" /><span :class="briefStale ? 'text-warn' : 'text-cream-200'">{{ briefStale ? 'Brief đã cũ — nên tạo lại' : 'Brief khớp prompt/trend hiện tại' }}</span></li>
                    </ul>
                    <div class="mt-4 rounded-lg border border-ink-700 bg-ink-800 p-3">
                      <div class="flex items-center justify-between text-xs"><span class="text-cream-400">Chi phí ước tính</span><span class="font-semibold text-cream-100">~{{ estimatedCredits }} credit</span></div>
                      <div class="mt-1 flex items-center justify-between text-body"><span class="text-cream-400">Credit còn lại</span><span class="text-cream-200">{{ formatNumber(store.creditsLeft) }}</span></div>
                    </div>
                    <!-- Nút này TRÙNG hành động với nút chính ở thanh dưới ⇒ hạ xuống thứ yếu,
                         vì hai nút chính cùng lúc làm người dùng đứng hình (docs/DESIGN_SYSTEM.md §4 luật 3). -->
                    <button type="button" class="tool-btn mt-4 w-full justify-center !py-2.5" @click="applyCanvas">
                      <StudioIcon name="zap" size="h-3.5 w-3.5" /> Áp dụng &amp; mở Tạo ảnh
                    </button>
                    <!-- Gợi ý nhẹ (không ép): chưa gắn bộ sưu tập thì nhắc tạo để ảnh nằm gọn, dễ quản lý/chia sẻ. -->
                    <div v-if="!store.appliedProject" class="mt-2 rounded-lg border border-brand-500/30 bg-brand-500/10 px-3 py-2 text-tiny leading-4 text-brand-200">
                      <StudioIcon name="info" size="h-3 w-3" class="mr-1 inline-block align-[-2px]" />
                      Gợi ý: tạo bộ sưu tập từ brief để ảnh tạo ra nằm gọn trong một bộ — dễ quản lý, duyệt mẫu và chia sẻ với khách.
                    </div>
                    <!-- Cờ đang-chạy: không có nó thì bấm hai lần tạo HAI dự án trùng nhau. -->
                    <button type="button" class="tool-btn mt-2 w-full justify-center !py-2.5" :disabled="creatingCollection" @click="createCollection">
                      <StudioIcon name="briefcase" size="h-3.5 w-3.5" /> {{ creatingCollection ? 'Đang tạo…' : 'Tạo bộ sưu tập từ brief' }}
                    </button>
                    <button type="button" class="tool-btn mt-2 w-full justify-center !py-2.5" @click="setStep('brief')">
                      <StudioIcon name="arrowLeft" size="h-3.5 w-3.5" /> Chỉnh lại brief
                    </button>
                    <div v-if="collectionError || store.collectionBriefError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-danger/40 bg-danger/10 p-3 text-xs leading-5 text-danger"><StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ collectionError || store.collectionBriefError }}</span></div>
                  </div>

                  <div class="card p-5">
                    <h3 class="font-display text-base font-semibold text-brand-300">Bộ sưu tập</h3>
                    <p class="mt-1.5 text-xs leading-5 text-cream-200">{{ collection.project_payload?.name }}</p>
                    <p class="mt-1 text-label text-cream-400">Tạo bộ sưu tập chỉ tạo vỏ dự án; ảnh vẫn do bạn chủ động tạo trong Canvas.</p>
                  </div>
                </aside>
              </div>
            </template>
          </section>
</template>
