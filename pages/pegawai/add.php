<?php
// MANPRO/pages/pegawai/add.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur']); // hanya direktur boleh akses

$_active='user'; $page_title='Tambah Akun';
$err=[];

/* Deteksi kolom users agar robust */
$user_pk_col    = pick_column('users',['user_id','id','uid']) ?: 'user_id';
$name_col       = pick_column('users',['nama','name','full_name','fullname']) ?: null;
$email_col      = pick_column('users',['email','email_address','email_addr']) ?: null;
$username_col   = pick_column('users',['username','user_name','login']) ?: null;
$role_col       = pick_column('users',['role','user_role','level','role_name']) ?: null;
$avatar_col     = pick_column('users',['avatar_path','avatar','photo','profile_pic']) ?: null;
$password_col   = pick_column('users',['password_hash','password','passwd']) ?: null;

/* Jika kolom password tidak ada, tidak bisa membuat akun dengan password -> must error */
if (!$password_col) {
  $err[] = 'Kolom password tidak ditemukan pada tabel users — tidak bisa membuat akun baru.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!check_csrf($_POST['csrf'] ?? '')) $err[]='Sesi kadaluarsa.';

  $nama     = trim($_POST['nama'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $username = trim($_POST['username'] ?? '');
  $role     = $_POST['role'] ?? 'staff_keuangan';
  $pass1    = $_POST['password'] ?? '';
  $pass2    = $_POST['password2'] ?? '';

  // Validasi minimal: nama & username & password (only if those columns exist)
  if ($name_col && $nama === '') $err[] = 'Nama wajib diisi.';
  if ($username_col && $username === '') $err[] = 'Username wajib diisi.';
  if ($password_col && strlen($pass1) < 6) $err[] = 'Password minimal 6 karakter.';
  if ($password_col && $pass1 !== $pass2) $err[] = 'Konfirmasi password tidak sama.';

  // Email required only if column exists
  if ($email_col) {
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err[] = 'Email tidak valid.';
  }

  // role validation only if column exists
  $valid_roles = ['direktur','staff_keuangan','staff_operasional'];
  if ($role_col) {
    if (!in_array($role, $valid_roles, true)) $role = 'staff_keuangan';
  } else {
    // jika role column tidak ada, ignore role from form
    $role = null;
  }

  // Uniqueness checks only if columns exist
  if (!$err) {
    if ($email_col && $email !== '' && fetch("SELECT `$user_pk_col` FROM users WHERE `$email_col` = ? LIMIT 1", [$email])) {
      $err[] = 'Email sudah dipakai.';
    }
    if ($username_col && $username !== '' && fetch("SELECT `$user_pk_col` FROM users WHERE `$username_col` = ? LIMIT 1", [$username])) {
      $err[] = 'Username sudah dipakai.';
    }
  }

  // Upload avatar only if avatar_col exists
  $avatar_path = null;
  if ($avatar_col && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
    [$ok_up,$msg_up] = upload_image($_FILES['avatar'], PROFILE_DIR);
    if (!$ok_up) $err[] = 'Upload avatar gagal: '.$msg_up;
    else {
      // normalize relative path
      $p = str_replace('\\','/',$msg_up);
      $app = str_replace('\\','/', APP_DIR . '/');
      if (strpos($p,$app) === 0) $p = substr($p, strlen($app));
      $avatar_path = ltrim($p,'/');
    }
  }

  if (!$err) {
    // build insert dynamically based on available columns
    $cols = []; $marks = []; $vals = [];

    if ($email_col)    { $cols[] = "`$email_col`";    $marks[] = '?'; $vals[] = $email ?: null; }
    if ($username_col) { $cols[] = "`$username_col`"; $marks[] = '?'; $vals[] = $username ?: null; }
    if ($password_col) { $cols[] = "`$password_col`"; $marks[] = '?'; $vals[] = password_hash($pass1, PASSWORD_BCRYPT); }
    if ($role_col)     { $cols[] = "`$role_col`";     $marks[] = '?'; $vals[] = $role ?: null; }
    if ($avatar_col)   { $cols[] = "`$avatar_col`";   $marks[] = '?'; $vals[] = $avatar_path ?: null; }
    if ($name_col)     { $cols[] = "`$name_col`";     $marks[] = '?'; $vals[] = $nama ?: null; }

    // Always include created_at / updated_at columns if exist? Better to let DB default timestamps.
    if (empty($cols)) {
      $err[] = 'Tidak ada kolom users yang bisa diisi pada database.';
    } else {
      $sql = "INSERT INTO users (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $marks) . ")";
      query($sql, $vals);
      redirect('pages/pegawai/index.php?msg=Akun+berhasil+ditambahkan');
    }
  }
}

/* Render form (uses e() helper) */
$has_start = is_file(__DIR__ . '/../_partials/layout_start.php');
$has_end   = is_file(__DIR__ . '/../_partials/layout_end.php');
if ($has_start) include __DIR__ . '/../_partials/layout_start.php';
?>
<?php if(!$has_start): ?><!doctype html><html><head><meta charset="utf-8"><title><?=e($page_title)?></title></head><body><?php endif; ?>

<div class="card" style="max-width:900px">
  <h2>Tambah Akun</h2>
  <?php if($err): ?><div class="alert error"><?php foreach($err as $e) echo '<div>• '.e($e).'</div>'; ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div><label>Nama <?= $name_col ? '*' : '' ?></label>
        <input type="text" name="nama" <?= $name_col ? 'required':'' ?> value="<?= e($_POST['nama']??'') ?>">
      </div>

      <div>
        <label>Role</label>
        <?php if ($role_col): ?>
        <select name="role">
          <option value="direktur" <?= (($_POST['role']??'')==='direktur')?'selected':'' ?>>Direktur</option>
          <option value="staff_keuangan" <?= (($_POST['role']??'')==='staff_keuangan')?'selected':'' ?>>Staff Keuangan</option>
          <option value="staff_operasional" <?= (($_POST['role']??'')==='staff_operasional')?'selected':'' ?>>Staff Operasional</option>
        </select>
        <?php else: ?>
        <select disabled><option>Role tidak tersedia di DB</option></select>
        <?php endif; ?>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Email <?= $email_col ? '*' : '' ?></label>
        <input type="email" name="email" <?= $email_col ? 'required':'' ?> value="<?= e($_POST['email']??'') ?>">
      </div>

      <div>
        <label>Username <?= $username_col ? '*' : '' ?></label>
        <input type="text" name="username" <?= $username_col ? 'required':'' ?> value="<?= e($_POST['username']??'') ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
      <div><label>Password *</label><input type="password" name="password" required></div>
      <div><label>Ulangi Password *</label><input type="password" name="password2" required></div>
    </div>

    <?php if ($avatar_col): ?>
    <div style="margin-top:12px"><label>Avatar (opsional)</label><input type="file" name="avatar" accept="image/png,image/jpeg,image/webp"></div>
    <?php endif; ?>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn" type="submit">Simpan</button>
      <a class="btn outline" href="<?= asset_url('pages/pegawai/index.php') ?>">Batal</a>
    </div>
  </form>
</div>

<?php if ($has_end) include __DIR__ . '/../_partials/layout_end.php'; else echo '</body></html>'; ?>
