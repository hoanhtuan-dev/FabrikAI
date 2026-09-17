<script setup>
// [Yêu cầu 2026-09-17] Card "Tạo biến thể ảnh" — tách ra từ chip "Tạo ảnh mới" của card cũ
// "Ảnh mới từ ảnh mẫu". Toàn bộ logic nằm ở RefImageCard (variant='variation'); file này chỉ
// neo đúng chế độ + giữ 2 luồng có STATE RIÊNG thay vì dùng chung như hồi còn chip.
import RefImageCard from './RefImageCard.vue';
</script>

<template>
  <RefImageCard variant="variation" />
</template>
