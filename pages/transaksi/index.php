<?php
// pages/transaksi/index.php — robust + KPI ikut periode + kolom "method" opsional
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

$_active = 'transaksi';
$page_title = 'Transaksi';

/* ---------- helper: coba banyak kandidat, lalu scan information_schema ---------- */
function detect_method_column_for_table(string $table): ?string {
  $candidates = [
    'method','metode','payment_method','metode_pembayaran','cara_bayar',
    'payment_method_id','payment_method_name','metode_bayar','method_name',
    'metodePembayaran','metode_byr'
  ];
  foreach ($candidates as $c) {
    if (column_exists($table, $c)) return $c;
  }
  global $pdo, $dbname;
  try {
    $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
              AND (COLUMN_NAME LIKE ? OR COLUMN_NAME LIKE ? OR COLUMN_NAME LIKE ? OR COLUMN_NAME LIKE ?)
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$dbname, $table, '%method%', '%metode%', '%payment%', '%cara%']);
    $col = $st->fetchColumn();
    if ($col) return $col;
  } catch (Throwable $e) {}
  return null;
}

/* ---------- Deteksi kolom (robust) ---------- */
$table_exists    = table_exists('transaksi_keuangan');
$date_col        = $table_exists ? (pick_column('transaksi_keuangan', ['tgl_transaksi','tanggal','date','created_at','tgl']) ?? null) : null;
$nominal_col     = $table_exists ? (pick_column('transaksi_keuangan', ['nominal','total','jumlah','nilai','amount']) ?? 'nominal') : null;
$keterangan_col  = $table_exists ? (pick_column('transaksi_keuangan', ['keterangan','catatan','note','deskripsi','description']) ?? 'keterangan') : null;
$tipe_col        = $table_exists ? (pick_column('transaksi_keuangan', ['tipe','type','transaction_type']) ?? 'tipe') : null;
$pk_col          = $table_exists ? (pick_column('transaksi_keuangan', ['id','trx_id','transaction_id']) ?? 'id') : null;
$method_col      = $table_exists ? detect_method_column_for_table('transaksi_keuangan') : null;

/* ---------- Filters & defaults ---------- */
$filter = $_GET['filter'] ?? 'all';   // all | pendapatan | pengeluaran
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

/* ---------- WHERE untuk tabel (ikut filter & tanggal) ---------- */
$where = []; $params = [];
if ($table_exists) {
  if ($filter === 'pendapatan')  $where[] = "`$tipe_col` = 'pendapatan'";
  if ($filter === 'pengeluaran') $where[] = "`$tipe_col` = 'pengeluaran'";
  if ($date_col) { $where[] = "`$date_col` BETWEEN ? AND ?"; $params[] = $from; $params[] = $to; }
}
$wsql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ---------- KPI: ikut periode tanggal (abaikan filter tipe agar tetap tampil dua-duanya) ---------- */
$sumIn = 0.0; $sumOut = 0.0; $saldo = 0.0;
if ($table_exists) {
  $whereDate = []; $pDate = [];
  if ($date_col) { $whereDate[] = "`$date_col` BETWEEN ? AND ?"; $pDate = [$from, $to]; }
  $wdsql = $whereDate ? ('WHERE '.implode(' AND ', $whereDate)) : '';

  // Pemasukan
  $sqlIn  = $wdsql ? "$wdsql AND `$tipe_col`='pendapatan'" : "WHERE `$tipe_col`='pendapatan'";
  $sumIn  = (float)(fetch("SELECT COALESCE(SUM(`$nominal_col`),0) s FROM transaksi_keuangan $sqlIn", $pDate)['s'] ?? 0);

  // Pengeluaran
  $sqlOut = $wdsql ? "$wdsql AND `$tipe_col`='pengeluaran'" : "WHERE `$tipe_col`='pengeluaran'";
  $sumOut = (float)(fetch("SELECT COALESCE(SUM(`$nominal_col`),0) s FROM transaksi_keuangan $sqlOut", $pDate)['s'] ?? 0);

  $saldo  = $sumIn - $sumOut;
} else {
  // Fallback sederhana pakai penjualan/pembelian jika tabel tidak ada
  if (table_exists('penjualan')) {
    $ptDate = pick_date_column('penjualan'); $ptTot = pick_total_column('penjualan');
    if ($ptDate && $ptTot) {
      $sumIn = (float)(fetch("SELECT COALESCE(SUM(`$ptTot`),0) s FROM penjualan WHERE `$ptDate` BETWEEN ? AND ?", [$from,$to])['s'] ?? 0);
    }
  }
  if (table_exists('pembelian')) {
    $pbDate = pick_date_column('pembelian'); $pbTot = pick_total_column('pembelian');
    if ($pbDate && $pbTot) {
      $sumOut = (float)(fetch("SELECT COALESCE(SUM(`$pbTot`),0) s FROM pembelian WHERE `$pbDate` BETWEEN ? AND ?", [$from,$to])['s'] ?? 0);
    }
  }
  $saldo = $sumIn - $sumOut;
}

