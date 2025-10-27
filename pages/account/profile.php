<?php
// MANPRO/pages/account/profile.php (robust terhadap variasi skema users)
require_once __DIR__ . '/../../config/database.php';
require_login();

/* =========================
   Setup awal & ambil user
   ========================= */
$page_title = 'Akun Saya';
$_active    = 'account';

$uid = (int)($_SESSION['user']['user_id'] ?? 0);
if ($uid <= 0) {
  redirect('auth/login.php');
}

/* ----- DETEKSI KOLOM USERS ----- */
/* Kita akan memilih kolom yang tersedia dan alias ke nama standar:
   user_id, nama, username, email, role, avatar_path, password_hash, theme
*/
$user_pk_col = pick_column('users', ['user_id','id','uid']) ?: 'user_id';
$name_col    = pick_column('users', ['nama','name','full_name','fullname']) ?: null;
$username_col= pick_column('users', ['username','user_name','login','login_name']) ?: null;
$email_col   = pick_column('users', ['email','email_address','email_addr']) ?: null;
$role_col    = pick_column('users', ['role','user_role','level','role_name']) ?: null;
$avatar_col  = pick_column('users', ['avatar_path','avatar','photo','profile_pic']) ?: null;
$password_col= pick_column('users', ['password_hash','password','passwd']) ?: null;
$theme_col   = pick_column('users', ['theme','user_theme','pref_theme']) ?: null;

/* Build SELECT parts: alias semua ke nama standar */
$select = [];
$select[] = "u.`$user_pk_col` AS user_id";
$select[] = $name_col ? "COALESCE(u.`$name_col`,'') AS nama" : "'' AS nama";
$select[] = $username_col ? "COALESCE(u.`$username_col`,'') AS username" : "'' AS username";
$select[] = $email_col ? "COALESCE(u.`$email_col`,'') AS email" : "'' AS email";
$select[] = $role_col ? "COALESCE(u.`$role_col`,'') AS role" : "'' AS role";
$select[] = $avatar_col ? "COALESCE(u.`$avatar_col`,'') AS avatar_path" : "'' AS avatar_path";
$select[] = $password_col ? "COALESCE(u.`$password_col`,'') AS password_hash" : "'' AS password_hash";
$select[] = $theme_col ? "COALESCE(u.`$theme_col`,'light') AS theme" : "'light' AS theme";

/* Ambil user */
$sql = "SELECT " . implode(', ', $select) . " FROM users u WHERE u.`$user_pk_col` = ? LIMIT 1";
$user = fetch($sql, [$uid]);
if (!$user) {
  session_destroy();
  redirect('auth/login.php');
}

/* Short flags whether certain columns exist so we can guard behavior */
$has_password_col = (bool)$password_col;
$has_role_col     = (bool)$role_col;
$has_avatar_col   = (bool)$avatar_col;
$has_theme_col    = (bool)$theme_col;

/* =========================
   Form submit
   ========================= */
