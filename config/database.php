<?php
// --------------------------------------------------------------
// config/database.php — CV Gelora Maju Bersama (MANPRO) (REVISED)
// --------------------------------------------------------------

/**
 * Perubahan / catatan:
 * - Default DB name di-set ke 'cv_gmb' (ganti kalau berbeda)
 * - Fungsi role/permission sudah disiapkan untuk mendukung role_id / role_name / legacy role text
 * - Berisi helper DB (PDO), transaksi, CSRF, upload image, dan util lain yang umum
 */

define('DEV_MODE', true);
if (DEV_MODE) { error_reporting(E_ALL); ini_set('display_errors', 1); }
else { error_reporting(E_ALL & ~E_NOTICE); ini_set('display_errors', 0); }

if (session_status() === PHP_SESSION_NONE) session_start();

/* ===== THEME DEFAULT (fallback) ===== */
define('THEME_DEFAULT', 'light');

/* ===== PATH / URL ===== */
define('BASE_URL', '/MANPRO');                         // ← sesuaikan folder project di htdocs jika perlu
define('APP_DIR', dirname(__DIR__));                   // .../MANPRO
define('UPLOAD_DIR', APP_DIR . '/uploads/');
define('PROFILE_DIR', UPLOAD_DIR . 'profile/');
define('STOK_DIR',    UPLOAD_DIR . 'stok/');

function ensure_dir($p){ if(!is_dir($p)) @mkdir($p,0775,true); }
ensure_dir(PROFILE_DIR); ensure_dir(STOK_DIR);

/* ===== DB KONFIG (sesuaikan jika perlu) ===== */
$host = 'localhost';
$dbname = 'cv_gmb';            // <-- gunakan database baru cv_gmb
$username = 'root';
$password = '';
$charset = 'utf8mb4';

try {
  $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
  $pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
} catch (PDOException $e) {
  // Jangan tampilkan pesan detail di production
  if (DEV_MODE) {
    die('Koneksi PDO gagal: ' . $e->getMessage());
  } else {
    die('Koneksi database gagal. Hubungi administrator.');
  }
}

// (opsional) MySQLi utk kompatibilitas lama
$mysqli = @mysqli_connect($host,$username,$password,$dbname);
if ($mysqli) { mysqli_set_charset($mysqli,$charset); }

/* ===== Helper DB (PDO wrapper) ===== */
function query(string $sql, array $p = []): PDOStatement {
  global $pdo;
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return $st;
}
function fetch(string $sql, array $p = []) {
  return query($sql,$p)->fetch();
}
function fetchAll(string $sql, array $p = []): array {
  return query($sql,$p)->fetchAll();
}
function exec_sql(string $sql, array $p = []): int {
  // execute and return affected rows
  $st = query($sql,$p);
  return $st->rowCount();
}
function lastId(): string {
  global $pdo; return $pdo->lastInsertId();
}
function begin(): void {
  global $pdo; $pdo->beginTransaction();
}
function commit(): void {
  global $pdo; $pdo->commit();
}
function rollback(): void {
  global $pdo; if ($pdo->inTransaction()) $pdo->rollBack();
}
function inTransaction(): bool {
  global $pdo; return $pdo->inTransaction();
}

