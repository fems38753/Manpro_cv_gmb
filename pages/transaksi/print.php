<?php
// pages/transaksi/print.php — Print-only (tanpa chart), kolom Metode & Dibuat Oleh opsional
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/data/queries.php';
require_login();
allow(['direktur','staff_keuangan']);

/* ----- filter/range ----- */
$filter = $_GET['filter'] ?? 'all';    // all | pendapatan | pengeluaran
$range  = $_GET['range']  ?? 'bulan';  // minggu | bulan | tahun | custom
$from   = $_GET['from']   ?? null;
$to     = $_GET['to']     ?? null;

if (!$from || !$to) {
  if ($range === 'minggu') {
    $from = date('Y-m-d', strtotime('monday this week'));
    $to   = date('Y-m-d', strtotime('sunday this week'));
  } elseif ($range === 'tahun') {
    $from = date('Y-01-01');
    $to   = date('Y-12-31');
  } else { // default: bulan ini
    $from = date('Y-m-01');
    $to   = date('Y-m-t');
  }
}

/* ----- data transaksi ----- */
try {
  $data = trx_list($filter, 1, 10000, $from, $to, null); // ambil banyak biar lengkap
  $rows = $data['rows'] ?? [];
} catch (Throwable $e) {
  $rows = [];
}

/* ----- ringkasan sederhana (bukan statistik grafis) ----- */
try {
  $k = trx_summary($from, $to);
  $sumIn  = (float)($k['in']    ?? 0);
  $sumOut = (float)($k['out']   ?? 0);
  $saldo  = (float)($k['saldo'] ?? ($sumIn - $sumOut));
} catch (Throwable $e) {
  $sumIn = $sumOut = 0.0;
  foreach ($rows as $r) {
    $tipe = strtolower((string)($r['tipe'] ?? ''));
    $nom  = (float)($r['nominal'] ?? 0);
    if ($tipe === 'pendapatan') $sumIn += $nom; else $sumOut += $nom;
  }
  $saldo = $sumIn - $sumOut;
}

/* ----- mapping opsional kolom metode & dibuat oleh ----- */
function first_non_empty(array $arr) {
  foreach ($arr as $v) { if (isset($v) && $v !== '') return $v; }
  return '';
}
foreach ($rows as &$r) {
  $r['__method_show'] = first_non_empty([
    $r['method'] ?? null, $r['metode'] ?? null, $r['payment_method'] ?? null,
    $r['payment_method_name'] ?? null, $r['cara_bayar'] ?? null, $r['metode_bayar'] ?? null,
  ]);
  $r['__creator_show'] = first_non_empty([
    $r['created_by_name'] ?? null, $r['created_by'] ?? null,
    $r['user_name'] ?? null, $r['username'] ?? null, $r['author'] ?? null,
  ]);
}
unset($r);

/* ----- tentukan apakah kolom opsional ditampilkan ----- */
$showMethod = false; $showCreator = false;
if (!empty($rows)) {
  // tampilkan hanya bila minimal satu baris punya nilai
  foreach ($rows as $r) {
    if (!empty($r['__method_show']))  { $showMethod = true; }
    if (!empty($r['__creator_show'])) { $showCreator = true; }
    if ($showMethod && $showCreator) break;
  }
}

/* flag reset tombol */
$hasActiveFilter = ($filter !== 'all') || ($range !== 'bulan') ||
                   ($_GET['from'] ?? '') !== '' || ($_GET['to'] ?? '') !== '';

/* printed by (untuk footer) */
$u = function_exists('current_user') ? (current_user() ?: []) : [];
$printedBy = $u['username'] ?? $u['name'] ?? ($_SESSION['user']['username'] ?? 'User');