$errors = [];
$ok     = (($_GET['msg'] ?? '') === 'ok');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!check_csrf($_POST['csrf'] ?? '')) {
    $errors[] = 'Sesi kadaluarsa. Muat ulang halaman.';
  }

  // ambil input
  $nama     = trim($_POST['nama'] ?? '');
  $username = trim($_POST['username'] ?? '');
  $theme    = $_POST['theme'] ?? ($user['theme'] ?? 'light');

  if ($nama === '')     $errors[] = 'Nama wajib diisi.';
  if ($username === '') $errors[] = 'Username wajib diisi.';
  if (!in_array($theme, ['light','dark','system'], true)) $theme = 'light';

  // cek unik username (gunakan kolom detected)
  if (!$errors) {
    if ($username_col) {
      $q = "SELECT `$user_pk_col` FROM users WHERE `$username_col` = ? AND `$user_pk_col` <> ? LIMIT 1";
      if (fetch($q, [$username, $uid])) $errors[] = 'Username sudah digunakan akun lain.';
    } else {
      // kalau tidak ada kolom username di DB (jarang), skip uniqueness check
    }
  }

  // password opsional: hanya jika ada kolom password
  $oldpass   = $_POST['old_password'] ?? '';
  $newpass   = $_POST['new_password'] ?? '';
  $newpass2  = $_POST['new_password2'] ?? '';
  $want_change_pwd = ($newpass !== '' || $newpass2 !== '');

  if ($want_change_pwd) {
    if (!$has_password_col) {
      $errors[] = 'Fitur ganti password tidak tersedia (kolom password tidak ditemukan).';
    } else {
      if (strlen($newpass) < 6) $errors[] = 'Password baru minimal 6 karakter.';
      if ($newpass !== $newpass2) $errors[] = 'Konfirmasi password baru tidak sama.';
      // verify old password
      if (!password_verify($oldpass, (string)$user['password_hash'])) {
        $errors[] = 'Password lama salah.';
      }
    }
  }

  // upload avatar opsional
  $avatar_path = $user['avatar_path'] ?? null;
  if ($has_avatar_col && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
    [$okUp, $msgUp] = upload_image($_FILES['avatar'], PROFILE_DIR);
    if (!$okUp) {
      $errors[] = 'Upload avatar gagal: ' . $msgUp;
    } else {
      $p   = str_replace('\\','/',$msgUp);
      $app = str_replace('\\','/', APP_DIR . '/');
      if (strpos($p, $app) === 0) $p = substr($p, strlen($app));
      $avatar_path = ltrim($p, '/');

      // hapus avatar lama jika berasal dari uploads/profile
      $old = $user['avatar_path'] ?? null;
      if ($old && str_starts_with($old, 'uploads/profile/')) {
        $absOld = APP_DIR . '/' . str_replace('/', DIRECTORY_SEPARATOR, $old);
        if (is_file($absOld)) @unlink($absOld);
      }
    }
  }

  // simpan ke DB
  if (!$errors) {
    // susun SET dinamis, gunakan detected column names
    $sets = [];
    $args = [];

    // nama
    if ($name_col) {
      $sets[] = "`$name_col` = ?";
      $args[] = $nama;
    } else {
      // aliasing: if DB has no name column, update a column 'nama' doesn't exist — skip
    }

    // username
    if ($username_col) {
      $sets[] = "`$username_col` = ?";
      $args[] = $username;
    }

    // avatar
    if ($has_avatar_col) {
      $sets[] = "`$avatar_col` = ?";
      $args[] = $avatar_path;
    }

    // theme
    if ($has_theme_col) {
      $sets[] = "`$theme_col` = ?";
      $args[] = $theme;
    }

    // password
    if ($want_change_pwd && $has_password_col) {
      $sets[] = "`$password_col` = ?";
      $args[] = password_hash($newpass, PASSWORD_BCRYPT);
    }

    if (empty($sets)) {
      $errors[] = 'Tidak ada kolom yang bisa diperbarui pada tabel users.';
    } else {
      $sql = 'UPDATE users SET ' . implode(', ', $sets) . " WHERE `$user_pk_col` = ?";
      $args[] = $uid;
      query($sql, $args);

      // segarkan sesi (alias key names tetap sama seperti sebelumnya: nama, username, avatar_path, theme)
      $_SESSION['user']['nama'] = $nama;
      $_SESSION['user']['username'] = $username;
      if ($has_avatar_col) $_SESSION['user']['avatar_path'] = $avatar_path;
      if ($has_theme_col)  $_SESSION['user']['theme'] = $theme;

      redirect('pages/account/profile.php?msg=ok');
    }
  }
}

/* =========================
   Siapkan URL avatar preview
   ========================= */
$label      = strtoupper(substr(($user['nama'] ?: ($user['username'] ?? 'U')), 0, 1));
$avatar_url = avatar_url($user['avatar_path'] ?? null, $label);

/* =========================
   Render
   ========================= */
include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card" style="max-width:900px">
  <h2>Akun Saya</h2>

  <?php if((($_GET['msg'] ?? '') === 'ok')): ?>
    <div class="alert" style="background:#e9f9ee;border:1px solid #bfe6cc;color:#256a3b;border-radius:10px;padding:10px 12px;margin:10px 0">
      Perubahan disimpan.
    </div>
  <?php endif; ?>

  <?php if($errors): ?>
    <div class="alert" style="background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;border-radius:10px;padding:10px 12px;margin:10px 0">
      <?php foreach($errors as $e) echo '• ' . e($e) . '<br>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <!-- Avatar -->
    <div style="display:flex;gap:18px;align-items:center;margin:10px 0 16px 0">
      <img src="<?= $avatar_url ?>" alt="avatar"
           style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid var(--line)">
      <div>
        <div class="muted">Avatar (opsional)</div>
        <?php if ($has_avatar_col): ?>
          <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" onchange="previewAvatar(event)">
          <img id="previewAvatar" style="display:none;margin-top:8px;width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid var(--line)">
        <?php else: ?>
          <div class="muted" style="font-size:13px">Kolom avatar tidak ditemukan pada tabel users.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Data dasar -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <label>Nama *</label>
        <input type="text" name="nama" required value="<?= e($_POST['nama'] ?? $user['nama']) ?>">
      </div>
      <div>
        <label>Username *</label>
        <input type="text" name="username" required value="<?= e($_POST['username'] ?? $user['username']) ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Email</label>
        <input type="text" value="<?= e($user['email'] ?? '-') ?>" disabled>
        <div class="muted" style="font-size:12px;margin-top:4px">Email tidak dapat diubah di sini.</div>
      </div>
      <div>
        <label>Role</label>
        <?php if ($has_role_col): ?>
          <input type="text" value="<?= e($_POST['role'] ?? $user['role']) ?>" disabled>
          <div class="muted" style="font-size:12px;margin-top:4px">Role hanya bisa diubah oleh Direktur di menu <b>User</b>.</div>
        <?php else: ?>
          <input type="text" value="-" disabled>
          <div class="muted" style="font-size:12px;margin-top:4px">Kolom role tidak ditemukan pada tabel users.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Ganti Password -->
    <fieldset style="margin-top:16px;border:1px dashed var(--sand);border-radius:12px;padding:12px">
      <legend class="muted" style="padding:0 6px">Ganti Password (opsional)</legend>
      <?php if (!$has_password_col): ?>
        <div class="muted">Fitur ganti password tidak tersedia karena kolom password tidak ditemukan.</div>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div>
            <label>Password Lama</label>
            <input type="password" name="old_password" autocomplete="current-password">
          </div>
          <div>
            <label>Password Baru</label>
            <input type="password" name="new_password" autocomplete="new-password">
          </div>
        </div>
        <div style="margin-top:12px">
          <label>Ulangi Password Baru</label>
          <input type="password" name="new_password2" autocomplete="new-password">
        </div>
        <div class="muted" style="font-size:12px;margin-top:6px">Kosongkan kolom password jika tidak ingin mengubah.</div>
      <?php endif; ?>
    </fieldset>

    <!-- Preferensi Tema -->
    <?php $theme_now = $_POST['theme'] ?? ($user['theme'] ?? 'light'); ?>
    <fieldset style="margin-top:16px;border:1px dashed var(--sand);border-radius:12px;padding:12px">
      <legend class="muted" style="padding:0 6px">Preferensi Tema</legend>
      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <label><input type="radio" name="theme" value="light"  <?= $theme_now==='light'  ? 'checked' : '' ?>> Light</label>
        <label><input type="radio" name="theme" value="dark"   <?= $theme_now==='dark'   ? 'checked' : '' ?>> Dark</label>
        <label><input type="radio" name="theme" value="system" <?= $theme_now==='system' ? 'checked' : '' ?>> System (ikut OS)</label>

        <button type="button" id="toggleTheme" class="btn outline no-print" style="margin-left:auto">
          <i class="fa-solid fa-moon"></i> <span class="label">Dark</span>
        </button>
      </div>
      <small class="muted">Tombol di kanan mengubah tema <i>instan</i> di browser ini. Untuk menyimpan ke akun, klik <b>Simpan</b>.</small>
    </fieldset>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn" type="submit"><i class="fas fa-save"></i> Simpan</button>
      <a class="btn outline" href="<?= asset_url('pages/dashboard/index.php') ?>"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>
  </form>
