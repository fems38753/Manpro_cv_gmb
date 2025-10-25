<?php
// pages/dashboard/index.php
require_once __DIR__ . '/../../config/database.php';
require_login();

$_active    = 'dashboard';
$page_title = 'Overview';
$u = current_user();

/* ===== KPI: Stok ===== */
// total jenis produk di stok: prefer stock_balances, fallback ke stok/products
$totalProdukStok = 0;
if (table_exists('stock_balances')) {
  $row = fetch("SELECT COUNT(DISTINCT product_id) AS c FROM stock_balances");
  $totalProdukStok = (int)($row['c'] ?? 0);
} elseif (table_exists('stok')) {
  $totalProdukStok = (int)(fetch("SELECT COUNT(*) c FROM stok")['c'] ?? 0);
} elseif (table_exists('products')) {
  $totalProdukStok = (int)(fetch("SELECT COUNT(*) c FROM products")['c'] ?? 0);
}

// total qty stok: sum(stock_balances.quantity) preferred
$totalQtyStok = 0;
if (table_exists('stock_balances')) {
  $row = fetch("SELECT COALESCE(SUM(quantity),0) AS s FROM stock_balances");
  $totalQtyStok = (int)round($row['s'] ?? 0);
} elseif (table_exists('stok')) {
  $totalQtyStok = (int) sum_column_fallback('stok', ['qty','jumlah','quantity','stok','stock_qty','qty_total']);
} else {
  $totalQtyStok = 0;
}

/* ===== KPI: Penjualan & Pembelian ===== */
$totalPenjualanRp = 0.0;
if (table_exists('penjualan')) {
  $penjTotalCol = pick_total_column('penjualan') ?? null;
  if ($penjTotalCol) {
    $totalPenjualanRp = (float)(fetch("SELECT COALESCE(SUM(`$penjTotalCol`),0) s FROM penjualan")['s'] ?? 0);
  } else {
    // if no total column, try summing qty*harga_satuan (best-effort)
    if (column_exists('penjualan','qty') && column_exists('penjualan','harga_satuan')) {
      $totalPenjualanRp = (float)(fetch("SELECT COALESCE(SUM(qty * harga_satuan),0) s FROM penjualan")['s'] ?? 0);
    }
  }
}

$totalPembelianRp = 0.0;
if (table_exists('pembelian')) {
  $pembTotalCol = pick_total_column('pembelian') ?? null;
  if ($pembTotalCol) {
    $totalPembelianRp = (float)(fetch("SELECT COALESCE(SUM(`$pembTotalCol`),0) s FROM pembelian")['s'] ?? 0);
  } else {
    if (column_exists('pembelian','qty') && column_exists('pembelian','harga_satuan')) {
      $totalPembelianRp = (float)(fetch("SELECT COALESCE(SUM(qty * harga_satuan),0) s FROM pembelian")['s'] ?? 0);
    }
  }
}

$saldoKas = $totalPenjualanRp - $totalPembelianRp;

