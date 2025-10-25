<?php
require_once __DIR__.'/../../config/database.php';
require_login();
allow(['direktur','staff_operasional','staff_keuangan']);

// Range presets
$range = $_GET['range'] ?? 'this_month'; // today, this_month, last_month, this_year, custom
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
$supplier = trim($_GET['supplier'] ?? '');

// calculate default ranges
$today = date('Y-m-d');
switch ($range) {
  case 'today':
    $from = $from ?: $today;
    $to   = $to   ?: $today;
    break;
  case 'last_month':
    $first = date('Y-m-01', strtotime('first day of last month'));
    $last  = date('Y-m-t', strtotime('last day of last month'));
    $from = $from ?: $first;
    $to   = $to   ?: $last;
    break;
  case 'this_year':
    $from = $from ?: date('Y-01-01');
    $to   = $to   ?: date('Y-12-31');
    break;
  case 'custom':
    // expect $from and $to provided by user
    $from = $from ?: date('Y-m-01');
    $to   = $to   ?: date('Y-m-t');
    break;
  case 'this_month':
  default:
    $from = $from ?: date('Y-m-01');
    $to   = $to   ?: date('Y-m-t');
    $range = 'this_month';
    break;
}

// detect which schema to use
$use_new = table_exists('purchases');

// build where clause
$where = []; $params = [];
if ($use_new) {
  $where[] = "p.date BETWEEN ? AND ?"; $params[] = $from; $params[] = $to;
  if ($supplier !== '') { $where[] = "LOWER(s.name) LIKE LOWER(?)"; $params[] = "%$supplier%"; }
  $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

  // KPI: total, count, avg
  $k = fetch("SELECT COALESCE(SUM(p.total),0) AS total, COUNT(p.id) AS cnt, 
                    CASE WHEN COUNT(p.id)>0 THEN COALESCE(SUM(p.total)/COUNT(p.id),0) ELSE 0 END AS avg
             FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id $wsql", $params);
  $total_all = (float)($k['total'] ?? 0);
  $count_all = (int)($k['cnt'] ?? 0);
  $avg_all = (float)($k['avg'] ?? 0);

  // rows (aggregate product list, total qty)
  $sql = "
    SELECT p.id, p.date AS tgl, COALESCE(s.name,'') AS supplier,
           p.invoice_no AS no_po,
           p.total AS total_harga,
           COALESCE(GROUP_CONCAT(CONCAT(pr.name,' (',pi.qty,')') SEPARATOR ', '),'') AS produk_list,
           COALESCE(SUM(pi.qty),0) AS total_qty,
           p.created_by
    FROM purchases p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    LEFT JOIN purchase_items pi ON pi.purchase_id = p.id
    LEFT JOIN products pr ON pr.id = pi.product_id
    $wsql
    GROUP BY p.id
    ORDER BY p.date ASC, p.id ASC
  ";
  $rows = fetchAll($sql, $params);

  // datalist suppliers
  $suppliers = fetchAll("SELECT name FROM suppliers WHERE name IS NOT NULL AND name<>'' ORDER BY name LIMIT 200");
} else {
  // legacy fallback
  $dcol = pick_column('pembelian',['tgl_pembelian','tanggal','tgl','date','created_at']) ?: null;
  $scol = pick_column('pembelian',['supplier_pt','supplier','nama_supplier','vendor','pt']) ?: null;
  $pcol = pick_column('pembelian',['produk','nama_produk','barang','item']) ?: null;
  $tcol = pick_column('pembelian',['total_harga','total','grand_total','subtotal','jumlah_harga']) ?: '0';

  // where
  if ($dcol) { $where[] = "`$dcol` BETWEEN ? AND ?"; $params[]=$from; $params[]=$to; }
  if ($supplier !== '' && $scol) { $where[] = "`$scol` LIKE ?"; $params[] = "%$supplier%"; }
  $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

  $k = fetch("SELECT COALESCE(SUM($tcol),0) AS total, COUNT(*) AS cnt,
                    CASE WHEN COUNT(*)>0 THEN COALESCE(SUM($tcol)/COUNT(*),0) ELSE 0 END AS avg
             FROM pembelian $wsql", $params);
  $total_all = (float)($k['total'] ?? 0);
  $count_all = (int)($k['cnt'] ?? 0);
  $avg_all = (float)($k['avg'] ?? 0);

  $rows = fetchAll("SELECT ".($dcol?"`$dcol` AS tgl,":"NULL AS tgl,")." ".($scol?"`$scol` AS supplier,":"'' AS supplier,")." ".($pcol?"`$pcol` AS produk,":"'' AS produk,")." ".(pick_column('pembelian',['qty','jumlah','quantity']) ? (pick_column('pembelian',['qty','jumlah','quantity'])." AS total_qty,") : "NULL AS total_qty,")." $tcol AS total_harga, ".(pick_column('pembelian',['no_po','po','nomor_po'])?:'NULL')." AS no_po, created_by FROM pembelian $wsql ORDER BY ".($dcol?"`$dcol` ASC,":"")."id ASC", $params);

  // suppliers for datalist (legacy)
  $suppliers = [];
  if ($scol) $suppliers = fetchAll("SELECT DISTINCT `$scol` AS name FROM pembelian WHERE `$scol` IS NOT NULL AND `$scol`<>'' ORDER BY `$scol`");
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Laporan Pembelian • CV GMB</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root{--bg:#fff7f1;--accent:#59382B;--muted:#7b8794}
    body{font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial;color:#222;margin:18px}
    .toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
    .card{background:#fff;border-radius:10px;padding:14px;border:1px solid #f0e6df}
    .kpis{display:flex;gap:12px;margin:12px 0}
    .kpi{background:#fff7f1;border-radius:10px;padding:10px 14px;color:var(--accent);box-shadow:inset 0 0 0 1px rgba(0,0,0,0.03)}
    .kpi .label{font-size:12px;color:var(--muted)}
    .kpi .value{font-weight:700;margin-top:6px}
    .btn{padding:8px 12px;border-radius:8px;border:1px solid #ecd7bd;background:#fff7e8;color:var(--accent);text-decoration:none;cursor:pointer}
    .btn.outline{background:#fff;border:1px solid #e6d6c2}
    .filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    table{width:100%;border-collapse:collapse;margin-top:12px}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
    thead th{font-size:12px;color:var(--muted);text-transform:uppercase}
    .right{text-align:right}
    .muted{color:var(--muted);font-size:13px}
    @media print{.noprint{display:none}}
  </style>
</head>
<body>
  <div class="noprint toolbar">
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <label>Range</label>
      <select name="range" onchange="onRangeChange(this.value)">
        <option value="today" <?= $range==='today' ? 'selected':'' ?>>Hari ini</option>
        <option value="this_month" <?= $range==='this_month' ? 'selected':'' ?>>Bulan ini</option>
        <option value="last_month" <?= $range==='last_month' ? 'selected':'' ?>>Bulan lalu</option>
        <option value="this_year" <?= $range==='this_year' ? 'selected':'' ?>>Tahun ini</option>
        <option value="custom" <?= $range==='custom' ? 'selected':'' ?>>Custom</option>
      </select>

      <label>Dari</label>
      <input type="date" id="from" name="from" value="<?=e($from)?>">

      <label>Sampai</label>
      <input type="date" id="to" name="to" value="<?=e($to)?>">

      <label>Supplier</label>
      <input list="sup-list" type="text" name="supplier" value="<?=e($supplier)?>" placeholder="PT Contoh Textile">
      <datalist id="sup-list">
        <?php foreach($suppliers as $s){ $v = $s['name'] ?? $s['supplier'] ?? ''; if(!$v) continue; ?>
          <option value="<?=e($v)?>"></option>
        <?php } ?>
      </datalist>

      <button class="btn" type="submit">Tampilkan</button>
      <button type="button" class="btn" onclick="window.print()">Cetak</button>
      <a class="btn outline" href="<?=asset_url('pages/pembelian/index.php')?>">← Kembali</a>
    </form>
  </div>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start">
      <div>
        <div style="font-weight:800;font-size:20px;color:var(--accent)">CV Gelora Maju Bersama</div>
        <div class="muted">Laporan Pembelian (B2B)</div>
        <div class="muted" style="margin-top:6px">Filter: Supplier <?= $supplier ? '• '.e($supplier) : '• Semua' ?></div>
      </div>
      <div style="text-align:right">
        <div class="muted">Periode</div>
        <div style="font-weight:700"><?=e(date('d M Y', strtotime($from)))?> — <?=e(date('d M Y', strtotime($to)))?></div>
      </div>
    </div>

    <div class="kpis" role="region" aria-label="KPI pembelian">
      <div class="kpi">
        <div class="label">Total Pembelian</div>
        <div class="value"><?= rupiah($total_all) ?></div>
      </div>
      <div class="kpi">
        <div class="label">Jumlah Invoice</div>
        <div class="value"><?= number_format($count_all) ?></div>
      </div>
      <div class="kpi">
        <div class="label">Rata-rata per Invoice</div>
        <div class="value"><?= rupiah($avg_all) ?></div>
      </div>
    </div>

    <table aria-describedby="table-desc">
      <thead>
        <tr>
          <th style="width:95px">Tanggal</th>
          <th style="width:220px">Supplier</th>
          <th>Produk</th>
          <th style="width:70px">Qty</th>
          <th style="width:120px" class="right">Harga</th>
          <th style="width:120px" class="right">Total</th>
          <th style="width:110px">No. PO</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="muted">Tidak ada data untuk periode ini.</td></tr>
        <?php else: foreach ($rows as $r): 
            $tgl = $r['tgl'] ?? $r['tanggal'] ?? null;
            $produk = $r['produk_list'] ?? $r['produk'] ?? '';
            $qty = $r['total_qty'] ?? $r['total_qty'] ?? $r['qty'] ?? null;
            $harga = $r['unit_price'] ?? $r['harga'] ?? null;
            $total = $r['total_harga'] ?? $r['total'] ?? $r['total'];
        ?>
          <tr>
            <td><?= $tgl ? e(date('d M Y', strtotime($tgl))) : '<span class="muted">—</span>' ?></td>
            <td><?= e($r['supplier'] ?? '') ?></td>
            <td style="white-space:normal;word-break:break-word"><?= e($produk) ?></td>
            <td class="right"><?= $qty !== null ? number_format((int)$qty) : '<span class="muted">—</span>' ?></td>
            <td class="right"><?= is_null($harga) ? '<span class="muted">—</span>' : rupiah((float)$harga) ?></td>
            <td class="right"><?= rupiah((float)($total ?? 0)) ?></td>
            <td><?= e($r['no_po'] ?? '') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="5" class="right">TOTAL</th>
          <th class="right"><?= rupiah($total_all) ?></th>
          <th></th>
        </tr>
      </tfoot>
    </table>

    <div style="display:flex;justify-content:space-between;margin-top:18px;color:#666;font-size:13px">
      <div>Dicetak: <?=date('d M Y H:i')?> • Oleh: <?= e($_SESSION['user']['username'] ?? ($_SESSION['user']['user_id'] ?? '')) ?></div>
      <div>CV Gelora Maju Bersama</div>
    </div>
  </div>

<script>
function onRangeChange(v){
  const from = document.getElementById('from');
  const to   = document.getElementById('to');
  const now = new Date();
  if (v === 'today') {
    const d = new Date(); from.value = to.value = d.toISOString().slice(0,10);
  } else if (v === 'this_month') {
    const first = new Date(now.getFullYear(), now.getMonth(), 1);
    const last  = new Date(now.getFullYear(), now.getMonth()+1, 0);
    from.value = first.toISOString().slice(0,10);
    to.value   = last.toISOString().slice(0,10);
  } else if (v === 'last_month') {
    const first = new Date(now.getFullYear(), now.getMonth()-1, 1);
    const last  = new Date(now.getFullYear(), now.getMonth(), 0);
    from.value = first.toISOString().slice(0,10);
    to.value   = last.toISOString().slice(0,10);
  } else if (v === 'this_year') {
    from.value = now.getFullYear() + '-01-01';
    to.value   = now.getFullYear() + '-12-31';
  } else if (v === 'custom') {
    // keep as is
  }
}
</script>
</body>
</html>
