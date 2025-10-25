<?php
// auth/login.php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/* Jika sudah login (gunakan helper current_user() jika tersedia) */
if (function_exists('current_user')) {
  if (current_user()) {
    header('Location: ' . asset_url('pages/dashboard/index.php'));
    exit;
  }
} else {
  if (!empty($_SESSION['user']['user_id'])) {
    header('Location: ' . asset_url('pages/dashboard/index.php'));
    exit;
  }
}

/* ---------- Deteksi kolom users (robust) ---------- */
$user_pk_col    = pick_column('users', ['user_id','id','uid']) ?: 'user_id';
$email_col      = pick_column('users', ['email','email_address','email_addr']) ?: null;
$username_col   = pick_column('users', ['username','user_name','login']) ?: null;
$password_col   = pick_column('users', ['password_hash','password','passwd']) ?: null;
$name_col       = pick_column('users', ['nama','name','full_name','fullname']) ?: null;
$role_col       = pick_column('users', ['role','user_role','level','role_name']) ?: null;
$avatar_col     = pick_column('users', ['avatar_path','avatar','photo','profile_pic']) ?: null;
$last_login_col = pick_column('users', ['last_login','last_seen','last_active']) ?: null;

/* ---------- Handle submit ---------- */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $login_id = trim($_POST['email'] ?? '');
  $pass     = $_POST['password'] ?? '';

  if ($login_id === '') $errors[] = 'Email atau Username wajib diisi.';
  if ($pass === '')     $errors[] = 'Password wajib diisi.';

  if (empty($password_col)) $errors[] = 'Kolom password tidak ditemukan di database. Hubungi admin.';

  if (!$errors) {
    // Pilih kolom pencarian: bila input valid email dan ada kolom email → cari di email
    $use_col = null;
    if (filter_var($login_id, FILTER_VALIDATE_EMAIL) && $email_col) {
      $use_col = $email_col;
    } elseif ($username_col) {
      $use_col = $username_col;
    } elseif ($email_col) {
      // input mungkin bukan email, tapi email column ada — coba email anyway
      $use_col = $email_col;
    } else {
      $errors[] = 'Kolom username/email tidak ditemukan pada tabel users.';
    }

    if (!$errors && $use_col) {
      // Build SELECT list
      $select = [];
      $select[] = "u.`$user_pk_col` AS user_id";
      $select[] = $email_col    ? "COALESCE(u.`$email_col`,'') AS email" : "'' AS email";
      $select[] = $username_col ? "COALESCE(u.`$username_col`,'') AS username" : "'' AS username";
      $select[] = $password_col ? "COALESCE(u.`$password_col`,'') AS password_hash" : "'' AS password_hash";
      $select[] = $name_col     ? "COALESCE(u.`$name_col`,'') AS nama" : "'' AS nama";
      $select[] = $role_col     ? "COALESCE(u.`$role_col`,'') AS role" : "'' AS role";
      $select[] = $avatar_col   ? "COALESCE(u.`$avatar_col`,'') AS avatar_path" : "'' AS avatar_path";

      $sql = "SELECT " . implode(', ', $select) . " FROM users u WHERE u.`$use_col` = ? LIMIT 1";
      $row = fetch($sql, [$login_id]);

      if (!$row) {
        $errors[] = 'Email/Username atau password salah.'; // do not reveal which
      } else {
        // verify password
        $hash = (string)($row['password_hash'] ?? '');
        if (!password_verify($pass, $hash)) {
          $errors[] = 'Email/Username atau password salah.';
        } else {
          // login sukses → isi session sesuai konvensi aplikasi
          $_SESSION['user'] = [
            'user_id'     => (int)$row['user_id'],
            'email'       => $row['email'] ?? null,
            'username'    => $row['username'] ?? null,
            'nama'        => $row['nama'] ?? null,
            'role'        => $row['role'] ?? null,
            'avatar_path' => $row['avatar_path'] ?? null
          ];
          // juga set legacy keys untuk kode lama
          $_SESSION['user_id'] = (int)$row['user_id'];
          if (!empty($row['role'])) $_SESSION['role'] = $row['role'];
          // update last_login bila kolom ada
          if ($last_login_col) {
            query("UPDATE users SET `$last_login_col` = NOW() WHERE `$user_pk_col` = ?", [(int)$row['user_id']]);
          }
          // redirect ke dashboard
          header('Location: ' . asset_url('pages/dashboard/index.php'));
          exit;
        }
      }
    }
  }
}

