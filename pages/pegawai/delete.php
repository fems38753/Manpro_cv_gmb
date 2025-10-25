<?php
// MANPRO/pages/pegawai/delete.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur']); // hanya direktur

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pages/pegawai/index.php');
if (!check_csrf($_POST['csrf'] ?? '')) redirect('pages/pegawai/index.php?msg=Sesi+kadaluarsa');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect('pages/pegawai/index.php?msg=Data+tidak+valid');

// detect columns
$user_pk_col = pick_column('users', ['user_id','id','uid']) ?: 'user_id';
$role_col    = pick_column('users', ['role','user_role','level','role_name']) ?: null;
$avatar_col  = pick_column('users', ['avatar_path','avatar','photo','profile_pic']) ?: null;

// cannot delete yourself
$self_id = (int)($_SESSION['user']['user_id'] ?? 0);
if ($self_id && $id === $self_id) {
  redirect('pages/pegawai/index.php?msg=Tidak+bisa+menghapus+akun+sendiri');
}

// fetch user info
$selCols = ["u.`$user_pk_col` AS user_id"];
if ($role_col) $selCols[] = "COALESCE(u.`$role_col`,'') AS role";
if ($avatar_col) $selCols[] = "COALESCE(u.`$avatar_col`,'') AS avatar_path";
$sql = "SELECT " . implode(', ', $selCols) . " FROM users u WHERE u.`$user_pk_col` = ? LIMIT 1";
$row = fetch($sql, [$id]);
if (!$row) redirect('pages/pegawai/index.php?msg=Data+tidak+ditemukan');

// if role exists and is direktor -> ensure not last direktor
if ($role_col && ($row['role'] ?? '') === 'direktur') {
  $count = (int)(fetch("SELECT COUNT(*) c FROM users WHERE `$role_col`='direktur'")['c'] ?? 0);
  if ($count <= 1) {
    redirect('pages/pegawai/index.php?msg=Tidak+bisa+hapus+direktur+terakhir');
  }
}

// delete avatar file if present and under uploads/
if ($avatar_col && !empty($row['avatar_path']) && str_starts_with($row['avatar_path'],'uploads/')) {
  $abs = APP_DIR . '/' . str_replace(['\\','/'], DIRECTORY_SEPARATOR, $row['avatar_path']);
  if (is_file($abs)) @unlink($abs);
}

// delete user
query("DELETE FROM users WHERE `$user_pk_col` = ?", [$id]);
redirect('pages/pegawai/index.php?msg=Akun+terhapus');
