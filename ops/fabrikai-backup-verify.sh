#!/usr/bin/env bash
#
# KIỂM CHỨNG BẢN SAO LƯU — hai mức, tuỳ quyền của tài khoản CSDL.
#
# Vì sao có tệp này (2026-09-25): "sao lưu xong" và "tệp có tồn tại" KHÔNG phải là "khôi phục được".
# Tệp có thể nguyên vẹn mà vẫn thiếu bảng, thiếu dữ liệu, hoặc bị cắt giữa đường.
#
#   MỨC 1 (luôn chạy được): tệp nén nguyên vẹn · có dấu kết thúc của mysqldump · đủ SỐ BẢNG ·
#           mọi bảng CÓ DỮ LIỆU trong CSDL thật đều có lệnh INSERT trong bản sao lưu.
#   MỨC 2 (cần một CSDL TẠM ghi được): nạp thật rồi ĐỐI CHIẾU SỐ DÒNG TỪNG BẢNG với CSDL thật.
#
# Tài khoản hiện tại KHÔNG có quyền CREATE DATABASE (đã thử: ERROR 1044), nên Mức 2 chỉ chạy khi
# chủ dự án tạo sẵn một CSDL trống (hPanel → MySQL Databases) rồi truyền tên vào tham số 2.
#
# Dùng:
#   ~/bin/fabrikai-backup-verify.sh                          # Mức 1, bản mới nhất
#   ~/bin/fabrikai-backup-verify.sh <tệp.sql.gz>             # Mức 1, tệp chỉ định
#   ~/bin/fabrikai-backup-verify.sh <tệp.sql.gz> <csdl-tạm>  # Mức 2 (nếu có quyền ghi CSDL đó)
#
set -euo pipefail

APP="$HOME/domains/fabrikai.shop"
DEST="$HOME/db-backups"

FILE=""
if [ "$#" -ge 1 ] && [ -n "$1" ]; then FILE="$1"; else
    FILE="$(ls -1t "$DEST"/fabrikai-*.sql.gz 2>/dev/null | head -1 || true)"
fi
[ -n "$FILE" ] && [ -f "$FILE" ] || { echo "Không thấy tệp sao lưu nào trong $DEST."; exit 1; }

SCRATCH="${2:-}"

CNF="$(mktemp /tmp/fabrikai-my.XXXXXX.cnf)"
chmod 600 "$CNF"
DROP_SCRATCH=""
cleanup() {
    if [ -n "$DROP_SCRATCH" ]; then
        mysql --defaults-extra-file="$CNF" -e "DROP DATABASE IF EXISTS $DROP_SCRATCH" >/dev/null 2>&1 || true
    fi
    rm -f "$CNF"
}
trap cleanup EXIT INT TERM

DBN="$(php "$HOME/bin/fabrikai-db-cnf.php" "$APP" "$CNF")"
echo "── KIỂM CHỨNG $(basename "$FILE") (CSDL thật: $DBN)"

fail() { echo "HỎNG: $1"; exit 1; }

# ─────────────── MỨC 1 ───────────────
gzip -t "$FILE" || fail "tệp nén không nguyên vẹn."
SIZE="$(stat -c%s "$FILE")"
[ "$SIZE" -gt 2048 ] || fail "tệp quá nhỏ ($SIZE byte)."

TMP="$(mktemp /tmp/fabrikai-dump.XXXXXX.sql)"
trap 'rm -f "$TMP"; cleanup' EXIT INT TERM
gzip -dc "$FILE" > "$TMP"

[ "$(tail -n 1 "$TMP" | grep -c 'Dump completed' || true)" -ge 1 ] \
    || fail "thiếu dấu kết thúc 'Dump completed' — bản sao lưu bị cắt."

LIVE_TABLES="$(mysql --defaults-extra-file="$CNF" -N -B -e "select table_name from information_schema.tables where table_schema='$DBN' and table_type='BASE TABLE'")"
[ -n "$LIVE_TABLES" ] || fail "CSDL thật không có bảng nào?"

N_LIVE="$(echo "$LIVE_TABLES" | wc -l | tr -d ' ')"
N_DUMP="$(grep -c '^CREATE TABLE' "$TMP" || true)"
[ "$N_DUMP" -eq "$N_LIVE" ] || fail "sao lưu có $N_DUMP bảng, CSDL thật có $N_LIVE bảng."

# Bảng CÓ dữ liệu trong CSDL thật thì PHẢI có lệnh INSERT trong bản sao lưu.
COUNT_Q=""
for t in $LIVE_TABLES; do COUNT_Q="$COUNT_Q select '$t' as t, count(*) as c from $t union all"; done
COUNT_Q="${COUNT_Q% union all}"
NONEMPTY="$(mysql --defaults-extra-file="$CNF" -N -B "$DBN" -e "$COUNT_Q" | awk -F'\t' '$2 > 0 {print $1}')"
MISSING=""
for t in $NONEMPTY; do
    # mysqldump viết "INSERT INTO <backtick>tên<backtick> VALUES" — dấu chấm khớp hai dấu backtick
    # mà không phải nhúng backtick vào chuỗi bash (nhúng là bị hiểu thành thay thế lệnh — đã dính thật).
    grep -q "^INSERT INTO .$t." "$TMP" || MISSING="$MISSING $t"
done
[ -z "$MISSING" ] || fail "các bảng có dữ liệu nhưng THIẾU trong bản sao lưu:$MISSING"

echo "MỨC 1 ĐẠT: $(du -h "$FILE" | cut -f1) · $N_DUMP bảng · $(echo "$NONEMPTY" | grep -c . || true) bảng có dữ liệu đều có mặt"

# ─────────────── MỨC 2 ───────────────
if [ -z "$SCRATCH" ]; then
    echo "MỨC 2 BỎ QUA: chưa có CSDL tạm. Muốn khôi phục thử thật, tạo một CSDL trống trong hPanel"
    echo "            rồi chạy lại:  ~/bin/fabrikai-backup-verify.sh $(basename "$FILE") <tên-csdl-tạm>"
    echo "── XONG (Mức 1)."
    exit 0
fi

if ! mysql --defaults-extra-file="$CNF" -e "DROP DATABASE IF EXISTS $SCRATCH; CREATE DATABASE $SCRATCH" 2>/dev/null; then
    echo "MỨC 2 KHÔNG CHẠY ĐƯỢC: tài khoản không có quyền tạo CSDL '$SCRATCH'."
    echo "── XONG (Mức 1)."
    exit 0
fi
DROP_SCRATCH="$SCRATCH"

gzip -dc "$FILE" | mysql --defaults-extra-file="$CNF" "$SCRATCH" || fail "nạp bản sao lưu vào CSDL tạm thất bại."

LIVE="$(mysql --defaults-extra-file="$CNF" -N -B "$DBN" -e "$COUNT_Q" | sort)"
COPY="$(mysql --defaults-extra-file="$CNF" -N -B "$SCRATCH" -e "$COUNT_Q" | sort)"
if [ "$LIVE" != "$COPY" ]; then
    echo "KHÁC NHAU giữa CSDL thật và bản khôi phục:"
    diff <(echo "$LIVE") <(echo "$COPY") | head -30
    fail "số dòng không khớp."
fi

TOTAL="$(echo "$LIVE" | awk -F'\t' '{s+=$2} END {print s}')"
echo "MỨC 2 ĐẠT: khôi phục thật vào '$SCRATCH' — $N_LIVE bảng · $TOTAL dòng khớp TỪNG BẢNG."
echo "── CSDL tạm sẽ được xoá. CSDL thật không bị đụng tới."
