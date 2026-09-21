<script setup>
/**
 * BƯỚC 4 — THỰC THI (2026-09-25).
 *
 * Bước này nay chia thành ba việc con: dựng danh sách mẫu → sinh prompt TỪNG mẫu và chốt từng mẫu →
 * đưa sang Canvas và lưu phiên. Nội dung nằm ở AgentSamplesStep.vue; component này chỉ giữ khung bước
 * và dải bước con, để cấu trúc 4 bước của khung trang không phải biết chi tiết bên trong.
 */
import AgentSubSteps from './AgentSubSteps.vue';
import AgentSamplesStep from './AgentSamplesStep.vue';
</script>

<template>
  <section id="agent-step-canvas" role="region" aria-label="Thực thi">
    <AgentSubSteps step-id="canvas" />
    <AgentSamplesStep />
  </section>
</template>
