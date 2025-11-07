<?php
// pages/penjualan/invoice.php — aman utk schema fleksibel + print ramah A4
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die('Invoice tidak ditemukan');

/* ====== DETEKSI KOLOM DINAMIS DI sales ====== */
$pk          = pick_column('sales', ['id','sale_id','id_penjualan']) ?: 'id';
$inv_col     = pick_column('sales', ['invoice_no','no_invoice','invoice','nomor_invoice']) ?: 'invoice_no';
$date_col    = pick_date_column('sales') ?: pick_column('sales',['sale_date','tgl','tanggal','date']);
$pay_col     = pick_column('sales', ['payment_status','status_pembayaran','status','payment_state']);
$pay_methcol = pick_column('sales', ['payment_method','method','metode','metode_pembayaran']);
$cust_col    = pick_customer_column('sales') ?: pick_column('sales', ['customer_name','customer','customer_nama']);
$note_col    = pick_column('sales', ['note','catatan','keterangan','remarks']);
$sub_col     = pick_column('sales', ['subtotal','sub_total']);
$total_col   = pick_total_column('sales') ?: pick_column('sales', ['total','grand_total']);

/* ====== SELECT header dengan alias tetap (aman utk ketiadaan kolom) ====== */
$sel = [];
$sel[] = "s.`$pk` AS id";
$sel[] = "COALESCE(s.`$inv_col`, CONCAT('INV-', s.`$pk`)) AS invoice_no";
$sel[] = $date_col    ? "s.`$date_col` AS sale_date"         : "NULL AS sale_date";
$sel[] = $pay_col     ? "s.`$pay_col` AS payment_status"     : "NULL AS payment_status";
$sel[] = $pay_methcol ? "s.`$pay_methcol` AS payment_method" : "NULL AS payment_method";
$sel[] = $cust_col    ? "s.`$cust_col` AS customer_name"     : "NULL AS customer_name";
$sel[] = $note_col    ? "s.`$note_col` AS note"              : "NULL AS note";
$sel[] = $sub_col     ? "s.`$sub_col` AS subtotal"           : "0 AS subtotal";

/* total: prioritas total_col; kalau tidak ada, jatuhkan ke subtotal (kalau ada), else 0 */
if ($total_col) {
  $sel[] = "s.`$total_col` AS total";
} elseif ($sub_col) {
  $sel[] = "s.`$sub_col` AS total";
} else {
  $sel[] = "0 AS total";
}

$sqlH = "SELECT ".implode(", ", $sel)." FROM sales s WHERE s.`$pk` = ? LIMIT 1";
$h = fetch($sqlH, [$id]);
if (!$h) die('Invoice tidak ditemukan');

