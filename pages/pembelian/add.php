<?php
// pages/pembelian/add.php (light patch: product photo + stok + transaksi)
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_operasional']);

$_active = 'pembelian';
$page_title = 'Tambah Pembelian (B2B)';

/* ---------------------------
   Small helpers used here
   --------------------------- */
function find_or_create_supplier(string $name): int {
  $name = trim($name);
  if ($name === '') throw new Exception('Nama supplier kosong');
  $r = fetch("SELECT id FROM suppliers WHERE LOWER(name)=LOWER(?) LIMIT 1", [$name]);
  if ($r) return (int)$r['id'];
  query("INSERT INTO suppliers (name, created_at) VALUES (?, NOW())", [$name]);
  return (int) lastId();
}

/** Buat / update product + simpan image jika ada
 *  Mengembalikan product_id (int)
 */
function find_or_create_product_with_photo(string $name, ?string $photo_relpath, string $unit='gulung', float $cost_price = 0.0): int {
  $name = trim($name);
  if ($name === '') throw new Exception('Nama produk kosong');
  $r = fetch("SELECT * FROM products WHERE LOWER(name)=LOWER(?) LIMIT 1", [$name]);
  if ($r) {
    // update cost price & image jika diberikan
    if ($cost_price > 0) {
      try { query("UPDATE products SET cost_price = ?, updated_at = NOW() WHERE id = ?", [$cost_price, $r['id']]); } catch(Exception $e){ }
    }
    if ($photo_relpath && column_exists('products','image')) {
      query("UPDATE products SET image = ?, updated_at = NOW() WHERE id = ?", [$photo_relpath, $r['id']]);
    }
    return (int)$r['id'];
  } else {
    if (column_exists('products','image')) {
      query("INSERT INTO products (sku,name,description,unit,cost_price,selling_price,image,created_at,updated_at)
             VALUES (?, ?, '', ?, ?, 0, ?, NOW(), NOW())",
            [null, $name, $unit, $cost_price, $photo_relpath]);
    } else {
      query("INSERT INTO products (sku,name,description,unit,cost_price,selling_price,created_at,updated_at)
             VALUES (?, ?, '', ?, ?, 0, NOW(), NOW())",
            [null, $name, $unit, $cost_price]);
    }
    return (int) lastId();
  }
}

function ensure_default_warehouse(): int {
  $r = fetch("SELECT id FROM warehouses ORDER BY id LIMIT 1");
  if ($r) return (int)$r['id'];
  query("INSERT INTO warehouses (name,address,created_at) VALUES (?, ?, NOW())", ['Gudang Utama','Gudang default']);
  return (int) lastId();
}

/* ---------------------------
   Form handler
   --------------------------- */
$err = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!check_csrf($_POST['csrf'] ?? '')) $err[] = 'Sesi kadaluarsa. Muat ulang halaman.';
  $tgl            = trim($_POST['tgl_pembelian'] ?? date('Y-m-d'));
  $invoice_no     = trim($_POST['invoice_no'] ?? '');
  $supplier_text  = trim($_POST['supplier_pt'] ?? '');
  $produk_text    = trim($_POST['produk'] ?? '');
  $qty            = (int)($_POST['qty'] ?? 0);
  $unit_price     = (float)($_POST['harga_satuan'] ?? 0);
  $warehouse_id   = (int)($_POST['warehouse_id'] ?? 0);
  $payment_status = in_array($_POST['payment_status'] ?? 'unpaid',['paid','unpaid']) ? $_POST['payment_status'] : 'unpaid';
  $payment_method = trim($_POST['payment_method'] ?? '') ?: null;
  $notes          = trim($_POST['catatan'] ?? '');
  $created_by     = $_SESSION['user']['user_id'] ?? ($_SESSION['user']['username'] ?? null);

  if ($supplier_text === '') $err[] = 'Supplier wajib diisi.';
  if ($produk_text === '') $err[] = 'Produk wajib diisi.';
  if ($qty <= 0) $err[] = 'Qty harus > 0.';
  if ($unit_price < 0) $err[] = 'Harga satuan tidak valid.';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $err[] = 'Tanggal tidak valid.';

  if ($warehouse_id <= 0) $warehouse_id = ensure_default_warehouse();

  // handle upload foto produk (opsional)
  $product_photo_rel = null;
  if (isset($_FILES['product_photo']) && $_FILES['product_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    [$ok,$msg] = upload_image($_FILES['product_photo'], STOK_DIR);
    if (!$ok) $err[] = 'Upload foto produk gagal: '.$msg;
    else {
      // normalisasi path jadi relatif (uploads/...)
      $p = str_replace('\\','/',$msg);
      $app = str_replace('\\','/', APP_DIR . '/');
      if (strpos($p,$app) === 0) $p = substr($p, strlen($app));
      if (preg_match('/^[A-Za-z]:\\//',$p) && ($pos=strpos($p,'uploads/'))!==false) $p = substr($p,$pos);
      $product_photo_rel = ltrim($p,'/');
    }
  }

  if (!$err) {
    begin();
    try {
      // supplier & product
      $supplier_id = find_or_create_supplier($supplier_text);
      $product_id = find_or_create_product_with_photo($produk_text, $product_photo_rel, 'gulung', $unit_price);

      // totals
      $subtotal = $qty * $unit_price;
      $tax = 0.00;
      $total = $subtotal + $tax;

      // insert purchase header
      $sql = "INSERT INTO purchases (supplier_id, invoice_no, date, warehouse_id, subtotal, tax, total, status, payment_status, payment_method, total_paid, created_by, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
      $pvals = [$supplier_id, $invoice_no ?: null, $tgl, $warehouse_id, $subtotal, $tax, $total, 'received', $payment_status, $payment_method, ($payment_status === 'paid' ? $total : 0), $created_by];
      query($sql, $pvals);
      $purchase_id = (int) lastId();

      // insert purchase item
      query("INSERT INTO purchase_items (purchase_id, product_id, qty, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)",
            [$purchase_id, $product_id, $qty, $unit_price, $subtotal]);

      // update stock_balances
      if (table_exists('stock_balances')) {
        $sb = fetch("SELECT id, quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? LIMIT 1", [$product_id, $warehouse_id]);
        if ($sb) {
          query("UPDATE stock_balances SET quantity = quantity + ?, last_updated = NOW() WHERE id = ?", [$qty, $sb['id']]);
        } else {
          query("INSERT INTO stock_balances (product_id, warehouse_id, quantity, last_updated) VALUES (?, ?, ?, NOW())", [$product_id, $warehouse_id, $qty]);
        }
      }

      // stock_movements (audit)
      if (table_exists('stock_movements')) {
        $note_sm = "Pembelian #{$purchase_id} - {$supplier_text}";
        query("INSERT INTO stock_movements (product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, performed_by, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
              [$product_id, $warehouse_id, $qty, 'purchase_in', 'purchase', $purchase_id, $note_sm, $created_by]);
      }

      // transaksi_keuangan: catat pengeluaran (simple)
      if (function_exists('trx_create')) {
        trx_create([
          'tipe' => 'pengeluaran',
          'tgl' => $tgl,
          'nominal' => $total,
          'keterangan' => "Pembelian #{$purchase_id} - {$supplier_text}",
          'method' => $payment_method,
          'created_by' => $created_by
        ]);
      } elseif (table_exists('transaksi_keuangan')) {
        $cols = ['tipe','tgl_transaksi','nominal','keterangan'];
        $vals = ['pengeluaran', $tgl, $total, "Pembelian #{$purchase_id} - {$supplier_text}"];
        if (column_exists('transaksi_keuangan','method')) { $cols[]='method'; $vals[]=$payment_method; }
        if (column_exists('transaksi_keuangan','created_by')) { $cols[]='created_by'; $vals[]=$created_by; }
        $sqlt = "INSERT INTO transaksi_keuangan (".implode(',',$cols).") VALUES (".implode(',', array_fill(0,count($vals),'?')).")";
        query($sqlt, $vals);
      }

      commit();
      redirect('pages/pembelian/index.php?msg=Pembelian+berhasil+ditambahkan');
      exit;
    } catch (Exception $e) {
      rollback();
      $err[] = 'Gagal menyimpan pembelian: '.e($e->getMessage());
    }
  }
}

