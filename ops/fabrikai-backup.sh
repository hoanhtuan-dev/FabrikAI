#!/usr/bin/env bash
#
# Sao lưu DB FabrikAI — chạy được TỪ BẤT KỲ ĐÂU, không cần nhớ mật khẩu.
#
# Vì sao có tệp này (2026-09-25): hai lần liên tiếp lệnh sao lưu viết tay HỎNG vì đọc thông tin DB
# bằng cách tự parse .env (parse_ini_file trả về rỗng ⇒ mysqldump báo "using password: NO").
# Ở đây thông tin đăng nhập lấy từ CHÍNH Laravel (config database) — đúng cái mà ứng dụng đang dùng,
# nên không thể lệch. Mật khẩu đi qua tệp my.cnf tạm (chmod 600) chứ KHÔNG qua tham số dòng lệnh.
#
# Dùng:
#   ~/bin/fabrikai-backup.sh                 # lưu vào ~/db-backups, giữ 10 bản mới nhất
#   KEEP=30 ~/bin/fabrikai-backup.sh         # giữ 30 bản
#   ~/bin/fabrikai-backup.sh /duong/dan/khac
#
set -euo pipefail

APP="$HOME/domains/fabrikai.shop"
DEST="$HOME/db-backups"
if [ "$#" -ge 1 ] && [ -n "$1" ]; then DEST="$1"; fi
KEEP="${KEEP:-10}"

cd "$APP"
[ -f artisan ] || { echo "KHÔNG thấy $APP/artisan — sai thư mục?"; exit 1; }
mkdir -p "$DEST"

CNF="$(mktemp /tmp/fabrikai-my.XXXXXX.cnf)"
chmod 600 "$CNF"
# Xoá tệp mật khẩu trong MỌI đường thoát, kể cả khi lỗi.
trap 'rm -f "$CNF"' EXIT INT TERM

DBN="$(php -r '
require $argv[1]."/vendor/autoload.php";
$app = require $argv[1]."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d = config("database.connections.".config("database.default"));
$q = fn ($s) => "\"".str_replace(["\\", "\""], ["\\\\", "\\\""], (string) $s)."\"";
$out = "[client]".PHP_EOL."user=".$q($d["username"]).PHP_EOL."password=".$q($d["password"]).PHP_EOL;
$out .= ! empty($d["unix_socket"])
    ? "socket=".$q($d["unix_socket"]).PHP_EOL
    : "host=".$q($d["host"]).PHP_EOL."port=".$q($d["port"]).PHP_EOL;
file_put_contents($argv[2], $out);
echo $d["database"];
' "$APP" "$CNF")"

STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$DEST/fabrikai-$STAMP.sql.gz"

echo "── SAO LƯU $DBN → $OUT"
mysqldump --defaults-extra-file="$CNF" \
    --single-transaction --quick --skip-lock-tables \
    --routines --events --triggers \
    --default-character-set=utf8mb4 \
    "$DBN" | gzip -9 > "$OUT"

# ── KIỂM CHỨNG: một tệp sao lưu KHÔNG được coi là xong chỉ vì lệnh trả về 0 ──
SIZE="$(stat -c%s "$OUT")"
[ "$SIZE" -gt 2048 ] || { echo "HỎNG: tệp quá nhỏ ($SIZE byte)."; exit 1; }

# (1) Tệp nén phải NGUYÊN VẸN. Bản trước tôi kiểm bằng "tail -c 200 | gzip -dc" — cắt giữa
# luồng gzip nên gzip không xuất được gì, và một bản sao lưu TỐT bị báo HỎNG. Đã sửa.
gzip -t "$OUT" || { echo "HỎNG: tệp nén không nguyên vẹn."; rm -f "$OUT"; exit 1; }

# (2) Dấu kết thúc của mysqldump — thiếu nó nghĩa là bản sao lưu bị cắt giữa đường.
MARK="$(gzip -dc "$OUT" | tail -n 1 | grep -c "Dump completed" || true)"
[ "$MARK" -ge 1 ] || { echo "HỎNG: thiếu dấu kết thúc 'Dump completed' — bản sao lưu có thể bị cắt."; rm -f "$OUT"; exit 1; }

IN_DUMP="$(gzip -dc "$OUT" | grep -c '^CREATE TABLE' || true)"
IN_DB="$(mysql --defaults-extra-file="$CNF" -N -B -e "select count(*) from information_schema.tables where table_schema='$DBN' and table_type='BASE TABLE'")"
[ "$IN_DUMP" -eq "$IN_DB" ] || { echo "HỎNG: sao lưu có $IN_DUMP bảng, CSDL có $IN_DB bảng."; exit 1; }

echo "ĐẠT: $(du -h "$OUT" | cut -f1) · $IN_DUMP bảng · kết thúc hợp lệ"

# ── DỌN BẢN CŨ (giữ $KEEP bản mới nhất) ──
ls -1t "$DEST"/fabrikai-*.sql.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r old; do
    rm -f "$old"
    echo "   đã xoá bản cũ: $(basename "$old")"
done

echo "── XONG. Bản mới nhất: $OUT"