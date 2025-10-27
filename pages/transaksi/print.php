<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/data/queries.php';
require_login();
allow(['direktur','staff_keuangan']);

/* ----- ambil filter/range ----- */
$filter = $_GET['filter'] ?? 'all';     // all | pendapatan | pengeluaran
$range  = $_GET['range']  ?? 'bulan';   // minggu | bulan | tahun | custom
$from   = $_GET['from']   ?? null;
$to     = $_GET['to']     ?? null;

/* default range jika custom kosong */
if (!$from || !$to) {
  if ($range === 'minggu') {
    // minggu ini (Senin - Minggu)
    $from = date('Y-m-d', strtotime('monday this week'));
    $to   = date('Y-m-d', strtotime('sunday this week'));
  } elseif ($range === 'tahun') {
    $from = date('Y-01-01');
    $to   = date('Y-12-31');
  } else {
    // bulan ini
    $from = date('Y-m-01');
    $to   = date('Y-m-t');
  }
}

/* ----- ambil data via helper yang mendukung filter ----- */
try {
  // ambil hingga 10000 baris agar laporan lengkap
  $data = trx_list($filter, 1, 10000, $from, $to, null);
  $rows = $data['rows'] ?? [];
} catch (Exception $e) {
  $rows = [];
}

/* ----- ringkasan ----- */
try {
  $k = trx_summary($from, $to);
  $sumIn  = (float)($k['in']    ?? 0);
  $sumOut = (float)($k['out']   ?? 0);
  $saldo  = (float)($k['saldo'] ?? ($sumIn - $sumOut));
} catch (Exception $e) {
  // fallback bila helper tidak tersedia
  $sumIn = $sumOut = $saldo = 0.0;
  foreach ($rows as $r) {
    if (strtolower((string)($r['tipe'] ?? '')) === 'pendapatan') $sumIn  += (float)($r['nominal'] ?? 0);
    else                                                         $sumOut += (float)($r['nominal'] ?? 0);
  }
  $saldo = $sumIn - $sumOut;
}

/* flag reset */
$hasActiveFilter = ($filter !== 'all') || ($range !== 'bulan') ||
                   ($_GET['from'] ?? '') !== '' || ($_GET['to'] ?? '') !== '';
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
    @page { size: A4 portrait; margin: 18mm 14mm; }
    body { font:13px/1.55 system-ui,Segoe UI,Roboto,Arial; color:#222; background:#fff; }

    .noprint{ margin:8px 0 16px }
    .topbar{ display:flex; gap:16px; align-items:center; justify-content:space-between; flex-wrap:wrap }

    .brand{ display:flex; gap:12px; align-items:center }
    .brand .title{ font-weight:800; font-size:20px; color:#59382B }
    .muted { color:#7b8794 }

    /* Filter form */
    .filter-form{ display:flex; gap:10px; align-items:end; flex-wrap:wrap }
    .filter-form .field{ display:flex; flex-direction:column; gap:6px }
    .filter-form label{ font-size:12px; color:#7b8794 }
    .filter-form select,
    .filter-form input[type="date"]{
      height:34px; border:1px solid #eadccd; border-radius:8px; padding:4px 10px; min-width:150px;
      background:#fff;
    }

    /* Buttons */
    .btn{ display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:10px;
          border:1px solid #e9d9c3; background:#fff7e8; color:#59382B; text-decoration:none; cursor:pointer }
    .btn:hover{ filter:brightness(0.98) }
    .btn.primary{ background:#f3e6d4; border-color:#e5cfad }
    .btn.outline{ background:#fff; border:1px solid #eadccd }
    .btn i{ min-width:14px; text-align:center }

    /* Chips & table */
    .summary { display:flex; gap:12px; margin:6px 0 12px }
    .chip { border:1px solid #e9d9c3; padding:8px 10px; border-radius:10px; background:#fff7e8 }
    table { width:100%; border-collapse:collapse; margin-top:10px }
    th,td { padding:8px 10px; border-bottom:1px solid #eee; text-align:left; vertical-align:top }
    thead th { font-weight:700; color:#7b8794; text-transform:uppercase; font-size:12px }
    .right { text-align:right }
    .footer { margin-top:16px; display:flex; justify-content:space-between; color:#7b8794; font-size:12px }

    @media print { .noprint{ display:none } }
  </style>
</head>
<body>

<!-- BAR ATAS: Judul + Form Periode & Aksi -->
<div class="noprint topbar">
  <div class="brand">
    <div>
      <div class="title">CV Gelora Maju Bersama</div>
      <div class="muted">Laporan Transaksi Keuangan</div>
    </div>
  </div>

  <!-- FORM FILTER: Periode & Tampilkan -->
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

    <button class="btn primary" type="submit">
      <i class="fa-solid fa-filter"></i> Tampilkan
    </button>

    <?php if ($hasActiveFilter): ?>
      <a class="btn outline" href="<?= asset_url('pages/transaksi/print.php') ?>">
        <i class="fa-solid fa-rotate-left"></i> Reset
      </a>
    <?php endif; ?>

    <a class="btn" href="#" onclick="window.print();return false">
      <i class="fa-solid fa-print"></i> Cetak
    </a>
    <a class="btn outline" href="<?= asset_url('pages/transaksi/index.php') ?>">
      <i class="fa-solid fa-arrow-left"></i> Kembali
    </a>
  </form>
</div>

<!-- Header kecil (periode) -->
<div class="noprint" style="display:flex;justify-content:space-between;align-items:flex-start;margin-top:-6px">
  <div class="muted">Periode</div>
  <div><b><?= e(date('d M Y', strtotime($from))) ?> – <?= e(date('d M Y', strtotime($to))) ?></b></div>
</div>

<!-- Ringkasan -->
<div class="summary">
  <div class="chip">Pemasukan: <b><?= rupiah($sumIn) ?></b></div>
  <div class="chip">Pengeluaran: <b><?= rupiah($sumOut) ?></b></div>
  <div class="chip">Saldo (In − Out): <b><?= rupiah($saldo) ?></b></div>
  <div class="chip">Filter: <b><?= e(ucfirst($filter)) ?></b></div>
</div>

<table>
  <thead>
    <tr>
      <th style="width:110px">Tanggal</th>
      <th style="width:120px">Tipe</th>
      <th class="right" style="width:140px">Nominal</th>
      <th style="width:120px"></th>
      <th>Keterangan</th>
      <th style="width:160px">Dibuat Oleh</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="6" class="muted">Tidak ada transaksi untuk filter ini.</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td><?= e(!empty($r['tgl']) ? date('d M Y', strtotime($r['tgl'])) : '') ?></td>
        <td><?= e(ucfirst($r['tipe'] ?? '')) ?></td>
        <td class="right"><?= rupiah($r['nominal'] ?? 0) ?></td>
        <td><?= e($r['method'] ?? '') ?></td>
        <td><?= e($r['keterangan'] ?? '') ?></td>
        <td><?= e($r['created_by_name'] ?? ($r['created_by'] ?? '')) ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<div class="footer">
  <div>Dicetak: <?= date('d M Y H:i') ?> • Oleh: <?= e($_SESSION['user']['username'] ?? 'User') ?></div>
  <div>CV Gelora Maju Bersama</div>
</div>

</body>
</html>
