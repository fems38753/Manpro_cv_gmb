<?php
// pages/penjualan/index.php — multi-item view, auto-pick kolom yang ada
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

$_active='penjualan';
$page_title='Penjualan';

/* =========== util pick kolom =========== */
if (!function_exists('pick_col')) {
  function pick_col(string $table, array $cands, ?string $fallback=null){
    foreach ($cands as $c) if (column_exists($table, $c)) return $c;
    return $fallback;
  }
}

/* =========== pilih kolom yang tersedia =========== */
/* sales.* */
$sale_pk        = pick_col('sales', ['id','sale_id'], 'id');
$sale_date_col  = pick_col('sales', ['sale_date','date','tgl_penjualan','tgl','created_at'], 'created_at');
$sale_cust_col  = pick_col('sales', ['customer_name','customer','nama_customer','buyer','toko'], null);
$sale_total_col = pick_col('sales', ['total','grand_total','amount'], null);
$sale_pay_col   = pick_col('sales', ['payment_status','status_pembayaran','status'], null);

/* sale_items.* (untuk baris item) */
$si_sale_fk   = pick_col('sale_items', ['sale_id','sales_id'], 'sale_id');
$si_prod_col  = pick_col('sale_items', ['product_id','produk_id'], 'product_id');
$si_wh_col    = pick_col('sale_items', ['warehouse_id','gudang_id'], 'warehouse_id');
$si_qty_col   = pick_col('sale_items', ['qty','quantity','jumlah'], 'qty');
$si_price_col = pick_col('sale_items', ['unit_price','harga_satuan','price'], 'unit_price');
$si_sub_col   = pick_col('sale_items', ['subtotal','total_harga','amount'], 'subtotal');

/* =========== filter periode & customer =========== */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-t');
$cust = trim($_GET['customer'] ?? '');

$where  = ["s.`$sale_date_col` BETWEEN ? AND ?"];
$params = [$from, $to];
if ($cust !== '' && $sale_cust_col) { $where[] = "s.`$sale_cust_col` LIKE ?"; $params[] = "%$cust%"; }
$wsql = 'WHERE '.implode(' AND ', $where);

/* =========== data baris (per item) =========== */
$sql = "
  SELECT
    s.`$sale_pk`                            AS sale_id,
    s.`$sale_date_col`                      AS tgl,
    ".($sale_cust_col ? "COALESCE(s.`$sale_cust_col`,'-')" : "''")." AS customer,
    ".($sale_pay_col  ? "COALESCE(s.`$sale_pay_col`,'')"   : "''")." AS payment_status,

    si.`$si_qty_col`                        AS qty,
    si.`$si_price_col`                      AS harga_satuan,
    si.`$si_sub_col`                        AS total_harga,

    COALESCE(p.name,'(Produk)')             AS product_name,
    COALESCE(w.name,'-')                    AS warehouse_name
  FROM sales s
  JOIN sale_items si     ON si.`$si_sale_fk` = s.`$sale_pk`
  LEFT JOIN products p   ON p.id = si.`$si_prod_col`
  LEFT JOIN warehouses w ON w.id = si.`$si_wh_col`
  $wsql
  ORDER BY s.`$sale_date_col` DESC, s.`$sale_pk` DESC, si.id ASC
  LIMIT 1000
";
$rows = fetchAll($sql, $params);

/* =========== total periode (KPI) =========== */
/* kalau sales.total ada, pakai itu; kalau tidak ada, sum dari item */
if ($sale_total_col) {
  $r   = fetch("SELECT COALESCE(SUM(s.`$sale_total_col`),0) s FROM sales s $wsql", $params);
  $sum = (float)($r['s'] ?? 0);
} else {
  $r   = fetch("
    SELECT COALESCE(SUM(si.`$si_sub_col`),0) s
    FROM sales s
    JOIN sale_items si ON si.`$si_sale_fk` = s.`$sale_pk`
    $wsql
  ", $params);
  $sum = (float)($r['s'] ?? 0);
}

$msg = $_GET['msg'] ?? '';

include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card">
  <h2 style="margin-bottom:8px">Penjualan</h2>

  <?php if($msg): ?>
    <div style="background:#e9f9ee;border:1px solid #bfe6cc;color:#256a3b;padding:10px 12px;border-radius:10px;margin-bottom:10px"><?= e($msg) ?></div>
  <?php endif; ?>

  <div class="kpi">
    <div class="card"><div class="muted">Total Penjualan (periode)</div><h1><?= rupiah($sum) ?></h1></div>
  </div>

  <form class="no-print" method="get" style="display:flex;gap:10px;align-items:center;margin:10px 0;flex-wrap:wrap">
    <label>Dari</label><input type="date" name="from" value="<?= e($from) ?>">
    <label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>">
    <label>Customer</label><input type="text" name="customer" value="<?= e($cust) ?>" placeholder="Toko / PT ...">
    <button class="btn" type="submit"><i class="fas fa-filter"></i> Tampilkan</button>
    <a class="btn" href="<?= asset_url('pages/penjualan/add.php') ?>"><i class="fas fa-plus"></i> Tambah</a>
  </form>

  <table class="table">
    <thead>
      <tr>
        <th>Tanggal</th>
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
          <td><?= e(date('d M Y', strtotime($r['tgl']))) ?></td>
          <td><?= e($r['customer'] ?? '-') ?></td>
          <td><?= e($r['product_name'] ?? '-') ?></td>
          <td><?= e($r['warehouse_name'] ?? '-') ?></td>
          <td><?= number_format((float)$r['qty']) ?></td>
          <td><?= rupiah($r['harga_satuan']) ?></td>
          <td><?= rupiah($r['total_harga']) ?></td>
          <td><?= e($r['payment_status'] ? ucfirst(strtolower($r['payment_status'])) : '-') ?></td>
          <td class="no-print" style="white-space:nowrap;display:flex;gap:10px">
            <a class="btn outline" href="<?= asset_url('pages/penjualan/invoice.php?id='.(int)$r['sale_id']) ?>">Invoice</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../_partials/layout_end.php';
