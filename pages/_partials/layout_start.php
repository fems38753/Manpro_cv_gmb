<?php
// pages/_partials/layout_start.php — robust & safe
require_once __DIR__ . '/../../config/database.php';
require_login(); // pastikan sudah login

/* ==============================
   Helper avatar_url (fallback + normalisasi path)
   ============================== */
if (!function_exists('avatar_url')) {
  function avatar_url(?string $path, string $label = 'U'): string {
    $fallback = "data:image/svg+xml;utf8," . rawurlencode(
      '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48">
         <circle cx="24" cy="24" r="24" fill="#E1C5AA"/>
         <text x="50%" y="56%" text-anchor="middle" font-size="18" font-family="Arial" fill="#59382B">'.$label.'</text>
       </svg>'
    );

    if (!$path) return $fallback;

    $p = str_replace('\\', '/', trim($path));
    $app = str_replace('\\', '/', APP_DIR . '/');
    if (strpos($p, $app) === 0) $p = substr($p, strlen($app));
    if (preg_match('/^[A-Za-z]:\\//', $p)) {
      $pos = strpos($p, 'uploads/');
      if ($pos !== false) $p = substr($p, $pos);
    }
    $p = ltrim($p, '/');
    $abs = APP_DIR . '/' . str_replace('/', DIRECTORY_SEPARATOR, $p);
    if (!is_file($abs)) return $fallback;
    return asset_url($p) . '?v=' . urlencode(@filemtime($abs));
  }
}

/* ==============================
   Ambil user dari session (fallback ke DB bila perlu)
   ============================== */
$sessionUser = $_SESSION['user'] ?? [];

/* Deteksi kemungkinan nama kolom di tabel users agar query aman */
$user_pk_col    = pick_column('users', ['user_id','id','uid']) ?: 'user_id';
$name_col       = pick_column('users', ['nama','name','full_name','fullname']) ?: null;
$username_col   = pick_column('users', ['username','user_name','login']) ?: null;
$email_col      = pick_column('users', ['email','email_address','email_addr']) ?: null;
$role_col       = pick_column('users', ['role','user_role','level','role_name']) ?: null;
$avatar_col     = pick_column('users', ['avatar_path','avatar','photo','profile_pic']) ?: null;
$theme_col      = pick_column('users', ['theme','user_theme','pref_theme']) ?: null;

/* Apakah kita perlu refresh data user dari DB?
   (Hanya lakukan bila ada user_id di session dan ada field yang kosong)
*/
$need_fetch = false;
if (!empty($sessionUser['user_id'])) {
  // jika ada satu atau lebih informasi penting yang kosong, kita fetch
  if (empty($sessionUser['nama']) || empty($sessionUser['username']) || !array_key_exists('avatar_path', $sessionUser) || !array_key_exists('theme', $sessionUser) || !isset($sessionUser['role'])) {
    $need_fetch = true;
  }
}

$fresh = [];
if ($need_fetch) {
  $uid = (int)$sessionUser['user_id'];
  if ($uid > 0) {
    // Bangun SELECT hanya untuk kolom yang ada
    $select = [];
    $select[] = "u.`$user_pk_col` AS user_id";
    $select[] = $name_col      ? "COALESCE(u.`$name_col`,'') AS nama" : "'' AS nama";
    $select[] = $username_col  ? "COALESCE(u.`$username_col`,'') AS username" : "'' AS username";
    $select[] = $email_col     ? "COALESCE(u.`$email_col`,'') AS email" : "'' AS email";
    $select[] = $role_col      ? "COALESCE(u.`$role_col`,'') AS role" : "'' AS role";
    $select[] = $avatar_col    ? "COALESCE(u.`$avatar_col`,'') AS avatar_path" : "'' AS avatar_path";
    $select[] = $theme_col     ? "COALESCE(u.`$theme_col`,'light') AS theme" : "'light' AS theme";

    $sql = "SELECT " . implode(', ', $select) . " FROM users u WHERE u.`$user_pk_col` = ? LIMIT 1";
    try {
      $fresh = fetch($sql, [$uid]) ?: [];
      // protect: ensure keys exist
      if ($fresh) {
        if (!array_key_exists('avatar_path', $fresh)) $fresh['avatar_path'] = $sessionUser['avatar_path'] ?? null;
        if (!array_key_exists('theme', $fresh)) $fresh['theme'] = $sessionUser['theme'] ?? 'light';
      } else {
        $fresh = [];
      }
    } catch (Throwable $e) {
      // bila query gagal (mis. kolom tidak ada), tinggalkan sesi apa adanya
      $fresh = [];
    }
    // merge ke session jika ada
    if ($fresh) {
      $_SESSION['user'] = array_merge($sessionUser, $fresh);
      $sessionUser = $_SESSION['user'];
    }
  }
}