/* ---------- Helper escaper ---------- */
function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---------- HTML ---------- */
?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Masuk - CV GMB</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    /* ===== Base layout ===== */
    body{
      margin:0; background:#f5f7fb;
      font-family:Poppins,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      min-height:100vh; overflow:hidden; position:relative;
    }
    .auth-wrap{min-height:100vh;display:grid;place-items:center;padding:24px;position:relative;z-index:2}
    .auth-card{width:100%;max-width:420px;background:#fff;border:1px solid #eee;border-radius:16px;box-shadow:0 6px 24px rgba(0,0,0,.08);padding:22px}
    .brand{display:flex;align-items:center;gap:10px;margin-bottom:8px}
    .brand i{color:#9c6728}
    h1{margin:.2rem 0 1rem 0;font-size:28px}
    .muted{color:#6b7280}
    label{display:block;margin:.4rem 0 .25rem .2rem;font-weight:600;font-size:13px}
    input[type=text],input[type=password]{width:100%;padding:11px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid #e9d9c3;background:#ffbb38;color:#59382B;font-weight:600;cursor:pointer;text-decoration:none}
    .btn:disabled{opacity:.6;cursor:not-allowed}
    .btn.secondary{background:#fff;border:1px solid #eadccd}
    .toolbar{display:flex;justify-content:space-between;align-items:center;margin-top:12px}
    .error{background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;border-radius:10px;padding:10px 12px;margin:10px 0;font-size:14px}
    .foot{text-align:center;margin-top:18px;color:#6b7280;font-size:13px}

    /* ===== Background slideshow (70% transparency) ===== */
    .bg-slot{
      position:fixed; inset:0;
      background-size:cover; background-position:center; background-repeat:no-repeat;
      opacity:0; transition:opacity 500ms ease-in-out; z-index:0;
    }
    .bg-slot.show{ opacity:.80; } /* transparansi 70% saat aktif */
  </style>
</head>
<body>
  <!-- Background slots -->
  <div id="bgA" class="bg-slot"></div>
  <div id="bgB" class="bg-slot"></div>

  <div class="auth-wrap">
    <div class="auth-card">
      <div class="brand"><i class="fas fa-feather"></i><b>CV GMB.</b></div>
      <h1><i class="fas fa-right-to-bracket"></i> Masuk</h1>
      <p class="muted">Login untuk mengakses dashboard.</p>

      <?php if ($errors): ?>
        <div class="error">
          <?php foreach($errors as $e) echo '• '.esc($e).'<br>'; ?>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <label>Email atau Username</label>
        <input type="text" name="email" required value="<?= esc($_POST['email'] ?? '') ?>">

        <label>Password</label>
        <input type="password" name="password" required>

        <div class="toolbar">
          <span class="muted">Belum punya akun? <a href="<?= asset_url('auth/register.php') ?>"><i class="fas fa-user-plus"></i> Daftar</a></span>
          <button class="btn" type="submit">Masuk</button>
        </div>
      </form>

      <div class="foot">© <?= date('Y') ?> CV GMB</div>
    </div>
  </div>

  <script>
    const images = [
      "<?= asset_url('assets/img/batik/parang-kemuning.jpg') ?>",
      "<?= asset_url('assets/img/batik/gunungan-biru.jpg') ?>",
      "<?= asset_url('assets/img/batik/burung-batik.jpg') ?>",
      "<?= asset_url('assets/img/batik/lereng-coklat.jpg') ?>",
      "<?= asset_url('assets/img/batik/kawung-ungu.jpg') ?>",
      "<?= asset_url('assets/img/batik/floral-hitam-emas.jpg') ?>"
    ];
    images.forEach(s=>{const i=new Image();i.src=s;});
    const a=document.getElementById('bgA'), b=document.getElementById('bgB');
    let cur=0,useA=true;
    function setBg(el,src){ el.style.backgroundImage=`url("${src}")`; }
    function tick(){
      const next = images[cur];
      const show = useA ? a : b;
      const hide = useA ? b : a;
      setBg(show,next);
      hide.classList.remove('show');
      void show.offsetWidth;
      show.classList.add('show');
      cur = (cur+1)%images.length; useA=!useA;
    }
    setBg(a, images[0]); a.classList.add('show'); cur=1; useA=false;
    setInterval(tick, 10000);
  </script>
</body>
</html>
