<?php
// pages/penjualan/print.php — print layout + filter periode & aksi
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

$_active    = 'penjualan';
$page_title = 'Laporan Penjualan';

// params (GET)
$from = $_GET['from'] ?? null;
$to   = $_GET['to'] ?? null;
$cust = trim($_GET['customer'] ?? '');

/* ---------- detect columns (robust) ---------- */
$pk                 = pick_column('penjualan', ['id','penjualan_id','sale_id','id_penjualan']) ?: 'id';
$date_col           = pick_date_column('penjualan');    // boleh null
$total_col          = pick_total_column('penjualan');   // boleh null
$qty_col            = pick_column('penjualan', ['qty','jumlah','quantity','qty_jual']) ?: null;
$price_col          = pick_column('penjualan', ['harga_satuan','harga','price','harga_jual','unit_price']) ?: null;
$customer_col       = pick_customer_column('penjualan') ?: null;
$product_ref_col    = pick_column('penjualan', ['product_id','produk','product','productname','nama_produk']) ?: null;
$warehouse_col      = pick_column('penjualan', ['warehouse_id','warehouse','gudang_id','gudang','warehouseid']) ?: null;
$payment_status_col = pick_column('penjualan', ['payment_status','status_pembayaran','status','payment_state']) ?: null;
$payment_method_col = pick_column('penjualan', ['payment_method','method','metode','metode_pembayaran']) ?: null;

/* ---------- WHERE (prefix pn.) ---------- */
$where = []; $params = [];
if ($date_col && $from && $to && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) {
  $where[] = "pn.`$date_col` BETWEEN ? AND ?"; $params[] = $from; $params[] = $to;
}
if ($cust !== '' && $customer_col) {
  $where[] = "pn.`$customer_col` LIKE ?"; $params[] = "%$cust%";
}
$wsql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ---------- product select & join ---------- */
$product_select = "'' AS product_name"; $product_join_sql = '';
if ($product_ref_col) {
  if (table_exists('products')) {
    $pr_pk = pick_column('products', ['id','product_id']) ?: 'id';
    $pr_name = pick_column('products', ['name','nama']) ?: null;
    $is_fk = false;
    try {
      global $pdo,$dbname;
      $st = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='penjualan' AND COLUMN_NAME=? LIMIT 1");
      $st->execute([$dbname,$product_ref_col]);
      $dtype = $st->fetchColumn();
      if ($dtype && in_array(strtolower($dtype), ['int','bigint','smallint','mediumint','tinyint'], true)) $is_fk = true;
    } catch (Throwable $e) { $is_fk=false; }
    if ($is_fk && $pr_name) {
      $product_select = "COALESCE(pr.`$pr_name`, CONCAT('Produk #', pn.`$product_ref_col`)) AS product_name";
      $product_join_sql = "LEFT JOIN products pr ON pr.`$pr_pk` = pn.`$product_ref_col`";
    } else {
      $product_select = "COALESCE(pn.`$product_ref_col`,'-') AS product_name";
    }
  } else {
    $product_select = "COALESCE(pn.`$product_ref_col`,'-') AS product_name";
  }
}

/* ---------- warehouse select & join ---------- */
$warehouse_select = "'-' AS warehouse_name";
$warehouse_join_sql = '';
if ($warehouse_col) {
  if (table_exists('warehouses')) {
    $wh_pk = pick_column('warehouses', ['id','warehouse_id']) ?: 'id';
    $wh_name = pick_column('warehouses', ['name','nama']) ?: null;
    $is_fk = false;
    try {
      global $pdo,$dbname;
      $st = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='penjualan' AND COLUMN_NAME=? LIMIT 1");
      $st->execute([$dbname,$warehouse_col]);
      $dtype = $st->fetchColumn();
      if ($dtype && in_array(strtolower($dtype), ['int','bigint','smallint','mediumint','tinyint'], true)) $is_fk=true;
    } catch (Throwable $e){ $is_fk=false; }
    if ($is_fk && $wh_name) {
      $warehouse_select = "COALESCE(w.`$wh_name`,'-') AS warehouse_name";
      $warehouse_join_sql = "LEFT JOIN warehouses w ON w.`$wh_pk` = pn.`$warehouse_col`";
    } else {
      $warehouse_select = "COALESCE(pn.`$warehouse_col`,'-') AS warehouse_name";
    }
  } else {
    $warehouse_select = "COALESCE(pn.`$warehouse_col`,'-') AS warehouse_name";
  }
}