/* ===== Util ===== */
function asset_url(string $rel): string {
  return rtrim(BASE_URL,'/') . '/' . ltrim($rel,'/');
}
function redirect(string $path) {
  $url = str_starts_with($path,'http') ? $path : rtrim(BASE_URL,'/') . '/' . ltrim($path,'/');
  header("Location: $url"); exit;
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
if (!function_exists('rupiah')) {
  function rupiah($n) {
    if ($n === null || $n === '') return '-';
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
  }
}
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function check_csrf(?string $t): bool {
  return isset($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

/* ===== Auth & Role helpers ===== */
function current_user(): ?array { return $_SESSION['user'] ?? null; }

/**
 * Ambil role name dari object user session.
 * Mendukung beberapa pola:
 * - jika user punya 'role' (legacy text), gunakan itu
 * - jika user punya 'role_name' gunakan itu
 * - jika user punya 'role_id', ambil dari tabel roles
 * - kembalikan null jika tak ditemukan
 */
function get_user_role_name(?array $u): ?string {
  if (!$u) return null;
  // legacy text columns
  if (!empty($u['role_name'])) return (string)$u['role_name'];
  if (!empty($u['role'])) return (string)$u['role']; // backwards compat
  if (!empty($u['role_id'])) {
    // ambil dari DB
    try {
      $r = fetch("SELECT name FROM roles WHERE id = ? LIMIT 1", [$u['role_id']]);
      if ($r && !empty($r['name'])) return $r['name'];
    } catch (Exception $e) {
      // ignore, return null
    }
  }
  return null;
}

/**
 * Periksa apakah user punya salah satu role pada array $roles (nama role).
 */
function user_has_role(?array $u, array $roles): bool {
  $name = get_user_role_name($u);
  if (!$name) return false;
  foreach ($roles as $r) { if (strcasecmp($r, $name)===0) return true; }
  return false;
}

/**
 * Ambil semua permission code untuk user (memakai role -> role_permissions -> permissions)
 * Mengembalikan array string, kosong jika tidak ditemukan.
 */
function get_user_permissions(int $user_id): array {
  // cache in session for performance (invalidasi manual jika role change)
  if (!isset($_SESSION['_perms_cache'])) $_SESSION['_perms_cache'] = [];
  if (isset($_SESSION['_perms_cache'][$user_id])) return $_SESSION['_perms_cache'][$user_id];

  $sql = "SELECT p.code
          FROM users u
          LEFT JOIN roles r ON u.role_id = r.id
          LEFT JOIN role_permissions rp ON rp.role_id = r.id
          LEFT JOIN permissions p ON p.id = rp.permission_id
          WHERE u.user_id = ?";
  try {
    $rows = fetchAll($sql, [$user_id]);
    $perms = array_map(fn($r)=>$r['code'],$rows);
    $_SESSION['_perms_cache'][$user_id] = $perms;
    return $perms;
  } catch (Exception $e) {
    return [];
  }
}

/**
 * Cek permission spesifik
 */
function user_has_permission(int $user_id, string $perm_code): bool {
  $perms = get_user_permissions($user_id);
  return in_array($perm_code, $perms, true);
}

/**
 * Pastikan user login, redirect ke login jika belum
 */
function require_login() {
  if (empty($_SESSION['user'])) {
    $login = rtrim(BASE_URL,'/') . '/auth/login.php';
    $here  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if ($here !== parse_url($login, PHP_URL_PATH)) {
      header("Location: $login");
      exit;
    }
  }
}

/**
 * Izin berbasis role (nama role). Menolak akses bila tidak punya role.
 * Contoh: allow(['direktur','staff_keuangan'])
 */
function allow(array $roles) {
  require_login();
  $u = current_user();
  if (!$u || !user_has_role($u, $roles)) {
    http_response_code(403);
    echo '<div style="margin:2rem;font:14px/1.5 system-ui"><b>Akses ditolak</b></div>';
    exit;
  }
}
/**
 * Alias yang lebih deskriptif
 */
function require_role(array $roles) { allow($roles); }

/**
 * Helper: landing page by role (nama role). Menggunakan role name dari session / DB
 */
function landing_by_role(?array $u): string {
  $role = get_user_role_name($u) ?? '';
  $map = [
    'direktur'           => 'pages/dashboard/index.php',
    'staff_keuangan'     => 'pages/keuangan/index.php',
    'staff_operasional'  => 'pages/stok/index.php',
  ];
  return $map[strtolower($role)] ?? 'pages/dashboard/index.php';
}

/* ===== Warehouse access helper (untuk operasional) ===== */
function user_can_access_warehouse(int $user_id, int $warehouse_id): bool {
  // Direktur/keuangan bypass (jika ingin)
  $u = fetch("SELECT role_id FROM users WHERE user_id = ? LIMIT 1", [$user_id]);
  if ($u && !empty($u['role_id'])) {
    $role = fetch("SELECT name FROM roles WHERE id = ? LIMIT 1", [$u['role_id']]);
    if ($role && in_array(strtolower($role['name']), ['direktur','staff_keuangan'], true)) return true;
  }
  // check user_warehouses
  $r = fetch("SELECT 1 FROM user_warehouses WHERE user_id = ? AND warehouse_id = ? LIMIT 1", [$user_id,$warehouse_id]);
  return (bool)$r;
}

/* ===== Avatar & Gambar Produk ===== */
function upload_image(array $file, string $toDir, array $allow=['jpg','jpeg','png','webp'], int $max=3_000_000): array {
  if (!isset($file['error']) || is_array($file['error'])) return [false,'Upload tidak valid'];
  if ($file['error'] !== UPLOAD_ERR_OK) {
    $map=[1=>'Batas server',2=>'Batas form',3=>'Sebagian',4=>'Tidak ada file',6=>'Tmp hilang',7=>'Gagal tulis',8=>'Ekstensi blok'];
    return [false,$map[$file['error']] ?? 'Upload error'];
  }
  if ($file['size'] > $max) return [false,'Ukuran terlalu besar'];
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, $allow, true)) return [false,'Format tidak didukung'];
  $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($file['tmp_name']);
  if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) return [false,'MIME tidak valid'];
  ensure_dir($toDir);
  $name = bin2hex(random_bytes(8)) . '.' . $ext;
  $dest = rtrim($toDir,'/\\') . DIRECTORY_SEPARATOR . $name;
  if (!move_uploaded_file($file['tmp_name'],$dest)) return [false,'Gagal simpan'];
  $rel = str_replace(APP_DIR . DIRECTORY_SEPARATOR, '', $dest);
  $rel = str_replace('\\','/',$rel);
  return [true,$rel];
}
function profile_role_dir(string $role): string {
  $slug = in_array($role, ['direktur','staff_keuangan','staff_operasional'], true) ? $role : 'lainnya';
  return rtrim(PROFILE_DIR,'/\\') . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR;
}
function ensure_profile_role_dir(string $role){ ensure_dir(profile_role_dir($role)); }