/* ---------- SELECT list (selalu alias konsisten) ---------- */
$selectCols = [];
$selectCols[] = ($pk_col     ? "`$pk_col` AS id"     : "NULL AS id");
$selectCols[] = ($date_col   ? "`$date_col` AS tgl"  : "NULL AS tgl");
$selectCols[] = ($tipe_col   ? "`$tipe_col` AS tipe" : "NULL AS tipe");
$selectCols[] = ($nominal_col? "`$nominal_col` AS nominal" : "0 AS nominal");
$selectCols[] = ($method_col ? "`$method_col` AS method"   : "'' AS method");
$selectCols[] = ($keterangan_col ? "COALESCE(`$keterangan_col`,'') AS keterangan" : "'' AS keterangan");

/* ---------- Fetch rows ---------- */
$rows = [];
if ($table_exists) {
  $order = ($date_col ? "`$date_col` DESC, " : "")."`$pk_col` DESC";
  $sql   = "SELECT ".implode(", ", $selectCols)." FROM transaksi_keuangan $wsql ORDER BY $order LIMIT 500";
  $rows  = fetchAll($sql, $params);
}
$showMethod = (bool)$method_col;

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
      <option value="all"         <?= $filter==='all'?'selected':'' ?>>Semua</option>
      <option value="pendapatan"  <?= $filter==='pendapatan'?'selected':'' ?>>Pendapatan</option>
      <option value="pengeluaran" <?= $filter==='pengeluaran'?'selected':'' ?>>Pengeluaran</option>
    </select>

    <label>Range:</label>
    <select name="range" onchange="this.form.submit()">
      <option value="minggu" <?= $range==='minggu'?'selected':'' ?>>Minggu ini</option>
      <option value="bulan"  <?= $range==='bulan'?'selected':'' ?>>Bulan ini</option>
      <option value="tahun"  <?= $range==='tahun'?'selected':'' ?>>Tahun ini</option>
      <option value="custom" <?= $range==='custom'?'selected':'' ?>>Custom</option>
    </select>

    <label>Dari</label><input type="date" name="from" value="<?= e($from) ?>">
    <label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>">

    <button class="btn" type="submit"><i class="fas fa-filter"></i> Tampilkan</button>
    <!-- Cetak ke pages/transaksi/print.php dengan parameter yang sama -->
    <a class="btn outline" target="_blank"
       href="<?= asset_url('pages/transaksi/print.php?filter='.urlencode($filter).'&range='.urlencode($range).'&from='.urlencode($from).'&to='.urlencode($to)) ?>">
      <i class="fas fa-print"></i> Cetak
    </a>
  </form>

  <table class="table">
    <thead>
      <tr>
        <th style="width:160px">Tanggal</th>
        <th style="width:140px">Tipe</th>
        <th style="width:160px">Nominal</th>
        <?php if ($showMethod): ?><th style="width:160px">Metode</th><?php endif; ?>
        <th>Keterangan</th>
        <th class="no-print">Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= $showMethod ? 6 : 5 ?>" class="muted">Belum ada data.</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr>
          <td><?= $r['tgl'] ? e(date('d M Y', strtotime($r['tgl']))) : '<span class="muted">—</span>' ?></td>
          <td><?= e(ucfirst((string)($r['tipe'] ?? ''))) ?></td>
          <td><?= rupiah((float)($r['nominal'] ?? 0)) ?></td>
          <?php if ($showMethod): ?><td><?= e((string)($r['method'] ?? '')) ?></td><?php endif; ?>
          <td><?= e((string)($r['keterangan'] ?? '')) ?></td>
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

<?php include __DIR__ . '/../_partials/layout_end.php';
