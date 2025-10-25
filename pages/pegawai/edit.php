<?php
// MANPRO/pages/pegawai/edit.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur']); // hanya direktur

$_active    = 'user';
$page_title = 'Edit Akun Pegawai';

// detect columns
$user_pk_col    = pick_column('users', ['user_id','id','uid']) ?: 'user_id';
$name_col       = pick_column('users', ['nama','name','full_name','fullname']) ?: null;
$email_col      = pick_column('users', ['email','email_address','email_addr']) ?: null;
$username_col   = pick_column('users', ['username','user_name','login']) ?: null;
$role_col       = pick_column('users', ['role','user_role','level','role_name']) ?: null;
$avatar_col     = pick_column('users', ['avatar_path','avatar','photo','profile_pic']) ?: null;
$password_col   = pick_column('users', ['password_hash','password','passwd']) ?: null;

/* Ambil ID user */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('pages/pegawai/index.php');

// Ambil data user (alias ke nama standar)
$select = [];
$select[] = "u.`$user_pk_col` AS user_id";
$select[] = $name_col ? "COALESCE(u.`$name_col`,'') AS nama" : "'' AS nama";
$select[] = $email_col ? "COALESCE(u.`$email_col`,'') AS email" : "'' AS email";
$select[] = $username_col ? "COALESCE(u.`$username_col`,'') AS username" : "'' AS username";
$select[] = $role_col ? "COALESCE(u.`$role_col`,'') AS role" : "'' AS role";
$select[] = $avatar_col ? "COALESCE(u.`$avatar_col`,'') AS avatar_path" : "'' AS avatar_path";

$u = fetch("SELECT " . implode(', ', $select) . " FROM users u WHERE u.`$user_pk_col` = ? LIMIT 1", [$id]);
if (!$u) redirect('pages/pegawai/index.php?msg=Data+tidak+ditemukan');

$old_avatar = $u['avatar_path'] ?? null;
$err = [];
$ok  = (($_GET['msg'] ?? '') === 'ok');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!check_csrf($_POST['csrf'] ?? '')) $err[] = 'Sesi kadaluarsa.';

  $nama     = trim($_POST['nama'] ?? $u['nama']);
  $email    = trim($_POST['email'] ?? $u['email']);
  $username = trim($_POST['username'] ?? $u['username']);
  $role     = trim($_POST['role'] ?? $u['role']);

  if ($name_col && $nama === '') $err[] = 'Nama wajib diisi.';
  if ($username_col && $username === '') $err[] = 'Username wajib diisi.';
  if ($email_col && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err[] = 'Format email tidak valid.';

  // Role validation only if role column exists
  $valid_roles = ['direktur','staff_keuangan','staff_operasional'];
  if ($role_col && !in_array($role, $valid_roles, true)) $err[] = 'Role tidak valid.';

  // Unique username check
  if (!$err && $username_col) {
    $exists = fetch("SELECT `$user_pk_col` FROM users WHERE `$username_col` = ? AND `$user_pk_col` <> ? LIMIT 1", [$username, $id]);
    if ($exists) $err[] = 'Username sudah dipakai akun lain.';
  }

  // Password change (optional)
  $newpass  = $_POST['new_password'] ?? '';
  $newpass2 = $_POST['new_password2'] ?? '';
  $pwd_sql = ''; $pwd_param = [];
  if ($newpass !== '' || $newpass2 !== '') {
    if (!$password_col) $err[] = 'Kolom password tidak tersedia di DB.';
    else {
      if (strlen($newpass) < 6) $err[] = 'Password baru minimal 6 karakter.';
      if ($newpass !== $newpass2) $err[] = 'Konfirmasi password tidak sama.';
      if (!$err) {
        $pwd_sql = ", `$password_col` = ?";
        $pwd_param = [ password_hash($newpass, PASSWORD_BCRYPT) ];
      }
    }
  }

  // Avatar upload (optional)
  $avatar_path = null;
  if ($avatar_col && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
    [$okUp,$msgUp] = upload_image($_FILES['avatar'], PROFILE_DIR);
    if (!$okUp) $err[] = 'Upload avatar gagal: '.$msgUp;
    else {
      $p = str_replace('\\','/',$msgUp);
      $app = str_replace('\\','/', APP_DIR . '/');
      if (strpos($p,$app)===0) $p = substr($p, strlen($app));
      $avatar_path = ltrim($p,'/');
      // remove old avatar if under uploads/profile
      if (!empty($old_avatar) && str_starts_with($old_avatar,'uploads/profile/')) {
        $absOld = APP_DIR . '/' . str_replace('/', DIRECTORY_SEPARATOR, $old_avatar);
        if (is_file($absOld)) @unlink($absOld);
      }
    }
  }

  if (!$err) {
    // compose update dynamically
    $sets = [];
    $args = [];
    if ($name_col)    { $sets[] = "`$name_col` = ?";    $args[] = $nama; }
    if ($email_col)   { $sets[] = "`$email_col` = ?";   $args[] = ($email !== '') ? $email : null; }
    if ($username_col){ $sets[] = "`$username_col` = ?"; $args[] = $username; }
    if ($role_col)    { $sets[] = "`$role_col` = ?";    $args[] = $role; }
    if ($avatar_col)  { $sets[] = "`$avatar_col` = ?";  $args[] = $avatar_path !== null ? $avatar_path : $old_avatar; }
    if ($pwd_sql !== '') { $sets[] = "`$password_col` = ?"; $args[] = $pwd_param[0]; }

    if (empty($sets)) {
      $err[] = 'Tidak ada kolom yang bisa disimpan pada DB.';
    } else {
      $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE `$user_pk_col` = ?";
      $args[] = $id;
      query($sql, $args);
      redirect('pages/pegawai/edit.php?id='.$id.'&msg=ok');
    }
  }
}

