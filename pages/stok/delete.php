<?php
// pages/stok/delete.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_operasional']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pages/stok/index.php');
if (!check_csrf($_POST['csrf'] ?? '')) redirect('pages/stok/index.php?msg=Sesi+kadaluarsa');

$sb_id = (int)($_POST['sb_id'] ?? 0);
if ($sb_id <= 0) redirect('pages/stok/index.php?msg=ID+tidak+valid');

try {
  begin();
  $sb = fetch("SELECT * FROM stock_balances WHERE id = ? LIMIT 1", [$sb_id]);
  if (!$sb) { rollback(); redirect('pages/stok/index.php?msg=Data+tidak+ditemukan'); }

  // Only allow delete when qty == 0
  if ((float)$sb['quantity'] !== 0.0) {
    rollback();
    redirect('pages/stok/index.php?msg=Hanya+dapat+menghapus+item+jika+qty+=+0');
  }

  // delete stock_movements referencing this sb_id (optional)
  if (table_exists('stock_movements')) {
    query("DELETE FROM stock_movements WHERE reference_type='stock_balance' AND reference_id = ?", [$sb_id]);
  }

  // delete stock_balances row
  query("DELETE FROM stock_balances WHERE id = ?", [$sb_id]);

  commit();
  redirect('pages/stok/index.php?msg=Item+stok+berhasil+dihapus');
} catch (Exception $e) {
  rollback();
  error_log('Error deleting stock_balances id='.$sb_id.' : '.$e->getMessage());
  redirect('pages/stok/index.php?msg=Gagal+menghapus+stok');
}
