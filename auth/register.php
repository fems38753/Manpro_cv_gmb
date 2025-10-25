<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// kalau sudah login, langsung ke dashboard
if (!empty($_SESSION['user']['user_id'])) {
  header('Location: ' . asset_url('pages/dashboard/index.php'));
  exit;
}

$roles = [
  'direktur'          => 'Direktur',
  'staff_keuangan'    => 'Staff Keuangan',
  'staff_operasional' => 'Staff Operasional'
];

$errors = [];
$ok     = isset($_GET['ok']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nama     = trim($_POST['nama'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $username = trim($_POST['username'] ?? '');
  $role     = $_POST['role'] ?? '';
  $pass1    = $_POST['password'] ?? '';
  $pass2    = $_POST['password2'] ?? '';

  if ($nama === '')                               $errors[] = 'Nama wajib diisi.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email tidak valid.';
  if ($username === '')                           $errors[] = 'Username wajib diisi.';
  if (!isset($roles[$role]))                      $errors[] = 'Role tidak valid.';
  if (strlen($pass1) < 6)                         $errors[] = 'Password minimal 6 karakter.';
  if ($pass1 !== $pass2)                          $errors[] = 'Konfirmasi password tidak sama.';

  // unik
  if (!$errors && fetch("SELECT user_id FROM users WHERE email=?",    [$email]))    $errors[] = 'Email sudah digunakan.';
  if (!$errors && fetch("SELECT user_id FROM users WHERE username=?", [$username])) $errors[] = 'Username sudah digunakan.';

  if (!$errors) {
    $hash = password_hash($pass1, PASSWORD_BCRYPT);
    query(
      "INSERT INTO users (email, username, nama, role, password_hash, created_at, update_at)
       VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
      [$email, $username, $nama, $role, $hash]
    );

    header('Location: ' . asset_url('auth/register.php?ok=1'));
    exit;
  }
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Daftar - CV GMB</title>
  <link rel="stylesheet" href="<?=asset_url('assets/css/style.css')?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    body{
      margin:0;background:#f5f7fb;
      font-family:Poppins,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      min-height:100vh;overflow:hidden;position:relative;
    }
    .auth-wrap{min-height:100vh;display:grid;place-items:center;padding:24px;position:relative;z-index:2}
    .auth-card{width:100%;max-width:520px;background:#fff;border:1px solid #eee;border-radius:16px;box-shadow:0 6px 24px rgba(0,0,0,.08);padding:22px}
    .brand{display:flex;align-items:center;gap:10px;margin-bottom:8px}
    .brand i{color:#9c6728}
    h1{margin:.2rem 0 1rem 0;font-size:28px}
    .muted{color:#6b7280}
    label{display:block;margin:.4rem 0 .25rem .2rem;font-weight:600;font-size:13px}
    input[type=text],input[type=email],input[type=password],select{
      width:100%;padding:11px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff
    }
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid #e9d9c3;background:#ffbb38;color:#59382B;font-weight:600;cursor:pointer;text-decoration:none}
    .btn.secondary{background:#fff;border:1px solid #eadccd}
    .toolbar{display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:10px;flex-wrap:wrap}
    .error{background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;border-radius:10px;padding:10px 12px;margin:10px 0;font-size:14px}
    .ok{background:#e9f9ee;border:1px solid #bfe6cc;color:#256a3b;border-radius:10px;padding:10px 12px;margin:10px 0;font-size:14px}

    /* ===== Background slideshow (70% transparency) ===== */
    .bg-slot{
      position:fixed; inset:0;
      background-size:cover; background-position:center; background-repeat:no-repeat;
      opacity:0; transition:opacity 900ms ease-in-out; z-index:0;
    }
    .bg-slot.show{ opacity:.7; } /* tampil dengan 70% */
  </style>
</head>
<body>
  <!-- layer untuk cross-fade background -->
  <div id="bgA" class="bg-slot"></div>
  <div id="bgB" class="bg-slot"></div>

  <div class="auth-wrap">
    <div class="auth-card">
      <div class="brand"><i class="fas fa-feather"></i><b>CV GMB.</b></div>
      <h1><i class="fas fa-user-plus"></i> Daftar</h1>
      <p class="muted">Buat akun untuk mengakses dashboard.</p>

      <?php if($ok): ?>
        <div class="ok">Akun berhasil dibuat. Silakan <a href="<?=asset_url('auth/login.php')?>"><b>login</b></a>.</div>
      <?php endif; ?>

      <?php if($errors): ?>
        <div class="error">
          <?php foreach($errors as $e) echo '• '.e($e).'<br>'; ?>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <label>Nama Lengkap</label>
        <input type="text" name="nama" required value="<?=e($_POST['nama'] ?? '')?>">

        <div class="grid">
          <div>
            <label>Username</label>
            <input type="text" name="username" required value="<?=e($_POST['username'] ?? '')?>">
          </div>
          <div>
            <label>Role</label>
            <select name="role" required>
              <option value="" disabled <?=empty($_POST['role'])?'selected':''?>>Pilih role</option>
              <?php foreach($roles as $v=>$t): ?>
                <option value="<?=$v?>" <?=(!empty($_POST['role']) && $_POST['role']===$v)?'selected':''?>><?=$t?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid">
          <div>
            <label>Email</label>
            <input type="email" name="email" required value="<?=e($_POST['email'] ?? '')?>">
          </div>
          <div></div>
        </div>

        <div class="grid">
          <div>
            <label>Password</label>
            <input type="password" name="password" required>
          </div>
          <div>
            <label>Ulangi Password</label>
            <input type="password" name="password2" required>
          </div>
        </div>

        <div class="toolbar">
          <span class="muted">Sudah punya akun? <a href="<?=asset_url('auth/login.php')?>"><i class="fas fa-right-to-bracket"></i> Masuk</a></span>
          <button class="btn" type="submit"><i class="fas fa-user-plus"></i> Daftar</button>
        </div>
      </form>

      <div class="foot">© <?=date('Y')?> CV GMB</div>
    </div>
  </div>

  <script>
    // Gambar-gambar batik untuk slideshow (samakan dengan file di assets/img/batik/)
    const images = [
      "<?= asset_url('assets/img/batik/parang-kemuning.jpg') ?>",
      "<?= asset_url('assets/img/batik/gunungan-biru.jpg') ?>",
      "<?= asset_url('assets/img/batik/burung-batik.jpg') ?>",
      "<?= asset_url('assets/img/batik/lereng-coklat.jpg') ?>",
      "<?= asset_url('assets/img/batik/kawung-ungu.jpg') ?>",
      "<?= asset_url('assets/img/batik/floral-hitam-emas.jpg') ?>",
    ];

    // Preload biar mulus
    images.forEach(src => { const i = new Image(); i.src = src; });

    const a = document.getElementById('bgA');
    const b = document.getElementById('bgB');
    let cur = 0, useA = true;

    function setBg(el, src){ el.style.backgroundImage = `url("${src}")`; }

    function tick(){
      const next = images[cur];
      const show = useA ? a : b;
      const hide = useA ? b : a;

      setBg(show, next);
      hide.classList.remove('show');
      void show.offsetWidth; // force reflow
      show.classList.add('show');

      cur = (cur + 1) % images.length;
      useA = !useA;
    }

    // start
    setBg(a, images[0]);
    a.classList.add('show');
    cur = 1; useA = false;

    // ganti tiap 5 detik
    setInterval(tick, 5000);
  </script>
</body>
</html>
