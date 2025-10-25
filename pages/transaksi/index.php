<?php
// pages/transaksi/index.php — improved detection for "method" column
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

$_active = 'transaksi';
$page_title = 'Transaksi';

/* ---------- helper: try many candidate names, then search information_schema ---------- */
function detect_method_column_for_table(string $table): ?string {
  // common candidates (order matters)
  $candidates = [
    'method','metode','payment_method','metode_pembayaran','cara_bayar','payment_method_id',
    'payment_method_name','metode_bayar','method_name','metodePembayaran','metode_byr'
  ];
  foreach ($candidates as $c) {
    if (column_exists($table, $c)) return $c;
  }

  // Try a loose search in information_schema for columns containing keywords
  global $pdo, $dbname;
  try {
    $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
              AND (COLUMN_NAME LIKE ? OR COLUMN_NAME LIKE ? OR COLUMN_NAME LIKE ? OR COLUMN_NAME LIKE ?)
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$dbname, $table, '%method%', '%metode%', '%payment%', '%cara%']);
    $col = $st->fetchColumn();
    if ($col) return $col;
  } catch (Throwable $e) {
    // ignore and return null
  }
  return null;
}

/* ---------- Detect columns (robust) ---------- */
$date_col = pick_column('transaksi_keuangan', ['tgl_transaksi','tanggal','date','created_at','tgl']) ?? null;
$nominal_col = pick_column('transaksi_keuangan', ['nominal','total','jumlah','nilai','amount']) ?? 'nominal';
$keterangan_col = pick_column('transaksi_keuangan', ['keterangan','catatan','note','deskripsi','description']) ?? 'keterangan';
$tipe_col = pick_column('transaksi_keuangan', ['tipe','type','transaction_type']) ?? 'tipe';
$pk_col = pick_column('transaksi_keuangan', ['id','trx_id','transaction_id']) ?? 'id';

/* ---------- Ensure the table exists (avoid exceptions) ---------- */
$table_exists = table_exists('transaksi_keuangan');

/* ---------- detect method column if table exists ---------- */
$method_col = null;
if ($table_exists) {
  $method_col = detect_method_column_for_table('transaksi_keuangan');
}

/* ---------- Filters & defaults ---------- */
$filter = $_GET['filter'] ?? 'all'; // all | pendapatan | pengeluaran
$range  = $_GET['range']  ?? 'bulan'; // minggu | bulan | tahun | custom
$from   = $_GET['from']   ?? null;
$to     = $_GET['to']     ?? null;

if (!$from || !$to) {
  if ($range === 'minggu') {
    $from = date('Y-m-d', strtotime('monday this week'));
    $to   = date('Y-m-d', strtotime('sunday this week'));
  } elseif ($range === 'tahun') {
    $from = date('Y-01-01');
    $to   = date('Y-12-31');
  } else {
    $from = date('Y-m-01');
    $to   = date('Y-m-t');
  }
}

/* ---------- Build WHERE ---------- */
$where = []; $params = [];
if ($table_exists) {
  if ($filter === 'pendapatan')  $where[] = "`$tipe_col` = 'pendapatan'";
  if ($filter === 'pengeluaran') $where[] = "`$tipe_col` = 'pengeluaran'";
  if ($date_col) {
    $where[] = "`$date_col` BETWEEN ? AND ?";
    $params[] = $from; $params[] = $to;
  }
}
$wsql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ---------- KPI (robust) ---------- */
$sumIn = 0.0; $sumOut = 0.0; $saldo = 0.0;
if ($table_exists) {
  $sumIn = (float)(fetch("SELECT COALESCE(SUM(`$nominal_col`),0) s FROM transaksi_keuangan WHERE `$tipe_col`='pendapatan'")['s'] ?? 0);
  $sumOut = (float)(fetch("SELECT COALESCE(SUM(`$nominal_col`),0) s FROM transaksi_keuangan WHERE `$tipe_col`='pengeluaran'")['s'] ?? 0);
  $saldo = $sumIn - $sumOut;
} else {
  // fallback: penjualan vs pembelian (best effort)
  if (table_exists('penjualan')) {
    $pt = pick_total_column('penjualan') ?? null;
    if ($pt) $sumIn = (float)(fetch("SELECT COALESCE(SUM(`$pt`),0) s FROM penjualan")['s'] ?? 0);
  }
  if (table_exists('pembelian')) {
    $bt = pick_total_column('pembelian') ?? null;
    if ($bt) $sumOut = (float)(fetch("SELECT COALESCE(SUM(`$bt`),0) s FROM pembelian")['s'] ?? 0);
  }
  $saldo = $sumIn - $sumOut;
}