/* Normalisasi nilai user agar ada key yang dipakai di layout */
$user = $sessionUser;
$user['user_id'] = (int)($user['user_id'] ?? $user['id'] ?? 0);
$user['nama'] = trim((string)($user['nama'] ?? $user['name'] ?? ''));
$user['username'] = trim((string)($user['username'] ?? $user['login'] ?? ''));
$user['email'] = trim((string)($user['email'] ?? ''));
$user['role'] = (string)($user['role'] ?? $user['role_name'] ?? '');
$user['avatar_path'] = $user['avatar_path'] ?? ($user['avatar'] ?? null);
$user['theme'] = in_array($user['theme'] ?? 'light', ['light','dark','system'], true) ? $user['theme'] : 'light';

/* ==============================
   Tentukan nama tampilan & avatar
   ============================== */
$role        = $user['role'] ?? '';
$displayName = trim($user['nama'] ?: $user['username'] ?: $user['email'] ?: 'User');
$label       = strtoupper(substr($displayName, 0, 1));
$avatar_url  = avatar_url($user['avatar_path'] ?? null, $label);

/* ==============================
   Role visibility
   ============================== */
$isDirektur     = ($role !== '' && strcasecmp($role, 'direktur') === 0);
$isKeuangan     = $isDirektur || ($role !== '' && strcasecmp($role, 'staff_keuangan') === 0);
$isOperasional  = $isDirektur || ($role !== '' && strcasecmp($role, 'staff_operasional') === 0);

/* ==============================
   Halaman aktif & tema
   ============================== */
$page_title = $page_title ?? 'Dashboard';
$_active    = $_active ?? '';
$theme = $user['theme'] ?? 'light';
if (!in_array($theme, ['light','dark','system'], true)) $theme = 'light';
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= e($theme) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($page_title) ?> - CV GMB</title>

  <!-- UI utama -->
  <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>?v=<?= urlencode((string)@filemtime(APP_DIR.'/assets/css/style.css')) ?>">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- Hormati override tema di semua halaman -->
  <script>
document.addEventListener('DOMContentLoaded', function () {
  const KEY = 'sb_collapsed';
  const sidebar = document.getElementById('sidebar');
  const btn = document.getElementById('toggleSidebar');
  if (!sidebar || !btn) return;

  // ---------- helpers ----------
  const isCollapsed = () => sidebar.classList.contains('collapsed');

  const refresh = () => {
    const collapsed = isCollapsed();

    // title tooltip saat collapsed
    document.querySelectorAll('.sidebar .menu a').forEach(a => {
      const label = a.querySelector('.label')?.textContent?.trim() || '';
      if (collapsed && label) a.setAttribute('title', label);
      else a.removeAttribute('title');
    });

    // ARIA + ikon panah
    btn.setAttribute('aria-expanded', String(!collapsed));
    btn.setAttribute('aria-label', collapsed ? 'Luaskan sidebar' : 'Ciutkan sidebar');
    const icon = btn.querySelector('i');
    if (icon) {
      icon.classList.toggle('fa-angle-left', !collapsed);
      icon.classList.toggle('fa-angle-right', collapsed);
    }
  };

  const setCollapsed = (state) => {
    sidebar.classList.toggle('collapsed', state);
    localStorage.setItem(KEY, state ? '1' : '0');
    refresh();
  };

  // ---------- apply initial state (tanpa animasi pertama kali) ----------
  const prevTransition = sidebar.style.transition;
  sidebar.style.transition = 'none';
  setCollapsed(localStorage.getItem(KEY) === '1');
  requestAnimationFrame(() => { sidebar.style.transition = prevTransition || ''; });

  // ---------- events ----------
  btn.addEventListener('click', () => setCollapsed(!isCollapsed()));

  // Auto-collapse hanya saat layar sempit; tidak memaksa expand saat kembali lebar
  const mq = window.matchMedia('(max-width: 900px)');
  const applyMQ = () => {
    if (mq.matches) setCollapsed(true);
    else refresh(); // hormati preferensi user
  };
  mq.addEventListener?.('change', applyMQ);
  applyMQ();

  // Pintasan keyboard: Ctrl/Cmd + B toggle, Esc untuk expand cepat
  window.addEventListener('keydown', (e) => {
    const key = (e.key || '').toLowerCase();
    if ((e.ctrlKey || e.metaKey) && key === 'b') { e.preventDefault(); setCollapsed(!isCollapsed()); }
    if (key === 'escape' && isCollapsed()) setCollapsed(false);
  });
});
</script>


  <style>
  *{box-sizing:border-box}
