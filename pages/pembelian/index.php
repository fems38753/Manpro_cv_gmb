<?php
// pages/pembelian/index.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_operasional','staff_keuangan']);

$_active    = 'pembelian';
$page_title = 'Pembelian';

/* ====== Filter ====== */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-t');
$supQ = trim($_GET['supplier'] ?? '');

$where  = [];
$params = [];
if ($from && $to) { $where[] = "p.date BETWEEN ? AND ?"; $params[] = $from; $params[] = $to; }
if ($supQ !== '') { $where[] = "s.name LIKE ?";          $params[] = "%$supQ%"; }
$wsql = $where ? 'WHERE '.implode(' AND ', $where) : '';

/* ====== Kolom opsional di purchases ====== */
$hasStatus   = column_exists('purchases','payment_status');
$hasMethod   = column_exists('purchases','payment_method');
$hasTotal    = column_exists('purchases','total');
$hasSubtotal = column_exists('purchases','subtotal');
$hasTax      = column_exists('purchases','tax');
$hasInvoice  = column_exists('purchases','invoice_no');
$hasCreated  = column_exists('purchases','created_by');
$hasWh       = column_exists('purchases','warehouse_id');

/* ====== Ringkasan total periode ====== */
if ($hasTotal) {
  $sumRow = fetch("SELECT COALESCE(SUM(p.total),0) s FROM purchases p LEFT JOIN suppliers s ON p.supplier_id=s.id $wsql", $params);
  $sum = (float)($sumRow['s'] ?? 0);
} elseif ($hasSubtotal) {
  $sumRow = fetch("SELECT COALESCE(SUM(p.subtotal".($hasTax?"+p.tax":"")."),0) s
                   FROM purchases p LEFT JOIN suppliers s ON p.supplier_id=s.id $wsql", $params);
  $sum = (float)($sumRow['s'] ?? 0);
} else {
  $sum = 0.0;
}

/* ====== SELECT aman ====== */
$select = [
  "p.id AS purchase_id",
  "p.date",
];
if ($hasInvoice)  $select[] = "p.invoice_no";
if ($hasTotal)    $select[] = "p.total";
elseif ($hasSubtotal) {
  $select[] = "p.subtotal".($hasTax?"+p.tax":"")." AS total";
} else {
  $select[] = "0 AS total";
}
if ($hasStatus)   $select[] = "p.payment_status"; else $select[] = "'' AS payment_status";
if ($hasMethod)   $select[] = "p.payment_method"; else $select[] = "'' AS payment_method";
if ($hasCreated)  $select[] = "p.created_by";     else $select[] = "NULL AS created_by";
if ($hasWh)       $select[] = "p.warehouse_id";   else $select[] = "NULL AS warehouse_id";

$select[] = "COALESCE(s.name,'') AS supplier";
$select[] = "pi.id AS item_id";
$select[] = "pi.product_id";
$select[] = "pi.qty";
$select[] = "pi.unit_price";
$select[] = "COALESCE(pr.name,'')  AS product_name";
$select[] = "COALESCE(pr.image,'') AS product_image";

$sql = "
  SELECT ".implode(', ', $select)."
  FROM purchases p
  LEFT JOIN suppliers s ON p.supplier_id = s.id
  LEFT JOIN purchase_items pi ON pi.purchase_id = p.id
  LEFT JOIN products pr ON pr.id = pi.product_id
  $wsql
  ORDER BY p.date DESC, p.id DESC
  LIMIT 500
";
$rows = fetchAll($sql, $params);

include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card">
  <h2>Pembelian</h2>

  <div class="kpi">
    <div class="card"><div class="muted">Total Pembelian (periode)</div><h1><?= rupiah($sum) ?></h1></div>
  </div>

  <form class="no-print" method="get" style="display:flex;gap:10px;align-items:center;margin:10px 0;flex-wrap:wrap">
    <label>Dari</label><input type="date" name="from" value="<?= e($from) ?>">
    <label>Sampai</label><input type="date" name="to"   value="<?= e($to) ?>">
    <label>Supplier</label><input type="text" name="supplier" value="<?= e($supQ) ?>" placeholder="PT Contoh">
    <button class="btn small" type="submit"><i class="fas fa-filter"></i> Tampilkan</button>

    <!-- Aksi -->
    <a class="btn small" href="<?= asset_url('pages/pembelian/add_product.php') ?>"><i class="fas fa-box-open"></i> Tambah Produk Baru</a>
    <a class="btn small" href="<?= asset_url('pages/pembelian/add_stock.php') ?>"><i class="fas fa-plus-circle"></i> Tambah Stok</a>
  </form>

  <table class="table">
    <thead>
      <tr>
        <th>Produk</th>
        <th>Supplier</th>
        <th>Qty</th>
        <th>Harga Satuan</th>
        <th>Total</th>
        <th>Metode</th>
        <th>Status</th>
        <th class="no-print">Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="muted">Belum ada data.</td></tr>
      <?php else: foreach($rows as $r):
        $url = null;
        if (!empty($r['product_image'])) {
          $abs = APP_DIR . '/' . str_replace('/', DIRECTORY_SEPARATOR, $r['product_image']);
          if (is_file($abs)) $url = asset_url($r['product_image']).'?v='.urlencode(@filemtime($abs));
        }
        $whName = '-';
        if (!empty($r['warehouse_id'])) {
          $whrec = fetch("SELECT name FROM warehouses WHERE id=? LIMIT 1", [(int)$r['warehouse_id']]);
          if ($whrec) $whName = $whrec['name'];
        }
      ?>
      <tr>
        <td style="display:flex;gap:10px;align-items:center">
          <?php if($url): ?>
            <img src="<?= $url ?>" alt="<?= e($r['product_name']) ?>" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #eee">
          <?php else: ?>
            <div style="width:64px;height:64px;border:1px dashed #eee;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:12px">No<br>Foto</div>
          <?php endif; ?>
          <div>
            <div style="font-weight:600"><?= e($r['product_name']) ?></div>
            <div class="muted" style="font-size:12px">Gudang: <?= e($whName) ?></div>
          </div>
        </td>
        <td><?= e($r['supplier']) ?></td>
        <td style="text-align:center"><?= number_format((float)$r['qty']) ?></td>
        <td style="text-align:center"><?= rupiah($r['unit_price']) ?></td>
        <td style="text-align:center"><?= rupiah($r['total']) ?></td>
        <td><?= e($r['payment_method'] ?: '-') ?></td>
        <td><?= e($r['payment_status'] ? ucfirst($r['payment_status']) : '-') ?></td>
        <td class="no-print actions-td">
          <a class="icon-btn neutral" title="Edit Pembelian" href="<?= asset_url('pages/pembelian/edit.php?id='.(int)$r['purchase_id']) ?>">
            <i class="fas fa-pen"></i>
          </a>
          <form method="post" action="<?= asset_url('pages/pembelian/delete.php') ?>" style="display:inline" onsubmit="return confirm('Hapus pembelian ini?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id"   value="<?= (int)$r['purchase_id'] ?>">
            <button class="icon-btn danger" type="submit" title="Hapus Pembelian"><i class="fas fa-trash"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../_partials/layout_end.php';