/* ---------- build select list ---------- */
$selects = [
  "pn.`$pk` AS id",
  ($date_col ? "pn.`$date_col` AS tgl" : "NULL AS tgl"),
  ($customer_col ? "pn.`$customer_col` AS customer" : "'' AS customer"),
  $product_select,
  $warehouse_select,
  ($qty_col ? "pn.`$qty_col` AS qty" : "NULL AS qty"),
  ($price_col ? "pn.`$price_col` AS harga_satuan" : "NULL AS harga_satuan"),
  ($total_col ? "pn.`$total_col` AS total_harga" : ( ($qty_col && $price_col) ? "(COALESCE(pn.`$qty_col`,0)*COALESCE(pn.`$price_col`,0)) AS total_harga" : "0 AS total_harga")),
  ($payment_status_col ? "pn.`$payment_status_col` AS payment_status" : "'' AS payment_status"),
  ($payment_method_col ? "pn.`$payment_method_col` AS payment_method" : "'' AS payment_method"),
];

$from_sql = "FROM penjualan pn";
if ($product_join_sql) $from_sql .= " " . $product_join_sql;
if ($warehouse_join_sql) $from_sql .= " " . $warehouse_join_sql;

/* ---------- fetch rows ---------- */
$sql = "SELECT ".implode(", ", $selects)." $from_sql $wsql ORDER BY ".($date_col ? "pn.`$date_col` ASC, " : "")."pn.`$pk` ASC";
try { $rows = fetchAll($sql, $params); } catch(Throwable $e){ $rows = []; }

/* ---------- total ---------- */
$sumTotal = 0.0;
try {
  if ($total_col) {
    $r = fetch("SELECT COALESCE(SUM(pn.`$total_col`),0) s $from_sql $wsql", $params);
    $sumTotal = (float)($r['s'] ?? 0);
  } elseif ($qty_col && $price_col) {
    $r = fetch("SELECT COALESCE(SUM(COALESCE(pn.`$qty_col`,0)*COALESCE(pn.`$price_col`,0)),0) s $from_sql $wsql", $params);
    $sumTotal = (float)($r['s'] ?? 0);
  }
} catch(Throwable $e){ $sumTotal = 0.0; }

