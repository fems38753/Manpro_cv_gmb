<?php
// pages/penjualan/index.php (robust + status fix)
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

$_active='penjualan';
$page_title='Penjualan';

/* --------------------------
   DETEKSI KOLOM DINAMIS PENTING
   -------------------------- */
$pk = pick_column('penjualan', ['id','penjualan_id','sale_id','id_penjualan']) ?: 'id';
$date_col = pick_date_column('penjualan'); // bisa null
$total_col = pick_total_column('penjualan') ?: null;
$product_col = pick_column('penjualan', ['product_id','produk','product','nama_produk','productId']) ?: null;
$qty_col = pick_column('penjualan', ['qty','jumlah','quantity','qty_jual']) ?: null;
$price_col = pick_column('penjualan', ['harga_satuan','harga','price','harga_jual','unit_price']) ?: null;
$customer_col = pick_customer_column('penjualan') ?: null;

/* ---------- DETEKSI KOLOM GUDANG & JOIN ---------- */
$warehouse_col = pick_column('penjualan', ['warehouse_id','warehouse','gudang_id','gudang','warehouseid']);
$join_warehouse = false;
$warehouse_name_select = "'' AS warehouse_name";
$warehouse_join_sql = '';

if ($warehouse_col) {
  if (table_exists('warehouses')) {
    $wh_pk = pick_column('warehouses', ['id','warehouse_id','wh_id']) ?: 'id';
    $wh_name = pick_column('warehouses', ['name','nama','warehouse_name']) ?: null;

    // check if penjualan.$warehouse_col is numeric (best-effort)
    $is_fk_numeric = false;
    try {
      global $pdo,$dbname;
      $st = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='penjualan' AND COLUMN_NAME=? LIMIT 1");
      $st->execute([$dbname,$warehouse_col]);
      $dtype = $st->fetchColumn();
      if ($dtype && in_array(strtolower($dtype), ['int','bigint','smallint','mediumint','tinyint'], true)) $is_fk_numeric = true;
    } catch (Throwable $e){ $is_fk_numeric=false; }

    if ($is_fk_numeric && $wh_name) {
      $join_warehouse = true;
      $warehouse_name_select = "COALESCE(w.`$wh_name`,'-') AS warehouse_name";
      $warehouse_join_sql = "LEFT JOIN warehouses w ON w.`$wh_pk` = pn.`$warehouse_col`";
    } else {
      $warehouse_name_select = "COALESCE(pn.`$warehouse_col`,'-') AS warehouse_name";
    }
  } else {
    $warehouse_name_select = "COALESCE(pn.`$warehouse_col`,'-') AS warehouse_name";
  }
} else {
  $warehouse_name_select = "'-' AS warehouse_name";
}

/* ---------- DETEKSI STATUS / PAYMENT_STATUS ---------- */
$payment_status_col = pick_column('penjualan', ['payment_status','status_pembayaran','status','payment_state']) ?: null;
$payment_method_col = pick_column('penjualan', ['payment_method','method','metode','metode_pembayaran']) ?: null;

/* --------------------------
   DETEKSI PRODUK (join ke products jika ada & FK)
   -------------------------- */
$product_select = "'' AS product_name";
$product_join_sql = '';
if ($product_col) {
  if (table_exists('products')) {
    $pr_pk = pick_column('products', ['id','product_id']) ?: 'id';
    $pr_name = pick_column('products', ['name','nama','product_name']) ?: null;

    $is_prod_fk = false;
    try {
      global $pdo,$dbname;
      $st = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='penjualan' AND COLUMN_NAME=? LIMIT 1");
      $st->execute([$dbname,$product_col]);
      $dtype = $st->fetchColumn();
      if ($dtype && in_array(strtolower($dtype), ['int','bigint','smallint','mediumint','tinyint'], true)) $is_prod_fk = true;
    } catch (Throwable $e){ $is_prod_fk=false; }

    if ($is_prod_fk && $pr_name) {
      $product_select = "COALESCE(pr.`$pr_name`, CONCAT('Produk #', pn.`$product_col`)) AS product_name";
      $product_join_sql = "LEFT JOIN products pr ON pr.`$pr_pk` = pn.`$product_col`";
    } else {
      $product_select = "COALESCE(pn.`$product_col`,'-') AS product_name";
    }
  } else {
    $product_select = "COALESCE(pn.`$product_col`,'-') AS product_name";
  }
} else {
  $product_select = "'' AS product_name";
}

/* --------------------------
   FILTERS
   -------------------------- */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-t');
$cust = trim($_GET['customer'] ?? '');

$where = []; $params = [];
if ($date_col) { $where[] = "pn.`$date_col` BETWEEN ? AND ?"; $params[] = $from; $params[] = $to;}
if ($cust !== '' && $customer_col) { $where[] = "pn.`$customer_col` LIKE ?"; $params[] = "%$cust%";}
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* --------------------------
   SELECT LIST
   -------------------------- */
