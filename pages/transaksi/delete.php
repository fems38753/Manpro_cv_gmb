<?php
// pages/transaksi/delete.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('pages/transaksi/index.php?msg=Metode+tidak+diizinkan');
}

// CSRF
if (!check_csrf($_POST['csrf'] ?? '')) {
  redirect('pages/transaksi/index.php?msg=Sesi+kadaluarsa');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  redirect('pages/transaksi/index.php?msg=Data+tidak+valid');
}

try {
  begin();

  // pastikan transaksi ada
  if (!table_exists('transaksi_keuangan')) {
    // fallback: nothing to delete
    rollback();
    redirect('pages/transaksi/index.php?msg=Tabel+transaksi_keuangan+tidak+ditemukan');
  }

  $trx = fetch("SELECT * FROM transaksi_keuangan WHERE id = ? LIMIT 1", [$id]);
  if (!$trx) {
    rollback();
    redirect('pages/transaksi/index.php?msg=Transaksi+tidak+ditemukan');
  }

  // Hapus journal entries yang mereferensi transaksi ini (jika ada)
  // Kita cek journal_entries.reference_type = 'transaksi_keuangan' dan reference_id = $id
  if (table_exists('journal_entries') && table_exists('journal_items')) {
    $je = fetch("SELECT id FROM journal_entries WHERE reference_type = 'transaksi_keuangan' AND reference_id = ? LIMIT 1", [$id]);
    if ($je && !empty($je['id'])) {
      $jid = (int)$je['id'];
      // hapus journal items lalu journal entry
      query("DELETE FROM journal_items WHERE journal_id = ?", [$jid]);
      query("DELETE FROM journal_entries WHERE id = ?", [$jid]);
    }
  }

  // Hapus transaksi
  query("DELETE FROM transaksi_keuangan WHERE id = ?", [$id]);

  commit();
  redirect('pages/transaksi/index.php?msg=Transaksi+terhapus');
  exit;
} catch (Exception $e) {
  rollback();
  // log internal untuk debugging
  error_log('Error deleting transaksi_keuangan id=' . $id . ' : ' . $e->getMessage());
  redirect('pages/transaksi/index.php?msg=Gagal+menghapus+transaksi');
  exit;
}
