<?php
// pages/pembelian/delete.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_operasional']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pages/pembelian/index.php');
if (!check_csrf($_POST['csrf'] ?? '')) redirect('pages/pembelian/index.php?msg=Sesi+kadaluarsa');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect('pages/pembelian/index.php?msg=ID+tidak+valid');

try {
  begin();

  // ambil purchase & items
  $p = fetch("SELECT * FROM purchases WHERE id = ? LIMIT 1", [$id]);
  if (!$p) { rollback(); redirect('pages/pembelian/index.php?msg=Pembelian+tidak+ditemukan'); }

  $items = fetchAll("SELECT * FROM purchase_items WHERE purchase_id = ?", [$id]);

  // rollback stok: kurangi stok sesuai item
  foreach ($items as $it) {
    if (!table_exists('stock_balances')) continue;
    $sb = fetch("SELECT id,quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? LIMIT 1", [$it['product_id'], $p['warehouse_id']]);
    $need = (float)$it['qty'];
    if ($sb) {
      if ((float)$sb['quantity'] < $need) {
        rollback();
        redirect('pages/pembelian/index.php?msg=Gagal+hapus:+stok+fisik+tidak+mencukupi+untuk+rollback');
      }
      query("UPDATE stock_balances SET quantity = quantity - ?, last_updated = NOW() WHERE id = ?", [$need, $sb['id']]);
      if (table_exists('stock_movements')) {
        $note = "Rollback pembelian #{$id} (hapus)";
        query("INSERT INTO stock_movements (product_id,warehouse_id,change_qty,movement_type,reference_type,reference_id,note,performed_by,created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
               [$it['product_id'], $p['warehouse_id'], -$need, 'purchase_reversal', 'purchase', $id, $note, $_SESSION['user']['user_id'] ?? null]);
      }
    } else {
      rollback();
      redirect('pages/pembelian/index.php?msg=Gagal+hapus:+stok+tidak+ditemukan+untuk+produk+ID+' . $it['product_id']);
    }
  }

  // hapus journal entries terkait purchase
  if (table_exists('journal_entries') && table_exists('journal_items')) {
    $je = fetch("SELECT id FROM journal_entries WHERE reference_type = 'purchase' AND reference_id = ? LIMIT 1", [$id]);
    if ($je && !empty($je['id'])) {
      query("DELETE FROM journal_items WHERE journal_id = ?", [$je['id']]);
      query("DELETE FROM journal_entries WHERE id = ?", [$je['id']]);
    }
  }

  // hapus transaksi_keuangan yang berkaitan (simple matching by keterangan)
  if (table_exists('transaksi_keuangan')) {
    query("DELETE FROM transaksi_keuangan WHERE keterangan LIKE ?", ["%Pembelian #{$id}%"]);
  }

  // hapus items lalu purchase
  query("DELETE FROM purchase_items WHERE purchase_id = ?", [$id]);
  query("DELETE FROM purchases WHERE id = ?", [$id]);

  commit();
  redirect('pages/pembelian/index.php?msg=Pembelian+dihapus');
  exit;
} catch (Exception $e) {
  rollback();
  error_log('Error deleting purchase id='.$id.' : '.$e->getMessage());
  redirect('pages/pembelian/index.php?msg=Gagal+menghapus+pembelian');
}