$select_list = [
  "pn.`$pk` AS id",
  ($date_col ? "pn.`$date_col` AS tgl" : "NULL AS tgl"),
  ($customer_col ? "pn.`$customer_col` AS customer" : "'' AS customer"),
  $product_select,
  $warehouse_name_select,
  ($qty_col ? "pn.`$qty_col` AS qty" : "NULL AS qty"),
  ($price_col ? "pn.`$price_col` AS harga_satuan" : "NULL AS harga_satuan"),
  ($total_col ? "pn.`$total_col` AS total_harga" : ( ($qty_col && $price_col) ? "(COALESCE(pn.`$qty_col`,0)*COALESCE(pn.`$price_col`,0)) AS total_harga" : "0 AS total_harga")),
  ($payment_status_col ? "pn.`$payment_status_col` AS payment_status" : "'' AS payment_status"),
  ($payment_method_col ? "pn.`$payment_method_col` AS payment_method" : "'' AS payment_method"),
];

$from_sql = "FROM penjualan pn";
if ($product_join_sql) $from_sql .= " " . $product_join_sql;
if ($warehouse_join_sql) $from_sql .= " " . $warehouse_join_sql;

$sql = "SELECT ".implode(", ", $select_list)." $from_sql $wsql ORDER BY ".($date_col ? "pn.`$date_col` DESC, " : "")."pn.`$pk` DESC LIMIT 500";

try {
  $rows = fetchAll($sql, $params);
} catch (Throwable $e) {
  // fallback: no rows
  $rows = [];
}

/* total summary */
$sum = 0.0;
try {
  if ($total_col) {
    $r = fetch("SELECT COALESCE(SUM(pn.`$total_col`),0) AS s $from_sql $wsql", $params);
    $sum = (float)($r['s'] ?? 0);
  } elseif ($qty_col && $price_col) {
    $r = fetch("SELECT COALESCE(SUM(COALESCE(pn.`$qty_col`,0)*COALESCE(pn.`$price_col`,0)),0) AS s $from_sql $wsql", $params);
    $sum = (float)($r['s'] ?? 0);
  }
} catch (Throwable $e) { $sum = 0.0; }

$msg = $_GET['msg'] ?? '';

include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card">
  <h2 style="margin-bottom:8px">Penjualan</h2>

  <?php if($msg): ?>
    <div style="background:#e9f9ee;border:1px solid #bfe6cc;color:#256a3b;padding:10px 12px;border-radius:10px;margin-bottom:10px"><?= e($msg) ?></div>
  <?php endif; ?>

  <div class="kpi">
    <div class="card"><div class="muted">Total Penjualan <?= $date_col? '(periode)':'' ?></div><h1><?= rupiah($sum) ?></h1></div>
  </div>

  <form class="no-print" method="get" style="display:flex;gap:10px;align-items:center;margin:10px 0;flex-wrap:wrap">
    <label>Dari</label><input type="date" name="from" value="<?= e($from) ?>" <?= $date_col ? '' : 'disabled' ?>>
    <label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>" <?= $date_col ? '' : 'disabled' ?>>
    <label>Customer</label><input type="text" name="customer" value="<?= e($cust) ?>" placeholder="Toko / PT ...">
    <button class="btn" type="submit"><i class="fas fa-filter"></i> Tampilkan</button>
    <a class="btn outline" target="_blank" href="<?= asset_url('pages/penjualan/print.php?from='.urlencode($from).'&to='.urlencode($to).'&customer='.urlencode($cust)) ?>"><i class="fas fa-print"></i> Cetak</a>
    <a class="btn" href="<?= asset_url('pages/penjualan/add.php') ?>"><i class="fas fa-plus"></i> Tambah</a>
  </form>

  <table class="table">
    <thead>
      <tr>
        <th><?= $date_col ? 'Tanggal' : 'Tanggal (—)' ?></th>
        <th>Customer</th>
        <th>Produk</th>
        <th>Gudang</th>
        <th>Qty</th>
        <th>Harga Satuan</th>
        <th>Total</th>
        <th>Status</th>
        <th class="no-print">Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if(!$rows): ?><tr><td colspan="9" class="muted">Belum ada data.</td></tr><?php endif; ?>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= $r['tgl'] ? e(date('d M Y', strtotime($r['tgl']))) : '<span class="muted">—</span>' ?></td>
          <td><?= e($r['customer'] ?? '-') ?></td>
          <td><?= e($r['product_name'] ?? '-') ?></td>
          <td><?= e($r['warehouse_name'] ?? '-') ?></td>
          <td><?= is_null($r['qty']) ? '<span class="muted">—</span>' : number_format($r['qty']) ?></td>
          <td><?= is_null($r['harga_satuan']) ? '<span class="muted">—</span>' : rupiah($r['harga_satuan']) ?></td>
          <td><?= rupiah($r['total_harga'] ?? 0) ?></td>
          <td>
            <?php
              $st = trim((string)($r['payment_status'] ?? $r['status'] ?? ''));
              if ($st === '') echo '<span class="muted">—</span>';
              else echo e(ucfirst(strtolower($st)));
            ?>
          </td>
          <td class="no-print" style="white-space:nowrap;display:flex;gap:10px">
            <!-- Edit -->
            <a class="icon-btn neutral" title="Edit Penjualan" href="<?=asset_url('pages/penjualan/edit.php?id='.$r['id'])?>">
              <i class="fas fa-pen"></i>
            </a>
            <!-- Delete -->
            <form method="post" action="<?=asset_url('pages/penjualan/delete.php')?>" style="display:inline" onsubmit="return confirm('Hapus pembelian ini?')">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="icon-btn danger" type="submit" title="Hapus Pembelian">
                <i class="fas fa-trash"></i>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
