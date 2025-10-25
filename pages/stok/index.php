<?php
// pages/stok/index.php (final — tanpa tombol Adjust)
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_operasional']);

$_active = 'stok';
$page_title = 'Pengelolaan Stok';

/* params */
$q     = trim($_GET['q'] ?? '');
$kat   = trim($_GET['kategori'] ?? '');
$minq  = isset($_GET['min_qty']) ? (int)$_GET['min_qty'] : 0;
$wh_id = isset($_GET['warehouse']) ? (int)$_GET['warehouse'] : 0;

$where = [];
$params = [];

/* Base FROM / JOIN */
$baseJoin = "FROM stock_balances sb
             JOIN products p ON p.id = sb.product_id
             LEFT JOIN warehouses w ON w.id = sb.warehouse_id";

/* filters (guarded by column existence) */
if ($q !== '') {
  if (column_exists('products','sku')) {
    $where[] = "(p.name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%";
  } else {
    $where[] = "p.name LIKE ?";
    $params[] = "%$q%";
  }
}
if ($kat !== '') {
  if (column_exists('products','kategori')) {
    $where[] = "p.kategori = ?";
    $params[] = $kat;
  }
}
if ($minq > 0) {
  $where[] = "sb.quantity >= ?";
  $params[] = $minq;
}
if ($wh_id > 0) {
  $where[] = "sb.warehouse_id = ?";
  $params[] = $wh_id;
}

$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* Build SELECT list dynamically depending on available columns in products */
$selectParts = [];
$selectParts[] = "p.name AS product_name";

if (column_exists('products','sku')) $selectParts[] = "p.sku";
else $selectParts[] = "'' AS sku";

if (column_exists('products','kategori')) $selectParts[] = "p.kategori";
else $selectParts[] = "'' AS kategori";

if (column_exists('products','selling_price')) $selectParts[] = "COALESCE(p.selling_price,0) AS harga_jual";
else $selectParts[] = "0 AS harga_jual";

if (column_exists('products','unit')) $selectParts[] = "COALESCE(p.unit,'gulung') AS satuan";
else $selectParts[] = "'gulung' AS satuan";

if (column_exists('products','image')) $selectParts[] = "COALESCE(p.image,'') AS image";
else $selectParts[] = "'' AS image";

$select = "SELECT sb.id AS sb_id, sb.product_id, sb.warehouse_id, sb.quantity, sb.last_updated, " . implode(", ", $selectParts) . ", COALESCE(w.name,'-') AS warehouse_name";

/* KPI queries (safe) */
$totalJenis = fetch("SELECT COUNT(DISTINCT sb.product_id) AS c $baseJoin $wsql", $params)['c'] ?? 0;
$totalQty   = fetch("SELECT COALESCE(SUM(sb.quantity),0) AS s $baseJoin $wsql", $params)['s'] ?? 0;

/* Fetch rows */
$sql = "
  $select
  $baseJoin
  $wsql
  ORDER BY p.name ASC, sb.quantity DESC
  LIMIT 1000
";
$rows = fetchAll($sql, $params);

/* categories + warehouses for filters */
$kategories = [];
if (column_exists('products','kategori')) {
  $kategories = fetchAll("SELECT DISTINCT kategori FROM products WHERE kategori IS NOT NULL AND kategori<>'' ORDER BY kategori");
}
$warehouses = fetchAll("SELECT id,name FROM warehouses ORDER BY name");

include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card">
  <h2>Pengelolaan Stok</h2>

  <div class="kpi">
    <div class="card"><div class="muted">Jumlah Jenis</div><div style="font-weight:700;height:45px;font-size:28px"><?= (int)$totalJenis ?></div></div>
    <div class="card"><div class="muted">Total Qty</div><div style="font-weight:700;height:45px;font-size:28px"><?= (int)$totalQty ?></div></div>
  </div>

  <form class="no-print" method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
    <input type="text" name="q" placeholder="Cari nama atau SKU..." value="<?= e($q) ?>">
    
      <?php foreach($kategories as $k): $v = is_array($k) ? ($k['kategori'] ?? '') : $k; if ($v==='') continue; ?>
        <option value="<?= e($v) ?>" <?= $v === $kat ? 'selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="warehouse">
      <option value="0">Semua Gudang</option>
      <?php foreach($warehouses as $w): ?>
        <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $wh_id ? 'selected' : '' ?>><?= e($w['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Min Qty</label><input type="number" name="min_qty" min="0" value="<?= e($minq) ?>">

    <button class="btn" type="submit">Tampilkan</button>
    <!-- tombol Tambah dihilangkan — stok hanya bertambah via Pembelian -->
  </form>

  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>FOTO</th><th>PRODUK</th><th>SKU</th><th>QTY</th><th>SATUAN</th><th>HARGA JUAL</th><th>GUDANG</th><th class="no-print">AKSI</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="muted">Tidak ada data.</td></tr>
        <?php else: foreach($rows as $r):
          $img = trim($r['image'] ?? '');
          $imgUrl = null;
          if ($img) {
            $abs = APP_DIR . '/' . str_replace('/', DIRECTORY_SEPARATOR, $img);
            if (is_file($abs)) $imgUrl = asset_url($img).'?v='.urlencode(@filemtime($abs));
          }
        ?>
          <tr>
            <td style="width:90px">
              <?php if ($imgUrl): ?>
                <img src="<?= $imgUrl ?>" alt="<?= e($r['product_name']) ?>" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #eee">
              <?php else: ?>
                <div style="width:64px;height:64px;border:1px dashed #cfcfcf;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:12px">No Foto</div>
              <?php endif; ?>
            </td>
            <td><div style="font-weight:600;color:var(--text)"><?= e($r['product_name']) ?></div></td>
            <td><?= e($r['sku'] ?? '-') ?></td>
            <td><?= (int)$r['quantity'] ?></td>
            <td><?= e($r['satuan'] ?? 'gulung') ?></td>
            <td><?= rupiah($r['harga_jual'] ?? 0) ?></td>
            <td><?= e($r['warehouse_name'] ?? '-') ?></td>
              <td class="no-print actions-td" style="white-space:nowrap">
              <a class="icon-btn neutral" title="Edit" href="<?= asset_url('pages/stok/edit.php?sb_id='.(int)$r['sb_id']) ?>"><i class="fas fa-pen"></i></a>
              <form method="post" action="<?= asset_url('pages/stok/delete.php') ?>" style="display:inline" onsubmit="return confirm('Hapus item stok ini? (Hanya jika qty = 0)')">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="sb_id" value="<?= (int)$r['sb_id'] ?>">
                <button class="icon-btn danger" title="Hapus" type="submit"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