/* ---------- Prepare SELECT list (always include method alias) ---------- */
$selectCols = [];
$selectCols[] = ($pk_col ? "`$pk_col` AS id" : "NULL AS id");
$selectCols[] = ($date_col ? "`$date_col` AS tgl" : "NULL AS tgl");
$selectCols[] = ($tipe_col ? "`$tipe_col` AS tipe" : "NULL AS tipe");
$selectCols[] = ($nominal_col ? "`$nominal_col` AS nominal" : "0 AS nominal");

// method always selected as "method" (fallback to empty string)
if ($method_col) $selectCols[] = "`$method_col` AS method"; else $selectCols[] = "'' AS method";

$selectCols[] = ($keterangan_col ? "COALESCE(`$keterangan_col`,'') AS keterangan" : "'' AS keterangan");

/* ---------- Fetch rows safely ---------- */
$rows = [];
if ($table_exists) {
  $sql = "SELECT ".implode(", ", $selectCols)." FROM transaksi_keuangan $wsql ORDER BY ".($date_col?"`$date_col` DESC, ":"")."`$pk_col` DESC LIMIT 500";
  $rows = fetchAll($sql, $params);
}

/* ---------- Debug info (only if DEV_MODE true) ---------- */
$debug_info = null;
if (defined('DEV_MODE') && DEV_MODE) {
  $debug_info = [
    'method_col' => $method_col ?? '(none)',
    'date_col' => $date_col ?? '(none)',
    'nominal_col' => $nominal_col ?? '(none)',
    'keterangan_col' => $keterangan_col ?? '(none)',
  ];
}

/* ---------- View ---------- */
include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card">
  <h2 style="margin-bottom:8px">Transaksi</h2>

  

  <div class="kpi" style="margin-bottom:12px;display:flex;gap:12px;flex-wrap:wrap">
    <div class="card"><div class="muted">Pemasukan</div><h1><?= rupiah($sumIn) ?></h1></div>
    <div class="card"><div class="muted">Pengeluaran</div><h1><?= rupiah($sumOut) ?></h1></div>
    <div class="card"><div class="muted">Saldo (In − Out)</div><h1><?= rupiah($saldo) ?></h1></div>
  </div>

  <form class="no-print" method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
    <label>Filter:</label>
    <select name="filter">
      <option value="all" <?= $filter==='all'?'selected':'' ?>>Semua</option>
      <option value="pendapatan" <?= $filter==='pendapatan'?'selected':'' ?>>Pendapatan</option>
      <option value="pengeluaran" <?= $filter==='pengeluaran'?'selected':'' ?>>Pengeluaran</option>
    </select>

    <label>Range:</label>
    <select name="range" onchange="this.form.submit()">
      <option value="minggu" <?= $range==='minggu'?'selected':'' ?>>Minggu ini</option>
      <option value="bulan" <?= $range==='bulan'?'selected':'' ?>>Bulan ini</option>
      <option value="tahun" <?= $range==='tahun'?'selected':'' ?>>Tahun ini</option>
      <option value="custom" <?= $range==='custom'?'selected':'' ?>>Custom</option>
    </select>

    <label>Dari</label><input type="date" name="from" value="<?= e($from) ?>">
    <label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>">

    <button class="btn" type="submit"><i class="fas fa-filter"></i> Tampilkan</button>
    <a class="btn outline" href="<?= asset_url('pages/transaksi/print.php?from='.urlencode($from).'&to='.urlencode($to).'&filter='.urlencode($filter)) ?>" target="_blank"><i class="fas fa-print"></i> Cetak</a>
    <a class="btn" href="<?= asset_url('pages/transaksi/add.php') ?>"><i class="fas fa-plus"></i> Tambah</a>
  </form>

  <table class="table">
    <thead>
      <tr>
        <th style="width:160px">Tanggal</th>
        <th style="width:140px">Tipe</th>
        <th style="width:160px">Nominal</th>
        
        <th>Keterangan</th>
        <th class="no-print">Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="muted">Belum ada data.</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr>
          <td><?= $r['tgl'] ? e(date('d M Y', strtotime($r['tgl']))) : '<span class="muted">—</span>' ?></td>
          <td><?= e(ucfirst($r['tipe'] ?? '')) ?></td>
          <td><?= rupiah($r['nominal'] ?? 0) ?></td>
          
          <td><?= e($r['keterangan'] ?? '') ?></td>
          <td class="no-print actions-td" style="white-space:nowrap">
            <a class="icon-btn neutral" title="Edit" href="<?= asset_url('pages/transaksi/edit.php?id='.(int)$r['id']) ?>"><i class="fas fa-pen"></i></a>
            <form method="post" action="<?= asset_url('pages/transaksi/delete.php') ?>" style="display:inline" onsubmit="return confirm('Hapus transaksi ini?')">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="icon-btn danger" title="Hapus" type="submit"><i class="fas fa-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