function avatar_url(?string $relPath, string $label='U'): string {
  $fallback = "data:image/svg+xml;utf8," . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72">
       <circle cx="36" cy="36" r="36" fill="#E1C5AA"/>
       <text x="50%" y="58%" text-anchor="middle" font-size="28" font-family="Arial" fill="#59382B">' . e($label) . '</text>
     </svg>'
  );
  if (!$relPath) return $fallback;
  $abs = APP_DIR . '/' . str_replace(['\\','/'], DIRECTORY_SEPARATOR, $relPath);
  if (!is_file($abs)) return $fallback;
  return asset_url($relPath) . '?v=' . urlencode((string)@filemtime($abs));
}
function product_img_url(?string $relPath, string $label='P'): string {
  $fallback = "data:image/svg+xml;utf8," . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="56" height="56">
       <rect width="100%" height="100%" rx="10" ry="10" fill="#E1C5AA"/>
       <text x="50%" y="58%" text-anchor="middle" font-size="22" font-family="Arial" fill="#59382B">' . e($label) . '</text>
     </svg>'
  );
  if (!$relPath) return $fallback;
  $abs = APP_DIR . '/' . str_replace(['\\','/'], DIRECTORY_SEPARATOR, $relPath);
  if (!is_file($abs)) return $fallback;
  return asset_url($relPath) . '?v=' . urlencode((string)@filemtime($abs));
}

/* ===== Fallback: Deteksi Tabel & Kolom ===== */
function table_exists(string $table): bool {
  global $pdo, $dbname;
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1");
  $st->execute([$dbname, $table]);
  return (bool)$st->fetchColumn();
}
function column_exists(string $table, string $column): bool {
  global $pdo, $dbname;
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $st->execute([$dbname, $table, $column]);
  return (bool)$st->fetchColumn();
}
function pick_column(string $table, array $candidates): ?string {
  foreach ($candidates as $c) { if (column_exists($table,$c)) return $c; } return null;
}
function pick_date_column(string $table): ?string {
  return pick_column($table, ['tgl','tanggal','tgl_transaksi','date','created_at','waktu','datetime']);
}
function pick_total_column(string $table): ?string {
  return pick_column($table, ['total_harga','total','grand_total','nominal','jumlah_harga']);
}
function pick_customer_column(string $table): ?string {
  return pick_column($table, ['customer','pelanggan','nama_customer','nama_pelanggan','buyer','client']);
}
function pick_supplier_column(string $table): ?string {
  return pick_column($table, ['supplier_pt','supplier','pt','nama_supplier','vendor']);
}
function pick_name_column_stok(): ?string {
  return pick_column('stok', ['nama_kain','nama','nama_produk','produk','nama_barang','kain']);
}
function pick_qty_column_stok(): ?string {
  return pick_column('stok', ['qty','jumlah','quantity','stok','stock_qty','qty_total']);
}
function sum_column_fallback(string $table, array $candidates): float {
  $col = pick_column($table,$candidates);
  if (!$col) return 0.0;
  $row = fetch("SELECT COALESCE(SUM($col),0) AS s FROM $table");
  return (float)($row['s'] ?? 0);
}
function top_stok_rows(int $limit=5): array {
  if (!table_exists('stok')) return [];
  $nameCol = pick_name_column_stok();
  $qtyCol  = pick_qty_column_stok();
  if (!$nameCol || !$qtyCol) return [];
  return fetchAll("SELECT $nameCol AS label, $qtyCol AS value FROM stok ORDER BY $qtyCol DESC LIMIT ?", [$limit]);
}

/* ===== Misc helpers ===== */
/**
 * Reset permission cache for a user (panggil jika role/perm diubah)
 */
function reset_user_permissions_cache(int $user_id): void {
  if (isset($_SESSION['_perms_cache'][$user_id])) unset($_SESSION['_perms_cache'][$user_id]);
}

/* ===== End of config ===== */