/* helper rupiah (safety) */
if (!function_exists('rupiah')) {
  function rupiah($n){ return 'Rp '.number_format((float)$n,0,',','.'); }
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Laporan Transaksi • CV GMB</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @page { size: A4 portrait; margin: 12mm 10mm; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-sizing: border-box; }
    html, body { height: 100%; }
    body { font: 12.5px/1.5 system-ui, Segoe UI, Roboto, Arial, sans-serif; color: #111827; background: #fff; margin: 0; }

    /* Wrapper konten agar aman 1 halaman A4 */
    .paper { width: 100%; max-width: 180mm; margin: 8mm auto 0; }

    /* Bar atas (screen only) */
    .noprint{ margin: 10px auto 0; max-width: 180mm; padding: 0 2mm; }
    .topbar{ display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap }
    .brand{ display:flex; gap:10px; align-items:center }
    .brand .title{ font-weight:800; font-size:18px; color:#59382B }
    .muted { color:#6b7280 }

    .filter-form{ display:flex; gap:8px; align-items:end; flex-wrap:wrap }
    .filter-form .field{ display:flex; flex-direction:column; gap:4px }
    .filter-form label{ font-size:11px; color:#6b7280 }
    .filter-form select,
    .filter-form input[type="date"]{
      height:32px; border:1px solid #eadccd; border-radius:8px; padding:4px 8px; min-width:140px; background:#fff;
    }

    .btn{ display:inline-flex; align-items:center; gap:8px; padding:7px 10px; border-radius:9px;
          border:1px solid #e9d9c3; background:#fff7e8; color:#59382B; text-decoration:none; cursor:pointer }
    .btn.primary{ background:#f3e6d4; border-color:#e5cfad }
    .btn.outline{ background:#fff; border:1px solid #eadccd }
    .btn i{ min-width:14px; text-align:center }

    /* Header cetak */
    .print-header {
      display:flex; justify-content:space-between; align-items:center; gap:12px;
      border-bottom: 2px solid #e5e7eb; padding-bottom: 8px; margin-bottom: 10px;
    }
    .hdr-left { display:flex; align-items:center; gap:10px }
    .hdr-title { font-weight:800; font-size:16px; color:#111827; line-height:1.15 }
    .hdr-sub   { font-size:11px; color:#6b7280 }
    .nowrap { white-space:nowrap }

    /* Summary chips (angka total, bukan statistik/grafik) */
    .summary { display:flex; gap:8px; flex-wrap:wrap; margin: 6px 0 8px }
    .chip { border:1px solid #e9d9c3; padding:6px 8px; border-radius:9px; background:#fff7e8; font-size:12px }

    /* Tabel */
    table { width:100%; border-collapse:collapse; }
    th,td { padding:7px 8px; border-bottom:1px solid #eee; text-align:left; vertical-align:top; }
    thead th { font-weight:700; color:#6b7280; text-transform:uppercase; font-size:11px }
    .right { text-align:right }
    .breakable { word-break:break-word; }

    /* Footer kecil */
    .footer { margin-top: 8px; display:flex; justify-content:space-between; align-items:center; color:#6b7280; font-size:11px }

    /* Print rules */
    @media print {
      .noprint { display:none !important; }
      .paper   { margin: 0 auto; }
      thead { display:table-header-group; }
      tfoot { display:table-footer-group; }
      table tr { page-break-inside:avoid; }
    }
  </style>
</head>
<body>

<!-- ====== BAR ATAS (screen) ====== -->
<div class="noprint">
  <div class="topbar">
    <div class="brand">
      <div class="title">CV Gelora Maju Bersama</div>
      <div class="muted">Laporan Transaksi Keuangan</div>
    </div>

    <form method="get" class="filter-form" action="">
      <div class="field">
        <label>Filter</label>
        <select name="filter">
          <option value="all"        <?= $filter==='all' ? 'selected' : '' ?>>Semua</option>
          <option value="pendapatan" <?= $filter==='pendapatan' ? 'selected' : '' ?>>Pendapatan</option>
          <option value="pengeluaran"<?= $filter==='pengeluaran' ? 'selected' : '' ?>>Pengeluaran</option>
        </select>
      </div>
      <div class="field">
        <label>Range</label>
        <select name="range">
          <option value="minggu" <?= $range==='minggu' ? 'selected' : '' ?>>Minggu ini</option>
          <option value="bulan"  <?= $range==='bulan'  ? 'selected' : '' ?>>Bulan ini</option>
          <option value="tahun"  <?= $range==='tahun'  ? 'selected' : '' ?>>Tahun ini</option>
          <option value="custom" <?= $range==='custom' ? 'selected' : '' ?>>Custom</option>
        </select>
      </div>
      <div class="field">
        <label>Dari</label>
        <input type="date" name="from" value="<?= e($from) ?>">
      </div>
      <div class="field">
        <label>Sampai</label>
        <input type="date" name="to" value="<?= e($to) ?>">
      </div>

      <button class="btn primary" type="submit"><i class="fa-solid fa-filter"></i> Tampilkan</button>

      <?php if ($hasActiveFilter): ?>
        <a class="btn outline" href="<?= asset_url('pages/transaksi/print.php') ?>">
          <i class="fa-solid fa-rotate-left"></i> Reset
        </a>
      <?php endif; ?>

      <a class="btn" href="#" onclick="window.print();return false"><i class="fa-solid fa-print"></i> Cetak</a>
      <a class="btn outline" href="<?= asset_url('pages/transaksi/index.php') ?>"><i class="fa-solid fa-arrow-left"></i> Kembali</a>
    </form>
  </div>
</div>

<!-- ====== KONTEN KERTAS ====== -->
<div class="paper">

  <!-- Header cetak -->
  <div class="print-header">
    <div class="hdr-left">
      <?php $logo = __DIR__.'/../../assets/img/logo.png'; if (is_file($logo)): ?>
        <img src="<?= asset_url('assets/img/logo.png') ?>" alt="Logo" style="height:26px;width:auto">
      <?php endif; ?>
      <div>
        <div class="hdr-title">Laporan Transaksi</div>
        <div class="hdr-sub">
          Periode: <?= e(date('d M Y', strtotime($from))) ?> – <?= e(date('d M Y', strtotime($to))) ?>
          <?= $filter !== 'all' ? ' • '.e(ucfirst($filter)) : '' ?>
        </div>
      </div>
    </div>
    <div class="hdr-sub nowrap">Dicetak: <?= e(date('d M Y H:i')) ?></div>
  </div>

  <!-- Ringkasan angka (bukan statistik) -->
  <div class="summary">
    <div class="chip">Pemasukan: <b><?= rupiah($sumIn) ?></b></div>
    <div class="chip">Pengeluaran: <b><?= rupiah($sumOut) ?></b></div>
    <div class="chip">Saldo (In − Out): <b><?= rupiah($saldo) ?></b></div>
    <div class="chip">Filter: <b><?= e(ucfirst($filter)) ?></b></div>
  </div>

  <!-- Tabel data -->
  <table>
    <thead>
      <tr>
        <th style="width:100px">Tanggal</th>
        <th style="width:110px">Tipe</th>
        <th class="right" style="width:120px">Nominal</th>
        <?php if ($showMethod): ?><th style="width:120px">Metode</th><?php endif; ?>
        <th class="breakable">Keterangan</th>
        <?php if ($showCreator): ?><th style="width:150px">Dibuat Oleh</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr>
        <td colspan="<?= 3 + 1 /*Keterangan*/ + ($showMethod?1:0) + ($showCreator?1:0) ?>" class="muted">
          Tidak ada transaksi untuk filter ini.
        </td>
      </tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td><?= e(!empty($r['tgl']) ? date('d M Y', strtotime($r['tgl'])) : '') ?></td>
        <td><?= e(ucfirst($r['tipe'] ?? '')) ?></td>
        <td class="right"><?= rupiah($r['nominal'] ?? 0) ?></td>
        <?php if ($showMethod): ?><td><?= e($r['__method_show']) ?></td><?php endif; ?>
        <td class="breakable"><?= e($r['keterangan'] ?? '') ?></td>
        <?php if ($showCreator): ?><td><?= e($r['__creator_show']) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div class="footer">
    <div>Dicetak: <?= e(date('d M Y H:i')) ?> • Oleh: <?= e($printedBy) ?></div>
    <div>CV Gelora Maju Bersama</div>
  </div>
</div>

<script>
  // Judul file PDF saat print/save
  (function(){
    const from = "<?= e(date('Ymd', strtotime($from))) ?>";
    const to   = "<?= e(date('Ymd', strtotime($to))) ?>";
    const flt  = "<?= e($filter) ?>";
    document.title = `Transaksi_${from}-${to}_${flt}.pdf`;
  })();
</script>

</body>
</html>