/* ---------------------------
   View
   --------------------------- */
include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card" style="max-width:920px">
  <h2>Tambah Pembelian (B2B)</h2>

  <?php if ($err): ?>
    <div class="alert error" style="background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;padding:10px;border-radius:10px;margin-bottom:10px">
      <?php foreach($err as $e) echo '<div>• '.e($e).'</div>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <label>Tanggal</label>
        <input type="date" name="tgl_pembelian" required value="<?= e($_POST['tgl_pembelian'] ?? date('Y-m-d')) ?>">
      </div>
      <div>
        <label>No. Invoice / PO</label>
        <input type="text" name="invoice_no" value="<?= e($_POST['invoice_no'] ?? '') ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Supplier (PT)</label>
        <input type="text" name="supplier_pt" required value="<?= e($_POST['supplier_pt'] ?? '') ?>">
      </div>
      <div>
        <label>Qty (gulungan)</label>
        <input type="number" name="qty" min="1" required value="<?= e($_POST['qty'] ?? 1) ?>">
      </div>
      <div>
        <label>Harga Satuan</label>
        <input type="number" name="harga_satuan" step="0.01" min="0" required value="<?= e($_POST['harga_satuan'] ?? 0) ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Produk (nama kain / motif)</label>
        <input type="text" name="produk" required value="<?= e($_POST['produk'] ?? '') ?>">
        <div class="muted" style="font-size:12px">Satu qty = 1 gulungan</div>
      </div>
      <div>
        <label>Foto Produk (opsional)</label>
        <input type="file" name="product_photo" accept="image/png,image/jpeg,image/webp">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Gudang</label>
        <select name="warehouse_id">
          <?php $whs = fetchAll("SELECT id,name FROM warehouses ORDER BY name"); foreach($whs as $w){ $sel = (int)($_POST['warehouse_id']??0)==(int)$w['id']?'selected':''; ?>
            <option value="<?=$w['id']?>" <?=$sel?>><?=e($w['name'])?></option>
          <?php } ?>
        </select>
      </div>
      <div>
        <label>Status Pembayaran</label>
        <select name="payment_status">
          <option value="unpaid" <?=($_POST['payment_status']??'unpaid')==='unpaid'?'selected':''?>>Unpaid</option>
          <option value="paid" <?=($_POST['payment_status']??'unpaid')==='paid'?'selected':''?>>Paid</option>
        </select>

        <div style="margin-top:8px">
          <label>Metode Pembayaran (opsional)</label>
          <select name="payment_method">
            <option value="">(kosong)</option>
            <option value="cash">Tunai</option>
            <option value="transfer">Transfer</option>
            <option value="kredit">Kredit</option>
          </select>
        </div>
      </div>
    </div>

    <div style="margin-top:12px">
      <label>Catatan</label>
      <textarea name="catatan" rows="3"><?= e($_POST['catatan'] ?? '') ?></textarea>
    </div>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn" type="submit"><i class="fas fa-save"></i> Simpan & Terima</button>
      <a class="btn outline" href="<?= asset_url('pages/pembelian/index.php') ?>"><i class="fas fa-arrow-left"></i> Batal</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
