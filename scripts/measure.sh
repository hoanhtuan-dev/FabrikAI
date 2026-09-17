#!/usr/bin/env bash
# FabrikAI — NGUỒN SỐ LIỆU CHUẨN DUY NHẤT (STUDIO_REVIEW_PLAN.md §5 quy tắc 1).
#
# Mở MỖI vòng bằng cách chạy file này rồi dán output vào đầu file trạng thái.
# Đừng bao giờ trích số từ tài liệu cũ — cả 4 tài liệu đã từng lệch neo.
#
# Dùng:  bash scripts/measure.sh            # đầy đủ (chạy cả test suite, ~25s)
#        bash scripts/measure.sh --no-tests # bỏ test (nhanh, ~2s)
set -uo pipefail
cd "$(dirname "$0")/.."

RUN_TESTS=1
[ "${1:-}" = "--no-tests" ] && RUN_TESTS=0

echo "══════════════════════════════════════════════════════════════"
echo " FabrikAI · ĐO CHUẨN · $(date '+%Y-%m-%d %H:%M:%S')"
echo "══════════════════════════════════════════════════════════════"

printf 'HEAD              : %s
' "$(git rev-parse --short HEAD 2>/dev/null || echo 'KHÔNG có git')"
printf 'Nhánh             : %s
' "$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '-')"
printf 'Commit count      : %s
' "$(git rev-list --count HEAD 2>/dev/null || echo '-')"
printf 'Working tree      : %s
' "$([ -z "$(git status --porcelain 2>/dev/null)" ] && echo 'SẠCH' || echo 'CÓ THAY ĐỔI CHƯA COMMIT')"

# ── Route ──
ROUTES="$(php artisan route:list 2>/dev/null | grep -oE 'Showing \[[0-9]+\] routes' | grep -oE '[0-9]+')"
printf 'Route             : %s\n' "${ROUTES:-?}"

# ── Test ──
if [ "$RUN_TESTS" = "1" ]; then
  TEST_OUT="$(vendor/bin/phpunit 2>&1 | tail -3 | tr -d '\033' | grep -oE '(OK \([0-9]+ tests, [0-9]+ assertions\)|Tests: [0-9]+.*|FAILURES|ERRORS)' | head -1)"
  TEST_FILES="$(find tests -name '*Test.php' | wc -l | tr -d ' ')"
  printf 'Test              : %s
' "${TEST_OUT:-? (xem output phpunit)}"
  printf 'File test         : %s\n' "$TEST_FILES"
else
  printf 'Test              : (bỏ qua — --no-tests)\n'
fi

# ── Kích thước code ──
echo "──────────────────────────────────────────────────────────────"
printf 'app/  PHP          : %s file · %s dòng\n' \
  "$(find app -name '*.php' | wc -l | tr -d ' ')" \
  "$(find app -name '*.php' -exec cat {} + | wc -l | tr -d ' ')"
printf 'app/Services       : %s file\n' "$(find app/Services -name '*.php' 2>/dev/null | wc -l | tr -d ' ')"
printf 'app/Models         : %s file\n' "$(find app/Models -name '*.php' 2>/dev/null | wc -l | tr -d ' ')"
printf 'Migrations         : %s file\n' "$(find database/migrations -name '*.php' 2>/dev/null | wc -l | tr -d ' ')"

echo "── 6 file lớn nhất (điểm nóng monolith) ──"
wc -l app/Http/Controllers/*.php app/Support/helpers.php resources/js/studio/store.js 2>/dev/null \
  | sort -rn | head -7 | sed 's/^/  /'

echo "── JS/Vue studio ──"
printf '  %s file (%s .vue) · %s dòng\n' \
  "$(find resources/js/studio -type f \( -name '*.js' -o -name '*.vue' \) | wc -l | tr -d ' ')" \
  "$(find resources/js/studio -name '*.vue' | wc -l | tr -d ' ')" \
  "$(find resources/js/studio -type f \( -name '*.js' -o -name '*.vue' \) -exec cat {} + | wc -l | tr -d ' ')"

printf 'public_html       : %s\n' "$(du -sh public_html 2>/dev/null | cut -f1)"
echo "══════════════════════════════════════════════════════════════"