/* ====== DETAIL ITEM (join produk & gudang bila ada) ====== */
$items = fetchAll("
  SELECT 
    i.*, 
    p.name AS product_name, 
    COALESCE(p.sku,'') AS sku, 
    w.name AS warehouse_name
  FROM sale_items i
  JOIN products p ON p.id = i.product_id
  LEFT JOIN warehouses w ON w.id = i.warehouse_id
  WHERE i.sale_id = ?
  ORDER BY i.id
", [$id]);

/* ====== UPDATE header (Kepada/Catatan) via POST ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (check_csrf($_POST['csrf'] ?? '')) {
    $new_cust = trim($_POST['customer_name'] ?? '');
    $new_note = trim($_POST['note'] ?? '');
    $cols = []; $vals = [];
    if ($cust_col) { $cols[] = "s.`$cust_col` = ?"; $vals[] = ($new_cust === '' ? null : $new_cust); }
    if ($note_col) { $cols[] = "s.`$note_col` = ?";  $vals[] = ($new_note === '' ? null : $new_note); }
    if ($cols) {
      $vals[] = $id;
      query("UPDATE sales s SET ".implode(',', $cols)." WHERE s.`$pk` = ?", $vals);
    }
  }
  header('Location: ' . asset_url('pages/penjualan/invoice.php?id='.$id.'&msg=Perubahan+berhasil+disimpan'));
  exit;
}

/* ====== Cari logo untuk print (ambil dari /uploads) ====== */
$logoUrl = null;
foreach (['logo_gmb.png','logo.png','cv_gmb_logo.png'] as $fn) {
  $abs = realpath(__DIR__.'/../../uploads/'.$fn);
  if ($abs && file_exists($abs)) { $logoUrl = asset_url('uploads/'.$fn); break; }
}

include __DIR__ . '/../_partials/layout_start.php';
?>
<style>
/* ====== PRINT-ONLY: paksa light, rapikan agar 1 halaman A4 ====== */
@media print {
  :root { color-scheme: light; }
  html, body { padding:0 !important; margin:0 !important; background:#fff !important; }
  /* Sembunyikan semua konten kecuali area invoice (anti tercecer) */
  body * { visibility: hidden; }
  #printArea, #printArea * { visibility: visible; }
  #printArea { position: absolute; left: 0; top: 0; width: 100%; padding: 0 !important; margin: 0 !important; }
  /* Hilangkan shadow/spacing yang bikin nambah halaman */
  .invoice-card { box-shadow:none !important; border:1px solid rgba(0,0,0,.15); margin:0 !important; page-break-inside: avoid; }
  /* Header print (logo + identitas) */
  .print-header { display:block !important; text-align:center; margin: 0 0 8mm 0; }
  .print-header img { max-height: 42px; }
  .print-header .brand { font-weight:700; font-size: 16px; margin-top:4px; }
  /* Tabel tidak pecah di tengah baris */
  table { page-break-inside:auto; }
  tr, td, th { page-break-inside:avoid; page-break-after:auto; }
  @page { size: A4 portrait; margin: 10mm; }
}
/* Saat layar, header print disembunyikan (mengikuti tema layout) */
.print-header { display:none; }
</style>

<div id="printArea">
  <!-- HEADER CETAK (print only) -->
  <div class="print-header">
    <?php if ($logoUrl): ?>
      <img src="<?= e($logoUrl) ?>" alt="CV GMB">
    <?php endif; ?>
    <div class="brand">CV GMB</div>
  </div>

  <div class="card invoice-card" style="max-width:900px;margin:16px auto">
    <!-- HEADER (selalu tampil) -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
      <div>
        <h2 style="margin:0">Invoice #<?= e($h['invoice_no']) ?></h2>
        <div class="muted">
          Tanggal:
          <?php if (!empty($h['sale_date'])): ?>
            <?= e(date('d M Y', strtotime($h['sale_date']))) ?>
          <?php else: ?>
            -
          <?php endif; ?>
        </div>
        <div class="muted">
          Status Bayar:
          <?= $h['payment_status'] !== null ? e(strtoupper((string)$h['payment_status'])) : '-' ?>
        </div>
      </div>
      <div class="no-print" style="display:flex;gap:8px">
        <button class="btn" onclick="window.print()"><i class="fas fa-print"></i> Cetak</button>
        <a class="btn outline" href="<?= asset_url('pages/penjualan/index.php') ?>">Kembali</a>
      </div>
    </div>

    <hr style="margin:14px 0">

    <!-- Pesan sukses -->
    <div class="no-print" style="margin-bottom:10px">
      <?php if(isset($_GET['msg'])): ?>
        <div style="background:#e9f9ee;border:1px solid #bfe6cc;color:#256a3b;padding:8px 10px;border-radius:8px">
          <?= e($_GET['msg']) ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- FORM edit Kepada / Catatan (hanya layar) -->
    <form class="no-print" method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:end;margin-top:10px">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div>
        <label class="muted" style="display:block;margin-bottom:6px">Kepada (Toko / PT)</label>
        <input type="text" name="customer_name" value="<?= e((string)$h['customer_name']) ?>" placeholder="Toko / PT ..." />
      </div>
      <div>
        <label class="muted" style="display:block;margin-bottom:6px">Catatan</label>
        <textarea name="note" rows="2" placeholder="Catatan di invoice (opsional)"><?= e((string)$h['note']) ?></textarea>
      </div>
      <div>
        <button class="btn" type="submit"><i class="fas fa-save"></i> Simpan Header</button>
      </div>
    </form>

    <hr class="no-print" style="margin:14px 0">

    <!-- BLOK Kepada / Catatan -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:8px">
      <div>
        <div style="font-weight:700">Kepada:</div>
        <div><?= $h['customer_name'] ? e($h['customer_name']) : '-' ?></div>
      </div>
      <div>
        <div style="font-weight:700">Catatan:</div>
        <div><?= $h['note'] ? nl2br(e($h['note'])) : '-' ?></div>
      </div>
    </div>

    <!-- TABEL ITEM -->
    <div class="table-responsive" style="margin-top:6px">
      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <th>PRODUK</th>
            <th>GUDANG</th>
            <th style="text-align:right">QTY</th>
            <th style="text-align:right">HARGA</th>
            <th style="text-align:right">SUBTOTAL</th>
          </tr>
        </thead>
        <tbody>
          <?php $no=1; foreach($items as $it): ?>
          <tr>
            <td><?= $no++ ?></td>
            <td><?= e($it['product_name']) . ($it['sku'] ? ' ('.e($it['sku']).')' : '') ?></td>
            <td><?= e($it['warehouse_name'] ?: '-') ?></td>
            <td style="text-align:right"><?= is_numeric($it['qty'] ?? null) ? number_format((float)$it['qty'],0,',','.') : '0' ?></td>
            <td style="text-align:right"><?= rupiah((float)($it['unit_price'] ?? 0)) ?></td>
            <td style="text-align:right"><?= rupiah((float)($it['subtotal'] ?? ( ($it['qty']??0)*($it['unit_price']??0) ))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5" style="text-align:right;font-weight:700">Subtotal</td>
            <td style="text-align:right"><?= rupiah((float)$h['subtotal']) ?></td>
          </tr>
          <tr>
            <td colspan="5" style="text-align:right;font-weight:900">TOTAL</td>
            <td style="text-align:right;font-weight:900"><?= rupiah((float)$h['total']) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="muted" style="margin-top:10px">Terima kasih Telah Berbelanja di CV GMB.</div>
  </div>
</div>

<?php include __DIR__ . '/../_partials/layout_end.php';
