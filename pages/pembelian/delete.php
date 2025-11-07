<?php
// MANPRO/pages/pembelian/delete.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_operasional','staff_keuangan']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('pages/pembelian/index.php');
}
if (!check_csrf($_POST['csrf'] ?? '')) {
  redirect('pages/pembelian/index.php?msg=Sesi+kadaluarsa');
}

$purchase_id = (int)($_POST['id'] ?? 0);
if ($purchase_id <= 0) {
  redirect('pages/pembelian/index.php?msg=ID+tidak+valid');
}

// Helper kecil untuk redirect dgn rollback
function fail_and_redirect(string $msg) {
  rollback();
  redirect('pages/pembelian/index.php?msg=' . rawurlencode($msg));
}

try {
  begin();

  // === Ambil purchase header ===
  $p = fetch("SELECT * FROM purchases WHERE id = ? LIMIT 1", [$purchase_id]);
  if (!$p) fail_and_redirect('Pembelian tidak ditemukan.');

  // Warehouse id (opsional)
  $warehouse_id = 0;
  if (column_exists('purchases','warehouse_id')) {
    $warehouse_id = (int)($p['warehouse_id'] ?? 0);
  }

  // === Ambil detail items ===
  $items = fetchAll("SELECT * FROM purchase_items WHERE purchase_id = ?", [$purchase_id]);

  // === Rollback stok (jika ada tabel stock_balances) ===
  if (table_exists('stock_balances')) {
    foreach ($items as $it) {
      $product_id = (int)$it['product_id'];
      $qty        = (float)$it['qty'];
      // cari baris saldo
      $sb = fetch("SELECT id, quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? LIMIT 1", [$product_id, $warehouse_id]);
      if (!$sb) {
        fail_and_redirect("Gagal hapus: saldo stok produk #$product_id di gudang ".($warehouse_id ?: '-')." tidak ditemukan.");
      }
      $newQty = (float)$sb['quantity'] - $qty;
      if ($newQty < 0) {
        fail_and_redirect("Gagal hapus: stok produk #$product_id di gudang ".($warehouse_id ?: '-')." tidak cukup untuk rollback.");
      }

      // update saldo
      query("UPDATE stock_balances SET quantity = ?, last_updated = NOW() WHERE id = ?", [$newQty, (int)$sb['id']]);

      // catat movement reversal (jika ada tabelnya)
      if (table_exists('stock_movements')) {
        $hasRefType = column_exists('stock_movements','reference_type');
        $hasRefId   = column_exists('stock_movements','reference_id');
        $hasNote    = column_exists('stock_movements','note');
        $hasBy      = column_exists('stock_movements','performed_by');
        $cols = ['product_id','warehouse_id','change_qty','movement_type','created_at'];
        $vals = [$product_id, $warehouse_id, -$qty, 'purchase_reversal', date('Y-m-d H:i:s')];
        if ($hasRefType) { $cols[]='reference_type'; $vals[]='purchase'; }
        if ($hasRefId)   { $cols[]='reference_id';   $vals[]=$purchase_id; }
        if ($hasNote)    { $cols[]='note';           $vals[]='Rollback pembelian #'.$purchase_id; }
        if ($hasBy)      { $cols[]='performed_by';   $vals[]=$_SESSION['user']['user_id'] ?? null; }
        $fields = implode(',', array_map(fn($c)=>"`$c`",$cols));
        $marks  = implode(',', array_fill(0,count($cols),'?'));
        query("INSERT INTO stock_movements ($fields) VALUES ($marks)", $vals);
      }
    }
  }

  // === Hapus movements yang tercatat saat pembelian (optional) ===
  if (table_exists('stock_movements')) {
    if (column_exists('stock_movements','reference_type') && column_exists('stock_movements','reference_id')) {
      query("DELETE FROM stock_movements WHERE reference_type='purchase' AND reference_id=?", [$purchase_id]);
    } else {
      // fallback bersihkan yang mengandung #ID (kalau ada kolom note)
      if (column_exists('stock_movements','note')) {
        query("DELETE FROM stock_movements WHERE note LIKE ?", ['%#'.$purchase_id.'%']);
      }
    }
  }

  // === Hapus transaksi keuangan terkait (pengeluaran saat pembelian) ===
  if (table_exists('transaksi_keuangan')) {
    $conds = [];
    $pars  = [];
    // keterangan mengandung nomor purchase
    if (column_exists('transaksi_keuangan','keterangan')) {
      $conds[] = "keterangan LIKE ?";
      $pars[]  = "%Pembelian #{$purchase_id}%";
    }
    // tipe pengeluaran (jika ada kolom tipe)
    if (column_exists('transaksi_keuangan','tipe')) {
      $conds[] = "tipe = 'pengeluaran'";
    }
    $where = $conds ? ('WHERE '.implode(' AND ', $conds)) : '';
    if ($where) query("DELETE FROM transaksi_keuangan $where", $pars);
  }

  // === Hapus jurnal (jika kamu memang punya tabel akuntansi itu) ===
  if (table_exists('journal_entries') && table_exists('journal_items')) {
    $hasRefType = column_exists('journal_entries','reference_type');
    $hasRefId   = column_exists('journal_entries','reference_id');
    if ($hasRefType && $hasRefId) {
      $je = fetch("SELECT id FROM journal_entries WHERE reference_type='purchase' AND reference_id=? LIMIT 1", [$purchase_id]);
      if ($je && !empty($je['id'])) {
        query("DELETE FROM journal_items  WHERE journal_id = ?", [$je['id']]);
        query("DELETE FROM journal_entries WHERE id = ?", [$je['id']]);
      }
    }
  }

  // === Hapus detail lalu header (hindari FK RESTRICT) ===
  query("DELETE FROM purchase_items WHERE purchase_id = ?", [$purchase_id]);
  query("DELETE FROM purchases      WHERE id = ?", [$purchase_id]);

  commit();
  redirect('pages/pembelian/index.php?msg=Pembelian+berhasil+dihapus');
} catch (Exception $e) {
  fail_and_redirect('Gagal menghapus pembelian: '.$e->getMessage());
}