// Layout
$has_start = is_file(__DIR__ . '/../_partials/layout_start.php');
$has_end   = is_file(__DIR__ . '/../_partials/layout_end.php');
if ($has_start) include __DIR__ . '/../_partials/layout_start.php';

// avatar preview url
$label = strtoupper(substr(($u['nama'] ?: ($u['username'] ?? 'U')), 0, 1));
$form_avatar_url = avatar_url($u['avatar_path'] ?? null, $label);
?>
<?php if(!$has_start): ?><!doctype html><html><head><meta charset="utf-8"><title><?= e($page_title) ?></title></head><body><?php endif; ?>

<div class="card" style="max-width:900px">
  <h2>Edit Akun Pegawai</h2>

  <?php if($ok): ?><div class="alert">Perubahan disimpan.</div><?php endif; ?>
  <?php if($err): ?><div class="alert error"><?php foreach($err as $e) echo '• '.e($e).'<br>'; ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:flex;gap:18px;align-items:center;margin:10px 0">
      <img src="<?= $form_avatar_url ?>" alt="avatar" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid #eee">
      <div>
        <div class="muted">Avatar (opsional)</div>
        <?php if ($avatar_col): ?>
          <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" onchange="previewAvatar(event)">
          <img id="previewAvatar" style="display:none;margin-top:8px;width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid #eee">
        <?php else: ?>
          <div class="muted">Kolom avatar tidak tersedia di DB.</div>
        <?php endif; ?>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div><label>Nama *</label><input type="text" name="nama" required value="<?= e($_POST['nama'] ?? $u['nama']) ?>"></div>
      <div><label>Email</label><input type="text" name="email" value="<?= e($_POST['email'] ?? $u['email']) ?>"></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
      <div><label>Username *</label><input type="text" name="username" required value="<?= e($_POST['username'] ?? $u['username']) ?>"></div>
      <div>
        <label>Role *</label>
        <select name="role" required>
          <?php $rval = $_POST['role'] ?? $u['role']; ?>
          <option value="direktur" <?= $rval==='direktur'?'selected':'' ?>>Direktur</option>
          <option value="staff_keuangan" <?= $rval==='staff_keuangan'?'selected':'' ?>>Staff Keuangan</option>
          <option value="staff_operasional" <?= $rval==='staff_operasional'?'selected':'' ?>>Staff Operasional</option>
        </select>
      </div>
    </div>

    <fieldset style="margin-top:16px;border:1px dashed #eadccd;padding:12px;border-radius:12px">
      <legend class="muted">Setel Ulang Password (opsional)</legend>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div><label>Password Baru</label><input type="password" name="new_password" placeholder="Minimal 6 karakter"></div>
        <div><label>Ulangi Password Baru</label><input type="password" name="new_password2"></div>
      </div>
    </fieldset>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn" type="submit">Simpan</button>
      <a class="btn outline" href="<?= asset_url('pages/pegawai/index.php') ?>">Kembali</a>
    </div>
  </form>
</div>

<script>
function previewAvatar(e){
  const [file] = e.target.files || [];
  const img = document.getElementById('previewAvatar');
  if(file){ img.src = URL.createObjectURL(file); img.style.display='block'; } else { img.removeAttribute('src'); img.style.display='none'; }
}
</script>

<?php
if ($has_end) include __DIR__ . '/../_partials/layout_end.php';
else echo '</body></html>';
