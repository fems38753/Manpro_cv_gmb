<?php
// MANPRO/pages/pegawai/index.php (robust terhadap variasi kolom users)
require_once __DIR__ . '/../../config/database.php';
require_login();

$_active    = 'user';
$page_title = 'Data Pegawai';

/* ============ DETEKSI KOLOM PENTING DI users ============ */
$user_pk_col   = pick_column('users', ['user_id','id','uid']) ?: 'user_id';
$name_col      = pick_column('users', ['nama','name','full_name','fullname']) ?: null;
$username_col  = pick_column('users', ['username','user_name','login']) ?: null;
$email_col     = pick_column('users', ['email','email_address','email_addr']) ?: null;
$role_col      = pick_column('users', ['role','user_role','level','role_name']) ?: null;
$avatar_col    = pick_column('users', ['avatar_path','avatar','photo','profile_pic']) ?: null;
$last_login_col= pick_column('users', ['last_login','last_seen','last_active']) ?: null;
$created_col   = pick_column('users', ['created_at','created','date_created','created_on']) ?: null;

/* ============ Filter & Pencarian ============ */
$q    = trim($_GET['q'] ?? '');
$role = trim($_GET['role'] ?? '');

$where = []; $p = [];

// searchable columns: try name, email, username if available
$searchCols = [];
if ($name_col) $searchCols[] = "COALESCE(u.`$name_col`,'')";
if ($email_col) $searchCols[] = "COALESCE(u.`$email_col`,'')";
if ($username_col) $searchCols[] = "COALESCE(u.`$username_col`,'')";

if ($q !== '' && $searchCols) {
  // build "(col1 LIKE ? OR col2 LIKE ? ...)"
  $conds = [];
  foreach ($searchCols as $c) {
    $conds[] = "$c LIKE ?";
    $p[] = "%$q%";
  }
  $where[] = '(' . implode(' OR ', $conds) . ')';
}

// role filter only if role column exists
if ($role !== '' && $role_col && in_array($role, ['direktur','staff_keuangan','staff_operasional'], true)) {
  $where[] = "u.`$role_col` = ?";
  $p[] = $role;
}

$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ============ SELECT DYNAMIC ============ */
$select = [];
$select[] = "u.`$user_pk_col` AS user_id";
$select[] = $name_col ? "COALESCE(u.`$name_col`,'') AS nama" : "'' AS nama";
$select[] = $email_col ? "COALESCE(u.`$email_col`,'') AS email" : "'' AS email";
$select[] = $username_col ? "COALESCE(u.`$username_col`,'') AS username" : "'' AS username";
$select[] = $role_col ? "COALESCE(u.`$role_col`,'') AS role" : "'' AS role";
$select[] = $avatar_col ? "COALESCE(u.`$avatar_col`,'') AS avatar_path" : "'' AS avatar_path";
$select[] = $last_login_col ? "COALESCE(u.`$last_login_col`,'') AS last_login" : "NULL AS last_login";
$select[] = $created_col ? "COALESCE(u.`$created_col`,'') AS created_at" : "NULL AS created_at";

/* ============ Ambil data ============ */
$sql  = "SELECT " . implode(', ', $select) . " FROM users u $wsql ORDER BY " .
        ($created_col ? "u.`$created_col` DESC" : "u.`$user_pk_col` DESC") . " LIMIT 500";
$rows = fetchAll($sql, $p);

/* ============ Counts (safe) ============ */
$total = (int)(fetch("SELECT COUNT(*) c FROM users")['c'] ?? 0);

$dir_count = $keu_count = $ops_count = 0;
if ($role_col) {
  $dir_count = (int)(fetch("SELECT COUNT(*) c FROM users WHERE `$role_col`='direktur'")['c'] ?? 0);
  $keu_count = (int)(fetch("SELECT COUNT(*) c FROM users WHERE `$role_col`='staff_keuangan'")['c'] ?? 0);
  $ops_count = (int)(fetch("SELECT COUNT(*) c FROM users WHERE `$role_col`='staff_operasional'")['c'] ?? 0);
}

/* ============ Permission: is director (use session role if available) ============ */
$is_director = (($_SESSION['user']['role'] ?? '') === 'direktur');

