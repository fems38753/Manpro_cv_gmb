<?php
// pages/pembelian/edit.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_operasional']);

$_active='pembelian'; $page_title='Edit Pembelian';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('pages/pembelian/index.php?msg=ID+tidak+valid');

$p = fetch("SELECT * FROM purchases WHERE id = ? LIMIT 1", [$id]);
if (!$p) redirect('pages/pembelian/index.php?msg=Pembelian+tidak+ditemukan');

$item = fetch("SELECT * FROM purchase_items WHERE purchase_id = ? LIMIT 1", [$id]);

/* helper functions (same as add.php) */
function find_or_create_supplier(string $name): int {
  $name = trim($name);
  if ($name === '') throw new Exception('Nama supplier kosong');
  $r = fetch("SELECT id FROM suppliers WHERE LOWER(name)=LOWER(?) LIMIT 1", [$name]);
  if ($r) return (int)$r['id'];
  query("INSERT INTO suppliers (name, created_at) VALUES (?, NOW())", [$name]);
  return (int) lastId();
}

function find_or_create_product_with_photo(string $name, ?string $photo_relpath, string $unit='gulung', float $cost_price = 0.0): int {
  $name = trim($name);
  if ($name === '') throw new Exception('Nama produk kosong');
  $r = fetch("SELECT * FROM products WHERE LOWER(name)=LOWER(?) LIMIT 1", [$name]);
  if ($r) {
    if ($cost_price > 0) try{ query("UPDATE products SET cost_price=?, updated_at=NOW() WHERE id=?", [$cost_price,$r['id']]); }catch(Exception $e){}
    if ($photo_relpath && column_exists('products','image')) query("UPDATE products SET image=?, updated_at=NOW() WHERE id=?", [$photo_relpath,$r['id']]);
    return (int)$r['id'];
  }
  if (column_exists('products','image')) {
    query("INSERT INTO products (sku,name,description,unit,cost_price,selling_price,image,created_at,updated_at)
           VALUES (?, ?, '', ?, ?, 0, ?, NOW(), NOW())", [null,$name,$unit,$cost_price,$photo_relpath]);
  } else {
    query("INSERT INTO products (sku,name,description,unit,cost_price,selling_price,created_at,updated_at)
           VALUES (?, ?, '', ?, ?, 0, NOW(), NOW())", [null,$name,$unit,$cost_price]);
  }
  return (int) lastId();
}

function ensure_default_warehouse(){ $r = fetch("SELECT id FROM warehouses ORDER BY id LIMIT 1"); if ($r) return (int)$r['id']; query("INSERT INTO warehouses (name,address,created_at) VALUES (?, ?, NOW())", ['Gudang Utama','Gudang default']); return (int) lastId(); }

function find_account(array $names=[], array $types=[]) {
  if (!table_exists('accounts')) return null;
  foreach ($names as $n) {
    $r = fetch("SELECT id FROM accounts WHERE LOWER(name) LIKE ? LIMIT 1", ['%'.strtolower($n).'%']);
    if ($r) return (int)$r['id'];
  }
  foreach ($types as $t) {
    $r = fetch("SELECT id FROM accounts WHERE LOWER(type)=? LIMIT 1", [strtolower($t)]);
    if ($r) return (int)$r['id'];
  }
  return null;
}

/* process edit */
$err = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!check_csrf($_POST['csrf'] ?? '')) $err[] = 'Sesi kadaluarsa.';

  $tgl = $_POST['tgl_pembelian'] ?? $p['date'];
  $invoice_no = trim($_POST['invoice_no'] ?? $p['invoice_no']);
  $supplier_text = trim($_POST['supplier_pt'] ?? '');
  $produk_text = trim($_POST['produk'] ?? '');
  $qty_new = (int)($_POST['qty'] ?? ($item['qty'] ?? 0));
  $unit_price = (float)($_POST['harga_satuan'] ?? ($item['unit_price'] ?? 0));
  $warehouse_id = (int)($_POST['warehouse_id'] ?? $p['warehouse_id']);
  $payment_status = in_array($_POST['payment_status'] ?? 'unpaid',['paid','unpaid']) ? $_POST['payment_status'] : 'unpaid';
  $payment_method = trim($_POST['payment_method'] ?? '') ?: null;
  $notes = trim($_POST['catatan'] ?? '');
  $created_by = $_SESSION['user']['user_id'] ?? null;

  if ($supplier_text === '') $err[] = 'Supplier wajib.';
  if ($produk_text === '') $err[] = 'Produk wajib.';
  if ($qty_new <= 0) $err[] = 'Qty harus > 0.';

  // photo upload
  $product_photo_rel = null;
  if (isset($_FILES['product_photo']) && $_FILES['product_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    [$ok,$msg] = upload_image($_FILES['product_photo'], STOK_DIR);
    if (!$ok) $err[] = 'Upload foto gagal: '.$msg;
    else {
      $pmsg = str_replace('\\','/',$msg);
      $app = str_replace('\\','/', APP_DIR . '/');
      if (strpos($pmsg,$app) === 0) $pmsg = substr($pmsg, strlen($app));
      if (preg_match('/^[A-Za-z]:\\//',$pmsg) && ($pos=strpos($pmsg,'uploads/'))!==false) $pmsg = substr($pmsg,$pos);
      $product_photo_rel = ltrim($pmsg,'/');
    }
  }

  if (!$err) {
    begin();
    try {
      $supplier_id = find_or_create_supplier($supplier_text);
      $product_id_new = find_or_create_product_with_photo($produk_text, $product_photo_rel, 'gulung', $unit_price);

      // recompute totals
      $subtotal = $qty_new * $unit_price;
      $tax = 0; $total = $subtotal + $tax;

      // update purchases header
      query("UPDATE purchases SET supplier_id=?, invoice_no=?, date=?, warehouse_id=?, subtotal=?, tax=?, total=?, payment_status=?, payment_method=?, total_paid=?, created_by=?, updated_at=NOW() WHERE id = ?",
            [$supplier_id, $invoice_no ?: null, $tgl, $warehouse_id, $subtotal, $tax, $total, $payment_status, $payment_method, ($payment_status==='paid'?$total:0), $created_by, $id]);

      // item & stock adjustments
      if ($item) {
        $old_product = $item['product_id']; $old_qty = (int)$item['qty'];

        if ($old_product == $product_id_new) {
          $diff = $qty_new - $old_qty;
          if ($diff !== 0) {
            $sb = fetch("SELECT id,quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? LIMIT 1", [$product_id_new,$warehouse_id]);
            if ($sb) {
              if ($diff > 0) {
                query("UPDATE stock_balances SET quantity = quantity + ?, last_updated = NOW() WHERE id = ?", [$diff,$sb['id']]);
              } else {
                if ((float)$sb['quantity'] < (-$diff)) throw new Exception('Stok fisik tidak cukup untuk mengurangi.');
                query("UPDATE stock_balances SET quantity = quantity + ?, last_updated = NOW() WHERE id = ?", [$diff,$sb['id']]);
              }
            } else {
              if ($diff > 0) query("INSERT INTO stock_balances (product_id,warehouse_id,quantity,last_updated) VALUES (?,?,?,NOW())", [$product_id_new,$warehouse_id,$diff]);
              else throw new Exception('Tidak ada saldo stok untuk produk lama.');
            }
            if (table_exists('stock_movements')) {
              $note = "Edit Pembelian #{$id} penyesuaian qty ".($diff>0?'+':'').$diff;
              query("INSERT INTO stock_movements (product_id,warehouse_id,change_qty,movement_type,reference_type,reference_id,note,performed_by,created_at)
                     VALUES (?,?,?,?,?,?,?,?,NOW())", [$product_id_new,$warehouse_id,$diff,'purchase_adjust','purchase',$id,$note,$created_by]);
            }
          }
          query("UPDATE purchase_items SET qty=?, unit_price=?, subtotal=? WHERE id = ?", [$qty_new,$unit_price,$subtotal,$item['id']]);
        } else {
          // product changed: revert old, apply new
          $sb_old = fetch("SELECT id,quantity FROM stock_balances WHERE product_id=? AND warehouse_id=? LIMIT 1", [$old_product,$p['warehouse_id']]);
          if (!$sb_old || (float)$sb_old['quantity'] < $old_qty) throw new Exception('Stok fisik tidak cukup untuk rollback produk lama.');
          query("UPDATE stock_balances SET quantity = quantity - ?, last_updated = NOW() WHERE id = ?", [$old_qty,$sb_old['id']]);
          if (table_exists('stock_movements')) query("INSERT INTO stock_movements (product_id,warehouse_id,change_qty,movement_type,reference_type,reference_id,note,performed_by,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())",
                [$old_product,$p['warehouse_id'],-$old_qty,'purchase_reversal','purchase',$id,"Edit Pembelian #{$id} - pindah produk",$created_by]);

          $sb_new = fetch("SELECT id FROM stock_balances WHERE product_id=? AND warehouse_id=? LIMIT 1", [$product_id_new,$warehouse_id]);
          if ($sb_new) query("UPDATE stock_balances SET quantity = quantity + ?, last_updated = NOW() WHERE id = ?", [$qty_new,$sb_new['id']]);
          else query("INSERT INTO stock_balances (product_id,warehouse_id,quantity,last_updated) VALUES (?,?,?,NOW())", [$product_id_new,$warehouse_id,$qty_new]);

          if (table_exists('stock_movements')) query("INSERT INTO stock_movements (product_id,warehouse_id,change_qty,movement_type,reference_type,reference_id,note,performed_by,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())",
                [$product_id_new,$warehouse_id,$qty_new,'purchase_in','purchase',$id,"Edit Pembelian #{$id} - pindah produk",$created_by]);

          query("UPDATE purchase_items SET product_id=?, qty=?, unit_price=?, subtotal=? WHERE id=?", [$product_id_new,$qty_new,$unit_price,$subtotal,$item['id']]);
        }
      } else {
        // no item -> create
        query("INSERT INTO purchase_items (purchase_id,product_id,qty,unit_price,subtotal) VALUES (?,?,?,?,?)", [$id,$product_id_new,$qty_new,$unit_price,$subtotal]);
        $sb = fetch("SELECT id FROM stock_balances WHERE product_id=? AND warehouse_id=? LIMIT 1", [$product_id_new,$warehouse_id]);
        if ($sb) query("UPDATE stock_balances SET quantity = quantity + ?, last_updated = NOW() WHERE id = ?", [$qty_new,$sb['id']]);
        else query("INSERT INTO stock_balances (product_id,warehouse_id,quantity,last_updated) VALUES (?,?,?,NOW())", [$product_id_new,$warehouse_id,$qty_new]);
        if (table_exists('stock_movements')) query("INSERT INTO stock_movements (product_id,warehouse_id,change_qty,movement_type,reference_type,reference_id,note,performed_by,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())",
              [$product_id_new,$warehouse_id,$qty_new,'purchase_in','purchase',$id,"Edit Pembelian #{$id} - tambah item",$created_by]);
      }

      // update transaksi_keuangan simple
      if (table_exists('transaksi_keuangan')) {
        query("UPDATE transaksi_keuangan SET tipe='pengeluaran', tgl_transaksi=?, nominal=?, keterangan=? WHERE keterangan LIKE ?",
              [$tgl,$total,"Pembelian #{$id} - {$supplier_text}","%Pembelian #{$id}%"]);
      }

      // update journal if exist
      if (table_exists('journal_entries') && table_exists('journal_items')) {
        $je = fetch("SELECT id FROM journal_entries WHERE reference_type='purchase' AND reference_id = ? LIMIT 1", [$id]);
        if ($je) {
          $jid = (int)$je['id'];
          query("UPDATE journal_entries SET date=?, description=? WHERE id = ?", [$tgl,"Pembelian #{$id}",$jid]);
          query("DELETE FROM journal_items WHERE journal_id = ?", [$jid]);
          $inv_acc = find_account(['persediaan','inventory','stok'], ['asset','expense']);
          $credit_acc = ($payment_status==='paid') ? find_account(['kas','bank','tunai'], ['asset']) : find_account(['hutang','accounts payable','utang'], ['liability']);
          if ($inv_acc && $credit_acc) {
            query("INSERT INTO journal_items (journal_id,account_id,debit,credit) VALUES (?,?,?,?)", [$jid,$inv_acc,$total,0]);
            query("INSERT INTO journal_items (journal_id,account_id,debit,credit) VALUES (?,?,?,?)", [$jid,$credit_acc,0,$total]);
          }
        }
      }

      commit();
      redirect('pages/pembelian/index.php?msg=Perubahan+disimpan');
      exit;
    } catch (Exception $e) {
      rollback();
      $err[] = 'Gagal update pembelian: '.e($e->getMessage());
    }
  }
}

// prepare image url for display if any
$imgurl = null;
if ($item) {
  $pr = fetch("SELECT * FROM products WHERE id = ? LIMIT 1", [$item['product_id']]);
  if ($pr && !empty($pr['image'])) {
    $abs = APP_DIR . '/' . str_replace('/', DIRECTORY_SEPARATOR, $pr['image']);
    if (is_file($abs)) $imgurl = asset_url($pr['image']).'?v='.urlencode(@filemtime($abs));
  }
}

include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card" style="max-width:920px">
  <h2>Edit Pembelian</h2>
  <?php if($err): ?><div class="alert error"><?php foreach($err as $e) echo '<div>• '.e($e).'</div>'; ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=csrf_token()?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div><label>Tanggal</label><input type="date" name="tgl_pembelian" required value="<?=e($_POST['tgl_pembelian'] ?? $p['date'])?>"></div>
      <div><label>No. Invoice</label><input type="text" name="invoice_no" value="<?=e($_POST['invoice_no'] ?? $p['invoice_no'])?>"></div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-top:12px">
      <div><label>Supplier (PT)</label><input type="text" name="supplier_pt" required value="<?=e($_POST['supplier_pt'] ?? (fetch("SELECT name FROM suppliers WHERE id=? LIMIT 1",[$p['supplier_id']])['name'] ?? ''))?>"></div>
      <div><label>Qty (gulungan)</label><input type="number" name="qty" min="1" required value="<?=e($_POST['qty'] ?? ($item['qty'] ?? 1))?>"></div>
      <div><label>Harga Satuan</label><input type="number" name="harga_satuan" step="0.01" min="0" required value="<?=e($_POST['harga_satuan'] ?? ($item['unit_price'] ?? 0))?>"></div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-top:12px">
      <div><label>Produk</label><input type="text" name="produk" required value="<?=e($_POST['produk'] ?? ($pr['name'] ?? ''))?>"></div>
      <div>
        <label>Foto Produk (opsional)</label>
        <input type="file" name="product_photo" accept="image/*">
        <div style="margin-top:8px">
          <?php if ($imgurl): ?><img src="<?=$imgurl?>" style="max-width:140px;border-radius:8px;border:1px solid #eee"><?php else: ?><div style="width:140px;height:90px;border:1px dashed #ccc;display:flex;align-items:center;justify-content:center;color:#aaa">Tidak ada foto</div><?php endif; ?>
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Gudang</label>
        <select name="warehouse_id">
          <?php $whs = fetchAll("SELECT id,name FROM warehouses ORDER BY name"); foreach($whs as $w){ $sel = (int)($_POST['warehouse_id'] ?? $p['warehouse_id']) == (int)$w['id'] ? 'selected':''; ?>
            <option value="<?=$w['id']?>" <?=$sel?>><?=e($w['name'])?></option>
          <?php } ?>
        </select>
      </div>
      <div>
        <label>Status Pembayaran</label>
        <select name="payment_status">
          <option value="unpaid" <?=($_POST['payment_status'] ?? $p['payment_status'])==='unpaid'?'selected':''?>>Unpaid</option>
          <option value="paid" <?=($_POST['payment_status'] ?? $p['payment_status'])==='paid'?'selected':''?>>Paid</option>
        </select>

        <div style="margin-top:8px">
          <label>Metode Pembayaran</label>
          <select name="payment_method"><option value="">(kosong)</option>
            <option value="cash" <?=($_POST['payment_method'] ?? $p['payment_method'])==='cash'?'selected':''?>>Tunai</option>
            <option value="transfer" <?=($_POST['payment_method'] ?? $p['payment_method'])==='transfer'?'selected':''?>>Transfer</option>
            <option value="kredit" <?=($_POST['payment_method'] ?? $p['payment_method'])==='kredit'?'selected':''?>>Kredit</option>
          </select>
        </div>
      </div>
    </div>

    <div style="margin-top:12px">
      <label>Catatan</label>
      <textarea name="catatan" rows="3"><?=e($_POST['catatan'] ?? ($p['notes'] ?? ''))?></textarea>
    </div>

    <div style="display:flex;gap:12px;margin-top:16px">
      <button class="btn" type="submit">Simpan</button>
      <a class="btn outline" href="<?=asset_url('pages/pembelian/index.php')?>">Batal</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
