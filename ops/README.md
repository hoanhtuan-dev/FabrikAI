# ops/ — công cụ vận hành FabrikAI

Ba tệp ở đây chạy **trên máy chủ**, không phải trong ứng dụng. Chúng được đưa vào git vì lý do rất thực tế:
bản đầu tôi viết chúng thẳng trên máy chủ bằng SSH, nghĩa là **máy chủ mất là mất luôn cách sao lưu** —
đúng lúc cần nhất.

## Cài lên máy chủ

```bash
ssh -p 65002 u310846799@145.79.25.57
mkdir -p ~/bin
# từ máy local:
scp -P 65002 ops/fabrikai-*.sh ops/fabrikai-db-cnf.php u310846799@145.79.25.57:~/bin/
ssh -p 65002 u310846799@145.79.25.57 'chmod +x ~/bin/fabrikai-*.sh'
```

## Sao lưu

```bash
~/bin/fabrikai-backup.sh                 # → ~/db-backups/fabrikai-<ngày-giờ>.sql.gz, giữ 10 bản
KEEP=30 ~/bin/fabrikai-backup.sh         # giữ 30 bản
~/bin/fabrikai-backup-verify.sh          # kiểm chứng bản mới nhất (Mức 1)
```

**Vì sao phải có bộ công cụ này:** hai lần liên tiếp lệnh sao lưu viết tay đều HỎNG, và cả hai lần đều
vì *tự parse `.env`* để lấy mật khẩu (`parse_ini_file` trả về rỗng ⇒ `mysqldump` báo
`using password: NO` ⇒ rất dễ kết luận sai là "mật khẩu sai"). `fabrikai-db-cnf.php` lấy thông tin
đăng nhập từ **chính Laravel** — đúng cái ứng dụng đang dùng, nên không thể lệch.

Mật khẩu đi qua tệp `my.cnf` tạm (`chmod 600`, xoá trong mọi đường thoát), **không** qua tham số dòng lệnh.

## Kiểm chứng — hai mức

| Mức | Cần gì | Chứng minh được |
|---|---|---|
| 1 | Không gì | tệp nén nguyên vẹn · có dấu `Dump completed` · đủ số bảng · mọi bảng **có dữ liệu** đều có lệnh `INSERT` |
| 2 | Một CSDL trống ghi được | **khôi phục thật** rồi đối chiếu **số dòng từng bảng** với CSDL thật |

Tài khoản hiện tại **không có quyền `CREATE DATABASE`** (đã thử: `ERROR 1044`), nên Mức 2 chỉ chạy khi
chủ dự án tạo sẵn một CSDL trống trong hPanel rồi truyền tên vào:

```bash
~/bin/fabrikai-backup-verify.sh ~/db-backups/fabrikai-....sql.gz ten_csdl_tam
```

Script tự xoá CSDL tạm khi xong — CSDL thật không bị đụng tới.
