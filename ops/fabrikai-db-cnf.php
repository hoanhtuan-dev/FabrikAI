<?php
/**
 * In ra TÊN CSDL và ghi một tệp my.cnf tạm chứa thông tin đăng nhập THẬT của ứng dụng.
 *
 * MỘT nơi duy nhất biết cách lấy thông tin DB. Vì sao không tự parse .env: đã HỎNG HAI LẦN liên tiếp
 * (parse_ini_file trả rỗng ⇒ mysqldump báo "using password: NO" ⇒ tưởng là mật khẩu sai). Ở đây lấy
 * từ chính Laravel — đúng cái mà ứng dụng đang chạy, nên không thể lệch.
 *
 * dùng: php fabrikai-db-cnf.php <thư-mục-app> <tệp-cnf-ra>
 */
$app = $argv[1] ?? '';
$out = $argv[2] ?? '';
if ($app === '' || $out === '') {
    fwrite(STDERR, "dùng: php fabrikai-db-cnf.php <thư-mục-app> <tệp-cnf-ra>" . PHP_EOL);
    exit(2);
}

require $app . '/vendor/autoload.php';
$laravel = require $app . '/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$d = config('database.connections.' . config('database.default'));
$q = fn ($s) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $s) . '"';

$cnf = '[client]' . PHP_EOL
    . 'user=' . $q($d['username']) . PHP_EOL
    . 'password=' . $q($d['password']) . PHP_EOL;

$cnf .= ! empty($d['unix_socket'])
    ? 'socket=' . $q($d['unix_socket']) . PHP_EOL
    : 'host=' . $q($d['host']) . PHP_EOL . 'port=' . $q($d['port']) . PHP_EOL;

file_put_contents($out, $cnf);
chmod($out, 0600);

echo $d['database'];