body{margin:0;font-family:"Poppins",system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--text);background:var(--bg)}
.layout{display:flex;min-height:100vh}
.sidebar{
  width:240px;
  background:var(--panel);
  border-right:1px solid var(--line);
  display:flex; flex-direction:column; justify-content:space-between;
  transition:width .2s ease;
  overflow:hidden; /* penting saat collapsed */
}
.sidebar h1{margin:0;padding:18px;font-size:20px;font-weight:700;color:var(--text);border-bottom:1px solid var(--line)}
.menu{padding:8px 10px}
.menu a{display:flex;gap:10px;align-items:center;padding:12px;border-radius:10px;margin:4px 0;color:var(--text);text-decoration:none}
.menu a:hover{background:var(--hover)}
.menu a.active{background:var(--active);color:var(--brand-2);font-weight:600}
.content{flex:1;display:flex;flex-direction:column}
header{background:var(--panel);border-bottom:1px solid var(--line);padding:10px 16px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:5;height: 87px;}
header h2{margin:0;font-size:20px}
.user-chip{display:flex;align-items:center;gap:10px;color:var(--text);text-decoration:none}
.user-chip img{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--line);background:var(--panel)}
.muted{color:var(--muted)}
main{padding:18px;flex:1}
footer{background:var(--panel);border-top:1px solid var(--line);color:var(--muted);font-size:13px;text-align:center;padding:10px}
.btn{display:inline-flex;align-items:center;gap:8px;cursor:pointer;background:var(--brand);color:#fff;border:1px solid transparent;padding:8px 12px;border-radius:10px}
.btn.outline{background:transparent;color:var(--brand-2);border:1px solid var(--sand)}
/* --- Sidebar toggle button --- */
.brand{display:flex; align-items:center; justify-content:space-between}
.sidebar-toggle{
  display:inline-flex; align-items:center; justify-content:center;
  width:32px; height:32px; border:1px solid var(--line);
  background:var(--panel); color:var(--text); border-radius:8px; cursor:pointer;
  transition:transform .2s ease;
}
.sidebar-toggle:focus{outline:2px solid var(--brand-2); outline-offset:2px}

/* --- Transition niceness --- */
.sidebar{width:240px; transition:width .2s ease}
.menu a{transition:background .2s ease, color .2s ease, padding .2s ease}
.menu a i{min-width:22px; text-align:center; font-size:18px}

/* --- Collapsed state --- */
.sidebar.collapsed{width:72px}
.sidebar.collapsed h1 span{display:none}          /* sembunyikan teks "CV GMB." */
.sidebar.collapsed .sidebar-toggle i{transform:rotate(180deg)}  /* arah panah */

.sidebar.collapsed .menu a{
  justify-content:center; gap:0; padding:12px 10px;
}
.sidebar.collapsed .menu a .label{      /* sembunyikan label menu */
  position:absolute; opacity:0; pointer-events:none; width:0; height:0; overflow:hidden;
}

/* Ikon tetap rapi di tengah */
.sidebar.collapsed .menu a i{margin:0}

/* Tooltip sederhana via title attr saat collapsed */
.sidebar.collapsed .menu a[title]{position:relative}
</style>
</head>
<body>
<div class="layout">

  <!-- Sidebar -->
  <!-- Sidebar -->
<aside class="sidebar" id="sidebar">
  <div>
    <h1 class="brand">
      <span>CV GMB.</span>
      <button id="toggleSidebar" class="sidebar-toggle" aria-label="Ciutkan sidebar" title="Ciutkan/luaskan sidebar">
        <i class="fas fa-angle-left"></i>
      </button>
    </h1>

    <div class="menu">
      <a class="<?=($_active==='dashboard'?'active':'')?>" href="<?=asset_url('pages/dashboard/index.php')?>">
        <i class="fas fa-house"></i> <span class="label">Dashboard</span>
      </a>

      <?php if($isKeuangan): ?>
        <a class="<?=($_active==='transaksi'?'active':'')?>" href="<?=asset_url('pages/transaksi/index.php')?>">
          <i class="fas fa-receipt"></i> <span class="label">Transaksi</span>
        </a>
        <a class="<?=($_active==='penjualan'?'active':'')?>" href="<?=asset_url('pages/penjualan/index.php')?>">
          <i class="fas fa-cart-shopping"></i> <span class="label">Penjualan</span>
        </a>
      <?php endif; ?>

      <?php if($isOperasional): ?>
        <a class="<?=($_active==='stok'?'active':'')?>" href="<?=asset_url('pages/stok/index.php')?>">
          <i class="fas fa-boxes-stacked"></i> <span class="label">Pengelolaan Stok</span>
        </a>
        <a class="<?=($_active==='pembelian'?'active':'')?>" href="<?=asset_url('pages/pembelian/index.php')?>">
          <i class="fas fa-truck"></i> <span class="label">Pembelian</span>
        </a>
      <?php endif; ?>

      <?php if($isDirektur): ?>
        <a class="<?=($_active==='user'?'active':'')?>" href="<?=asset_url('pages/pegawai/index.php')?>">
          <i class="fas fa-users"></i> <span class="label">User</span>
        </a>
      <?php endif; ?>
    </div>
  </div>
  <div class="menu">
    <a href="<?=asset_url('auth/logout.php')?>"><i class="fas fa-right-from-bracket"></i> <span class="label">Logout</span></a>
  </div>
</aside>

  <!-- Konten utama -->
  <div class="content">
    <header>
      <h2><?= e($page_title) ?></h2>
      <a class="user-chip" href="<?= asset_url('pages/account/profile.php') ?>">
        <span class="hi">Hi, <?= e($displayName) ?></span>
        <img src="<?= $avatar_url ?>" alt="avatar">
        <div class="role muted"><?= e(ucwords(str_replace('_',' ', $role))) ?></div>
      </a>
    </header>

    <main>