</div>

<script>
function previewAvatar(e){
  const [file] = e.target.files || [];
  const img = document.getElementById('previewAvatar');
  if(file){ img.src = URL.createObjectURL(file); img.style.display='block'; }
  else { img.removeAttribute('src'); img.style.display='none'; }
}

(function(){
  const root  = document.documentElement;
  const btn   = document.getElementById('toggleTheme'); // tombol cepat
  const label = btn?.querySelector('.label');
  const icon  = btn?.querySelector('i');
  const radios = document.querySelectorAll('input[name="theme"]');

  let mq = null;   // MediaQueryList untuk "system"
  let onMQ = null; // handler perubahan OS

  function uiRefresh(){
    const cur = root.getAttribute('data-theme') || 'light';
    const isDark = (cur === 'dark');
    if (label) label.textContent = isDark ? 'Light' : 'Dark';
    if (icon) { icon.classList.toggle('fa-moon', !isDark); icon.classList.toggle('fa-sun', isDark); }
  }

  function stopSystemListener(){
    if (mq && onMQ) { mq.removeEventListener?.('change', onMQ); }
    mq = null; onMQ = null;
  }

  function setTheme(mode){
    if (mode === 'system') {
      // Hapus override dan ikut OS
      localStorage.removeItem('theme_override');
      stopSystemListener();
      mq = window.matchMedia('(prefers-color-scheme: dark)');
      onMQ = () => root.setAttribute('data-theme', mq.matches ? 'dark' : 'light');
      onMQ(); // apply sekarang
      mq.addEventListener?.('change', onMQ);
    } else if (mode === 'light' || mode === 'dark') {
      stopSystemListener();
      localStorage.setItem('theme_override', mode);
      root.setAttribute('data-theme', mode);
    }
    // Sinkronkan radio (agar jelas di UI)
    const r = document.querySelector(`input[name="theme"][value="${mode}"]`);
    if (r) r.checked = true;
    uiRefresh();
    // Optional hook
    if (typeof window.refreshChartsTheme === 'function') window.refreshChartsTheme();
  }

  // Inisialisasi: hormati state yang sudah dipasang di layout_start.php
  uiRefresh();

  // --- Event radio (Light/Dark/System) ---
  radios.forEach(r => {
    r.addEventListener('change', (e) => {
      const v = e.target.value;
      setTheme(v);
    });
  });

  // --- Tombol cepat (flip Light/Dark) ---
  if (btn) {
    btn.addEventListener('click', () => {
      const cur = root.getAttribute('data-theme') || 'light';
      const next = (cur === 'dark') ? 'light' : 'dark';
      setTheme(next); // ini otomatis memindahkan radio dari "system" ke mode yang dipilih
    });
  }

  // Jika saat halaman dibuka radio sudah pada "system", pastikan override dibersihkan sekarang juga
  const checked = document.querySelector('input[name="theme"]:checked')?.value;
  if (checked === 'system') setTheme('system');
})();
</script>


<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