/* ---------- header logo (if available) ---------- */
$logo_path = 'assets/img/logo.png';
$logo_abs  = APP_DIR . '/' . $logo_path;
$logo_url  = is_file($logo_abs) ? asset_url($logo_path) : null;
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Penjualan • CV Gelora Maju Bersama</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  @page{size:A4 portrait;margin:16mm}
  body{font:13px/1.55 system-ui,Segoe UI,Roboto,Arial;color:#222}
  .brand{display:flex;align-items:center;gap:12px}
  .brand img{height:44px}
  .brand h1{margin:0;font-size:20px;color:#59382B}
  .muted{color:#7b8794}

  .noprint{margin-bottom:12px}
  .topbar{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}
  .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

  .filter-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
  .filter-form .field{display:flex;flex-direction:column;gap:6px}
  .filter-form label{font-size:12px;color:#7b8794}
  .filter-form input[type="date"],
  .filter-form input[type="text"]{
    height:34px;border:1px solid #eadccd;border-radius:8px;padding:4px 10px;min-width:160px
  }

  .btn{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;border:1px solid #e9d9c3;background:#fff7e8;color:#59382B;text-decoration:none;cursor:pointer}
  .btn:hover{filter:brightness(0.98)}
  .btn.primary{background:#f3e6d4;border-color:#e5cfad}
  .btn.outline{background:#fff;border:1px solid #eadccd}
  .btn i{min-width:14px;text-align:center}

  .chip{display:inline-block;border:1px solid #e9d9c3;padding:8px 10px;border-radius:10px;background:#fff7e8;margin-right:8px}
  table{width:100%;border-collapse:collapse;margin-top:12px}
  thead th{font-weight:700;color:#7b8794;text-transform:uppercase;font-size:12px;padding:8px 10px;border-bottom:1px solid #eee;text-align:left}
  td,th{padding:8px 10px;border-bottom:1px solid #eee}
  .right{text-align:right}

  @media print{
    .noprint{display:none}
  }
</style>
</head>
<body>

<div class="noprint topbar">
  <div style="display:flex;gap:12px;align-items:center">
    <?php if($logo_url): ?>
      <img src="<?= $logo_url ?>" alt="logo" style="height:48px;border-radius:6px;border:1px solid #eee">
    <?php endif; ?>
    <div>
      <div style="font-weight:800;font-size:20px;color:#59382B">CV Gelora Maju Bersama</div>
      <div class="muted">Laporan Penjualan</div>
    </div>
  </div>

  <!-- FORM FILTER: Periode & Tampilkan -->
  <form class="filter-form" method="get" action="">
    <div class="field">
      <label>Dari</label>
      <input type="date" name="from" value="<?= e($from ?? '') ?>">
    </div>
    <div class="field">
      <label>Sampai</label>
      <input type="date" name="to" value="<?= e($to ?? '') ?>">
    </div>
    <div class="field">
      <label>Customer (opsional)</label>
      <input type="text" name="customer" placeholder="Nama / kode customer" value="<?= e($cust) ?>">
    </div>
    <button class="btn primary" type="submit"><i class="fa-solid fa-filter"></i> Tampilkan</button>
    <?php if ($from || $to || $cust !== ''): ?>
      <a class="btn outline" href="<?= asset_url('pages/penjualan/print.php') ?>"><i class="fa-solid fa-rotate-left"></i> Reset</a>
    <?php endif; ?>
  </form>

  <!-- AKSI: Cetak & Kembali -->
  <div class="actions">
    <a class="btn" href="#" onclick="window.print();return false"><i class="fa-solid fa-print"></i> Cetak</a>
    <a class="btn outline" href="<?= asset_url('pages/penjualan/index.php') ?>"><i class="fa-solid fa-arrow-left"></i> Kembali</a>
  </div>
</div>

<!-- Ringkasan periode terpilih -->
<div style="margin:6px 0 10px 0">
  <span class="chip">
    Periode:
    <b>
      <?php
      if ($date_col && $from && $to) {
        echo e(date('d M Y', strtotime($from))) . ' – ' . e(date('d M Y', strtotime($to)));
      } else {
        echo '(tanpa filter tanggal)';
      }
      ?>
    </b>
  </span>
  <?php if ($cust !== ''): ?>
    <span class="chip">Filter Customer: <b><?= e($cust) ?></b></span>
  <?php endif; ?>
  <span class="chip">Total Penjualan: <b><?= rupiah($sumTotal) ?></b></span>
</div>

<table>
  <thead>
    <tr>
      <th style="width:110px">Tanggal</th>
      <th>Customer</th>
      <th>Produk</th>
      <th>Gudang</th>
      <th style="width:80px">Qty</th>
      <th style="width:120px">Harga</th>
      <th style="width:120px" class="right">Total</th>
      <th style="width:100px">Status</th>
      <th style="width:120px">Metode</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="9" class="muted">Tidak ada data.</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td><?= $r['tgl'] ? e(date('d M Y', strtotime($r['tgl']))) : '<span class="muted">—</span>' ?></td>
        <td><?= e($r['customer'] ?? '-') ?></td>
        <td><?= e($r['product_name'] ?? '-') ?></td>
        <td><?= e($r['warehouse_name'] ?? '-') ?></td>
        <td><?= is_null($r['qty']) ? '<span class="muted">—</span>' : number_format($r['qty']) ?></td>
        <td><?= is_null($r['harga_satuan']) ? '<span class="muted">—</span>' : rupiah($r['harga_satuan']) ?></td>
        <td class="right"><?= rupiah($r['total_harga'] ?? 0) ?></td>
        <td><?= ($r['payment_status'] ?? '') ? e(ucfirst(strtolower($r['payment_status']))) : '<span class="muted">—</span>' ?></td>
        <td><?= ($r['payment_method'] ?? '') ? e($r['payment_method']) : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
  <tfoot>
    <tr>
      <th colspan="6" class="right">TOTAL</th>
      <th class="right"><?= rupiah($sumTotal) ?></th>
      <th colspan="2"></th>
    </tr>
  </tfoot>
</table>

<div style="margin-top:14px;color:#7b8794;font-size:12px">
  Dicetak: <?= date('d M Y H:i') ?> • Oleh: <?= e($_SESSION['user']['username'] ?? 'User') ?> • CV Gelora Maju Bersama
</div>

</body>
</html>