/* ===== Top 5 Stok ===== */
$topStok = [];
if (table_exists('stock_balances')) {
  // prefer joining products to get name; if not exist, show product_id
  if (table_exists('products') && column_exists('products','name')) {
    $topStok = fetchAll("
      SELECT COALESCE(pr.name, CONCAT('Produk #', sb.product_id)) AS label,
             COALESCE(SUM(sb.quantity),0) AS value
      FROM stock_balances sb
      LEFT JOIN products pr ON pr.id = sb.product_id
      GROUP BY sb.product_id
      ORDER BY value DESC
      LIMIT 5
    ");
  } else {
    $topStok = fetchAll("
      SELECT CONCAT('Produk #', sb.product_id) AS label,
             COALESCE(SUM(sb.quantity),0) AS value
      FROM stock_balances sb
      GROUP BY sb.product_id
      ORDER BY value DESC
      LIMIT 5
    ");
  }
} elseif (table_exists('stok')) {
  // fallback to old stok table (if schema different)
  $nameCol = pick_name_column_stok();
  $qtyCol = pick_qty_column_stok();
  if ($nameCol && $qtyCol) {
    $topStok = fetchAll("SELECT $nameCol AS label, COALESCE($qtyCol,0) AS value FROM stok ORDER BY $qtyCol DESC LIMIT 5");
  }
}
// Ensure topStok is an array of {label, value}
if (!$topStok) $topStok = [];

/* ===== Chart Keuangan ===== */
/* Range GET param: day | month | year */
$range = $_GET['range'] ?? 'month';
if (!in_array($range, ['day','month','year'], true)) $range = 'month';
$rangeLabel = ['day'=>'Harian (30 hari terakhir)','month'=>'Bulanan','year'=>'Tahunan (5 tahun)'][$range];

/* Build keu data - prefer transaksi_keuangan table */
$keu = [];
if (table_exists('transaksi_keuangan')) {
  if ($range === 'day') {
    for ($i=29;$i>=0;$i--) {
      $d = date('Y-m-d', strtotime("-$i day"));
      $pemasukan = (float)(fetch("SELECT COALESCE(SUM(nominal),0) s FROM transaksi_keuangan WHERE tipe='pendapatan' AND DATE(tgl_transaksi)=?", [$d])['s'] ?? 0);
      $pengeluaran = (float)(fetch("SELECT COALESCE(SUM(nominal),0) s FROM transaksi_keuangan WHERE tipe='pengeluaran' AND DATE(tgl_transaksi)=?", [$d])['s'] ?? 0);
      $keu[] = ['label'=>date('d M', strtotime($d)), 'pemasukan'=>$pemasukan, 'pengeluaran'=>$pengeluaran];
    }
  } elseif ($range === 'year') {
    for ($i=4;$i>=0;$i--) {
      $yr = (int)date('Y', strtotime("-$i year"));
      $pemasukan = (float)(fetch("SELECT COALESCE(SUM(nominal),0) s FROM transaksi_keuangan WHERE tipe='pendapatan' AND YEAR(tgl_transaksi)=?", [$yr])['s'] ?? 0);
      $pengeluaran = (float)(fetch("SELECT COALESCE(SUM(nominal),0) s FROM transaksi_keuangan WHERE tipe='pengeluaran' AND YEAR(tgl_transaksi)=?", [$yr])['s'] ?? 0);
      $keu[] = ['label'=>(string)$yr, 'pemasukan'=>$pemasukan, 'pengeluaran'=>$pengeluaran];
    }
  } else { // month
    $keu = fetchAll("
      SELECT DATE_FORMAT(tgl_transaksi,'%b') AS label,
             SUM(CASE WHEN tipe='pendapatan' THEN nominal ELSE 0 END) AS pemasukan,
             SUM(CASE WHEN tipe='pengeluaran' THEN nominal ELSE 0 END) AS pengeluaran
      FROM transaksi_keuangan
      WHERE YEAR(tgl_transaksi) = YEAR(CURDATE())
      GROUP BY MONTH(tgl_transaksi)
      ORDER BY MONTH(tgl_transaksi)
    ");
    // ensure months 1..12 present even if empty
    $months = [];
    for ($m=1;$m<=12;$m++) $months[$m] = ['label'=>date('M', mktime(0,0,0,$m,1)),'pemasukan'=>0,'pengeluaran'=>0];
    foreach ($keu as $r) {
      // map by label; find month index by label
      $lbl = $r['label'];
      for ($m=1;$m<=12;$m++) {
        if ($months[$m]['label'] === $lbl) {
          $months[$m] = $r;
          break;
        }
      }
    }
    $keu = array_values($months);
  }
} else {
  // fallback using penjualan/pembelian (as earlier)
  $penjDate = table_exists('penjualan') ? pick_date_column('penjualan') : null;
  $penjTot  = table_exists('penjualan') ? pick_total_column('penjualan') : null;
  $pembDate = table_exists('pembelian') ? pick_date_column('pembelian') : null;
  $pembTot  = table_exists('pembelian') ? pick_total_column('pembelian') : null;

  if ($penjDate && $penjTot && $pembDate && $pembTot) {
    if ($range === 'day') {
      $keu=[];
      for ($i=29;$i>=0;$i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $pemasukan = (float)(fetch("SELECT COALESCE(SUM($penjTot),0) s FROM penjualan WHERE DATE($penjDate)=?", [$d])['s'] ?? 0);
        $pengeluaran = (float)(fetch("SELECT COALESCE(SUM($pembTot),0) s FROM pembelian WHERE DATE($pembDate)=?", [$d])['s'] ?? 0);
        $keu[]=['label'=>date('d M',strtotime($d)),'pemasukan'=>$pemasukan,'pengeluaran'=>$pengeluaran];
      }
    } elseif ($range === 'year') {
      $keu=[];
      for ($i=4;$i>=0;$i--) {
        $yr = (int)date('Y', strtotime("-$i year"));
        $pemasukan = (float)(fetch("SELECT COALESCE(SUM($penjTot),0) s FROM penjualan WHERE YEAR($penjDate)=?", [$yr])['s'] ?? 0);
        $pengeluaran = (float)(fetch("SELECT COALESCE(SUM($pembTot),0) s FROM pembelian WHERE YEAR($pembDate)=?", [$yr])['s'] ?? 0);
        $keu[]=['label'=>(string)$yr,'pemasukan'=>$pemasukan,'pengeluaran'=>$pengeluaran];
      }
    } else {
      $keu = [];
      for ($m=1;$m<=12;$m++) {
        $lbl = date('M', mktime(0,0,0,$m,1));
        $pemasukan = (float)(fetch("SELECT COALESCE(SUM($penjTot),0) s FROM penjualan WHERE YEAR($penjDate)=YEAR(CURDATE()) AND MONTH($penjDate)=?", [$m])['s'] ?? 0);
        $pengeluaran = (float)(fetch("SELECT COALESCE(SUM($pembTot),0) s FROM pembelian WHERE YEAR($pembDate)=YEAR(CURDATE()) AND MONTH($pembDate)=?", [$m])['s'] ?? 0);
        $keu[]=['label'=>$lbl,'pemasukan'=>$pemasukan,'pengeluaran'=>$pengeluaran];
      }
    }
  }
}

/* ===== Transaksi Terakhir (as before) ===== */
if (table_exists('transaksi_keuangan')) {
  $trxTerakhir = fetchAll("
    SELECT tgl_transaksi AS tgl, tipe, nominal, keterangan
    FROM transaksi_keuangan
    ORDER BY tgl_transaksi DESC
    LIMIT 10
  ");
} else {
  $rowsJual = []; $rowsBeli = [];
  if (table_exists('penjualan')) {
    $penjDate = pick_date_column('penjualan');
    $penjTot  = pick_total_column('penjualan');
    $custCol  = pick_customer_column('penjualan') ?? null;
    if ($penjDate && $penjTot) {
      $pd = '`'.$penjDate.'`'; $pt = '`'.$penjTot.'`';
      $cc = $custCol ? ('CONCAT(\'Penjualan: \', COALESCE(`'.$custCol.'`, \'\'))') : "'Penjualan'";
      $rowsJual = fetchAll("
        SELECT $pd AS tgl, 'pendapatan' AS tipe, $pt AS nominal, $cc AS keterangan
        FROM penjualan
        ORDER BY $pd DESC
        LIMIT 5
      ");
    }
  }
  if (table_exists('pembelian')) {
    $pembDate = pick_date_column('pembelian');
    $pembTot  = pick_total_column('pembelian');
    $suppCol  = pick_supplier_column('pembelian') ?? null;
    if ($pembDate && $pembTot) {
      $bd = '`'.$pembDate.'`'; $bt = '`'.$pembTot.'`';
      $sc = $suppCol ? ('CONCAT(\'Pembelian: \', COALESCE(`'.$suppCol.'`, \'\'))') : "'Pembelian'";
      $rowsBeli = fetchAll("
        SELECT $bd AS tgl, 'pengeluaran' AS tipe, $bt AS nominal, $sc AS keterangan
        FROM pembelian
        ORDER BY $bd DESC
        LIMIT 5
      ");
    }
  }
  $trxTerakhir = array_merge($rowsJual,$rowsBeli);
  usort($trxTerakhir, fn($a,$b)=>strtotime($b['tgl'])<=>strtotime($a['tgl']));
  $trxTerakhir = array_slice($trxTerakhir,0,10);
}

/* ===== VIEW ===== */
include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="dashboard">
  <h2>Overview</h2>

  <!-- KPI -->
  <div class="kpi">
    <div class="card">
      <div class="muted">Total Produk Stok</div>
      <h1><?= number_format($totalProdukStok) ?></h1>
      <small class="muted">Jumlah jenis produk</small>
    </div>

    <div class="card">
      <div class="muted">Total QTY Stok</div>
      <h1><?= number_format($totalQtyStok) ?></h1>
      <small class="muted">Unit tersedia</small>
    </div>

    <div class="card">
      <div class="muted">Total Penjualan</div>
      <h1><?= rupiah($totalPenjualanRp) ?></h1>
      <small class="muted">Akumulasi</small>
    </div>

    <div class="card">
      <div class="muted">Saldo (Penj−Pemb)</div>
      <h1><?= rupiah($saldoKas) ?></h1>
      <small class="muted">Perkiraan kas</small>
    </div>
  </div>

  <!-- Charts -->
  <div class="grid2">
    <div class="card">
      <div class="badge">Top 5 Stok</div>
      <canvas id="chartStok" height="150"></canvas>
    </div>

    <div class="card" style="position:relative">
      <div class="badge">Keuangan <?= e($rangeLabel) ?></div>
      <div class="no-print" style="position:absolute;top:10px;right:10px">
        <button id="menuBtn" class="btn outline" style="padding:6px 10px;border-radius:10px"><i class="fas fa-ellipsis-vertical"></i></button>
        <div id="menuDrop" style="display:none;position:absolute;right:0;margin-top:6px;background:#fff;border:1px solid #eadccd;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.08);overflow:hidden;min-width:180px">
          <a class="menuitem" href="?range=day"   style="display:block;padding:10px 12px;text-decoration:none;color:#111827">Harian (30 hari)</a>
          <a class="menuitem" href="?range=month" style="display:block;padding:10px 12px;text-decoration:none;color:#111827">Bulanan (tahun ini)</a>
          <a class="menuitem" href="?range=year"  style="display:block;padding:10px 12px;text-decoration:none;color:#111827">Tahunan (5 tahun)</a>
        </div>
      </div>
      <canvas id="chartKeu" height="150"></canvas>
    </div>
  </div>

  <!-- Transaksi terakhir -->
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <h3 style="margin:0">Transaksi Terakhir</h3>
      <div class="no-print" style="display:flex;gap:10px">
        <?php if(in_array($u['role'],['direktur','staff_keuangan'],true)): ?>
          <a class="btn outline" href="<?=asset_url('pages/transaksi/index.php')?>"><i class="fas fa-receipt"></i> Lihat Semua</a>
        <?php endif; ?>
      </div>
    </div>

    <table class="table">
      <thead><tr><th>Tanggal</th><th>Tipe</th><th>Nominal</th><th>Keterangan</th></tr></thead>
      <tbody>
        <?php if(!$trxTerakhir): ?>
          <tr><td colspan="4" class="muted">Belum ada transaksi.</td></tr>
        <?php else: foreach($trxTerakhir as $t): ?>
          <tr>
            <td><?=e(date('d M Y', strtotime($t['tgl'])))?></td>
            <td><?=e(ucfirst($t['tipe']))?></td>
            <td><?=rupiah($t['nominal'])?></td>
            <td><?=e($t['keterangan'])?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  // dropdown
  const btn  = document.getElementById('menuBtn');
  const drop = document.getElementById('menuDrop');
  if (btn) {
    btn.addEventListener('click', e => { e.preventDefault(); drop.style.display = (drop.style.display==='block'?'none':'block'); });
    document.addEventListener('click', e => { if (!btn.contains(e.target) && !drop.contains(e.target)) drop.style.display = 'none'; });
  }

  const warna = {
    emas: '#B7771D',
    coklat: '#59382B',
    kuning: '#FFBB38',
    oranye: '#FC7900',
    transparanEmas: 'rgba(183,119,29,0.3)',
    transparanOranye: 'rgba(252,121,0,0.3)'
  };

  const topStok = <?= json_encode($topStok) ?>;
  new Chart(document.getElementById('chartStok'), {
    type: 'bar',
    data: {
      labels: topStok.map(r => r.label),
      datasets: [{
        label: 'Qty',
        data: topStok.map(r => Number(r.value)),
        backgroundColor: warna.transparanEmas,
        borderColor: warna.emas,
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false } },
        y: { ticks: { callback: v => new Intl.NumberFormat('id-ID').format(v) } }
      }
    }
  });

  const keuData = <?= json_encode($keu) ?>;
  new Chart(document.getElementById('chartKeu'), {
    type: 'line',
    data: {
      labels: keuData.map(r => r.label),
      datasets: [
        {
          label: 'Pemasukan',
          data: keuData.map(r => Number(r.pemasukan)),
          borderColor: warna.emas,
          backgroundColor: warna.transparanEmas,
          pointBackgroundColor: warna.emas,
          fill: true, tension:0.3
        },
        {
          label: 'Pengeluaran',
          data: keuData.map(r => Number(r.pengeluaran)),
          borderColor: warna.oranye,
          backgroundColor: warna.transparanOranye,
          pointBackgroundColor: warna.oranye,
          fill: true, tension:0.3
        }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'top' } },
      scales: { y: { ticks: { callback: v => new Intl.NumberFormat('id-ID').format(v) } } }
    }
  });
</script>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