/* ============ Layout ============ */
$has_start = is_file(__DIR__ . '/../_partials/layout_start.php');
$has_end   = is_file(__DIR__ . '/../_partials/layout_end.php');
if ($has_start) include __DIR__ . '/../_partials/layout_start.php';
?>
<?php if(!$has_start): ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?= e($page_title) ?></title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
</head>
<body class="container"><main class="content">
<?php endif; ?>

<div class="card">
  <h2 style="margin-bottom:12px">Data Pegawai</h2>

  <div class="kpi">
    <div class="card"><div class="muted">Total Akun</div><h1><?= number_format($total) ?></h1></div>
    <div class="card"><div class="muted">Direktur</div><h1><?= number_format($dir_count) ?></h1></div>
    <div class="card"><div class="muted">Staff Keuangan</div><h1><?= number_format($keu_count) ?></h1></div>
    <div class="card"><div class="muted">Staff Operasional</div><h1><?= number_format($ops_count) ?></h1></div>
  </div>

  <form method="get" class="no-print" style="display:flex;gap:10px;align-items:center;margin:12px 0;flex-wrap:wrap">
    <input style="min-width:280px" type="text" name="q" placeholder="Cari nama / email / username..." value="<?= e($q) ?>">

    <?php if ($role_col): ?>
      <select name="role">
        <option value="">Semua Role</option>
        <option value="direktur"          <?= $role==='direktur'?'selected':'' ?>>Direktur</option>
        <option value="staff_keuangan"    <?= $role==='staff_keuangan'?'selected':'' ?>>Staff Keuangan</option>
        <option value="staff_operasional" <?= $role==='staff_operasional'?'selected':'' ?>>Staff Operasional</option>
      </select>
    <?php else: ?>
      <select name="role" disabled>
        <option value="">Role tidak tersedia</option>
      </select>
    <?php endif; ?>

    <button class="btn" type="submit"><i class="fas fa-filter"></i> Tampilkan</button>

    <?php if($is_director): ?>
      <a class="btn" href="<?= asset_url('pages/pegawai/add.php') ?>"><i class="fas fa-user-plus"></i> Tambah Akun</a>
    <?php endif; ?>
  </form>

  <table class="table">
    <thead>
      <tr>
        <th style="width:64px">Foto</th>
        <th>Nama</th>
        <th>Email</th>
        <th>Username</th>
        <th>Role</th>
        <th>Last Login</th>
        <th>Dibuat</th>
        <?php if($is_director): ?><th class="no-print" style="width:110px">Aksi</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="<?= $is_director?8:7 ?>" class="muted">Belum ada data.</td></tr>
      <?php endif; ?>

      <?php foreach($rows as $r): ?>
        <?php
          // prepare avatar url
          $nm    = trim($r['nama'] ?? $r['username'] ?? 'U');
          $label = strtoupper(substr($nm, 0, 1));
          $pp    = avatar_url($r['avatar_path'] ?? null, $label);

          // display role fallback
          $role_display = $r['role'] ?? '-';
        ?>
        <tr>
          <td style="width:72px">
            <img src="<?= $pp ?>" alt="Foto <?= e($nm) ?>"
                 style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid #f1e0c8;background:#fff">
          </td>
          <td><div style="font-weight:600"><?= e($r['nama'] ?? '-') ?></div></td>
          <td><?= e($r['email'] ?? '-') ?></td>
          <td><?= e($r['username'] ?? '-') ?></td>
          <td><?= e($role_display) ?></td>
          <td>
            <?php if(!empty($r['last_login'])): ?>
              <?= e(date('d M Y H:i', strtotime($r['last_login']))) ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if(!empty($r['created_at'])): ?>
              <?= e(date('d M Y H:i', strtotime($r['created_at']))) ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>

          <?php if($is_director): ?>
          <td class="no-print actions-td" style="white-space:nowrap">
          
            <a class="icon-btn neutral" title="Edit Pembelian" href="<?=asset_url('pages/pegawai/edit.php?id='.$r['user_id'])?>">
              <i class="fas fa-pen"></i>
            </a>
            
            <form method="post" action="<?=asset_url('pages/pembelian/delete.php')?>" style="display:inline" onsubmit="return confirm('Hapus pembelian ini?')">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?= (int)$r['user_id'] ?>">
              <button class="icon-btn danger" type="submit" title="Hapus Pembelian">
                <i class="fas fa-trash"></i>
              </button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
if($has_end) include __DIR__ . '/../_partials/layout_end.php';
else echo '</main></body></html>';
