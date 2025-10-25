<?php
// pages/penjualan/delete.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pages/penjualan/index.php');
if (!check_csrf($_POST['csrf'] ?? '')) redirect('pages/penjualan/index.php?msg=Sesi+kadaluarsa');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect('pages/penjualan/index.php?msg=Data+tidak+valid');

try {
  begin();
  $s = fetch("SELECT * FROM penjualan WHERE id = ? LIMIT 1", [$id]);
  if (!$s) { rollback(); redirect('pages/penjualan/index.php?msg=Data+tidak+ditemukan'); }

  // restore stock: add qty back to stock_balances
  if (table_exists('stock_balances')) {
    $sb = fetch("SELECT id, quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? LIMIT 1 FOR UPDATE", [$s['product_id'],$s['warehouse_id']]);
    if ($sb) {
      query("UPDATE stock_balances SET quantity = quantity + ?, last_updated = NOW() WHERE id = ?", [$s['qty'], $sb['id']]);
    } else {
      // create balance row (safe) — because we are restoring
      query("INSERT INTO stock_balances (product_id,warehouse_id,quantity,last_updated) VALUES (?,?,?,NOW())", [$s['product_id'],$s['warehouse_id'],$s['qty']]);
    }

    if (table_exists('stock_movements')) {
      query("INSERT INTO stock_movements (product_id,warehouse_id,change_qty,movement_type,reference_type,reference_id,note,performed_by,created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [$s['product_id'],$s['warehouse_id'],$s['qty'],'sale_revert','sale',$id,'Rollback penjualan (hapus)', $_SESSION['user']['user_id'] ?? null]);
    }
  }

  // delete transaksi_keuangan referencing this sale
  if (table_exists('transaksi_keuangan')) {
    query("DELETE FROM transaksi_keuangan WHERE keterangan LIKE ?", ["%Penjualan #{$id}%"]);
  }

  // delete sale
  query("DELETE FROM penjualan WHERE id = ?", [$id]);

  commit();
  redirect('pages/penjualan/index.php?msg=Penjualan+dihapus');
} catch (Exception $e) {
  rollback();
  error_log('Error deleting sale id='.$id.' : '.$e->getMessage());
  redirect('pages/penjualan/index.php?msg=Gagal+menghapus+penjualan');
}
