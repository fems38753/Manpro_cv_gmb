<?php
// pages/pembelian/index.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_operasional']);

$_active='pembelian';
$page_title='Pembelian';

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-t');
$supQ = trim($_GET['supplier'] ?? '');

$where=[]; $params=[];
if ($from && $to) { $where[] = "p.date BETWEEN ? AND ?"; $params[]=$from; $params[]=$to; }
if ($supQ !== '') { $where[] = "s.name LIKE ?"; $params[] = "%$supQ%"; }
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

// ringkasan total periode
$sumRow = fetch("SELECT COALESCE(SUM(p.total),0) s FROM purchases p LEFT JOIN suppliers s ON p.supplier_id=s.id $wsql", $params);
$sum = (float) ($sumRow['s'] ?? 0);

// ambil data (gabung purchase + purchase_items + products)
// NOTE: pi.product_name tidak diasumsikan ada --> gunakan COALESCE(pr.name, '')
$rows = fetchAll("
  SELECT p.id AS purchase_id, p.date, p.invoice_no, p.total, p.payment_status, p.payment_method, p.created_by,
         COALESCE(s.name,'') AS supplier, pi.id AS item_id, pi.product_id, pi.qty, pi.unit_price,
         COALESCE(pr.name, '') AS product_name,
         COALESCE(pr.image, '') AS product_image, p.warehouse_id
  FROM purchases p
  LEFT JOIN suppliers s ON p.supplier_id = s.id
  LEFT JOIN purchase_items pi ON pi.purchase_id = p.id
  LEFT JOIN products pr ON pr.id = pi.product_id
  $wsql
  ORDER BY p.date DESC, p.id DESC
  LIMIT 500
", $params);

include __DIR__ . '/../_partials/layout_start.php';
?>

<div class="card">
  <h2>Pembelian</h2>

  <div class="kpi">
    <div class="card"><div class="muted">Total Pembelian (periode)</div><h1><?=rupiah($sum)?></h1></div>
  </div>

  <form class="no-print" method="get" style="display:flex;gap:10px;align-items:center;margin:10px 0;flex-wrap:wrap">
    <label>Dari</label><input type="date" name="from" value="<?=e($from)?>">
    <label>Sampai</label><input type="date" name="to" value="<?=e($to)?>">
    <label>Supplier</label><input type="text" name="supplier" value="<?=e($supQ)?>" placeholder="PT Contoh">
    <button class="btn small" type="submit">Tampilkan</button>
    <a class="btn small" href="<?=asset_url('pages/pembelian/add.php')?>"><i class="fas fa-plus"></i> Tambah</a>
  </form>

  <table class="table">
    <thead>
      <tr>
        <th>Produk</th><th>Supplier</th><th>Qty</th><th>Harga Satuan</th><th>Total</th><th>Metode</th><th>Status Pembayaran</th><th class="no-print">Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if(!$rows): ?><tr><td colspan="8" class="muted">Belum ada data.</td></tr><?php endif;?>
      <?php foreach($rows as $r):
        $img = $r['product_image'] ?? null;
        $url = null;
        if ($img) {
          $abs = APP_DIR . '/' . str_replace('/', DIRECTORY_SEPARATOR, $img);
          if (is_file($abs)) $url = asset_url($img).'?v='.urlencode(@filemtime($abs));
        }
        // ambil warehouse name (safe)
        $whName = '-';
        if (!empty($r['warehouse_id'])) {
          $whrec = fetch("SELECT name FROM warehouses WHERE id=? LIMIT 1", [$r['warehouse_id']]);
          if ($whrec) $whName = $whrec['name'];
        }
      ?>
        <tr>
          <td style="display:flex;gap:10px;align-items:center">
            <?php if($url): ?>
              <img src="<?=$url?>" alt="<?=e($r['product_name'])?>" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #eee">
            <?php else: ?>
              <div style="width:64px;height:64px;border:1px dashed #eee;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:12px">No<br>Foto</div>
            <?php endif; ?>
            <div>
              <div style="font-weight:600"><?=e($r['product_name'])?></div>
              <div class="muted" style="font-size:12px">Gudang: <?=e($whName)?></div>
            </div>
          </td>
          <td><?=e($r['supplier'])?></td>
          <td style="text-align:center"><?=number_format($r['qty'])?></td>
          <td style="text-align:center"><?=rupiah($r['unit_price'])?></td>
          <td style="text-align:center"><?=rupiah($r['total'])?></td>
          <td><?=e($r['payment_method']?:'-')?></td>
          <td><?=e(ucfirst($r['payment_status']?:'unpaid'))?></td>
          <td class="no-print actions-td" style="white-space:nowrap">
            <!-- Edit -->
            <a class="icon-btn neutral" title="Edit Pembelian" href="<?=asset_url('pages/pembelian/edit.php?id='.$r['purchase_id'])?>">
              <i class="fas fa-pen"></i>
            </a>

            <!-- Delete -->
            <form method="post" action="<?=asset_url('pages/pembelian/delete.php')?>" style="display:inline" onsubmit="return confirm('Hapus pembelian ini?')">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$r['purchase_id']?>">
              <button class="icon-btn danger" type="submit" title="Hapus Pembelian">
                <i class="fas fa-trash"></i>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
