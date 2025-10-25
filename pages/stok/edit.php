<?php
// pages/stok/edit.php — Edit stok (metadata + qty) — safe + transactional
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_operasional']);

$_active = 'stok';
$page_title = 'Edit Item Stok';

/* ----- Ambil sb_id ----- */
$sb_id = (int)($_GET['sb_id'] ?? 0);
if ($sb_id <= 0) redirect('pages/stok/index.php?err=ID+tidak+valid');

/* ----- Build SELECT list safely for product columns ----- */
$prodCols = [];
$prodCols[] = "p.name AS product_name";

if (column_exists('products','sku')) $prodCols[] = "p.sku";
else $prodCols[] = "'' AS sku";

if (column_exists('products','kategori')) $prodCols[] = "p.kategori";
else $prodCols[] = "'' AS kategori";

if (column_exists('products','unit')) $prodCols[] = "COALESCE(p.unit,'gulung') AS unit";
else $prodCols[] = "'gulung' AS unit";

if (column_exists('products','selling_price')) $prodCols[] = "COALESCE(p.selling_price,0) AS harga_jual";
else $prodCols[] = "0 AS harga_jual";

if (column_exists('products','image')) $prodCols[] = "COALESCE(p.image,'') AS image";
else $prodCols[] = "'' AS image";

/* ----- Fetch row ----- */
$sql = "SELECT sb.*, " . implode(', ', $prodCols) . ", p.id AS product_id
        FROM stock_balances sb
        JOIN products p ON p.id = sb.product_id
        WHERE sb.id = ? LIMIT 1";
$row = fetch($sql, [$sb_id]);
if (!$row) redirect('pages/stok/index.php?err=Data+tidak+ditemukan');

$err = [];

/* ----- POST handler ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!check_csrf($_POST['csrf'] ?? '')) $err[] = 'Sesi kadaluarsa.';

  $product_name = trim((string)($_POST['product_name'] ?? $row['product_name']));
  $sku = column_exists('products','sku') ? trim((string)($_POST['sku'] ?? $row['sku'])) : '';
  $kategori = column_exists('products','kategori') ? trim((string)($_POST['kategori'] ?? $row['kategori'])) : '';
  $unit = column_exists('products','unit') ? trim((string)($_POST['unit'] ?? $row['unit']) ) : 'gulung';
  $harga_jual = column_exists('products','selling_price') && (isset($_POST['harga_jual']) && $_POST['harga_jual'] !== '') ? (float)$_POST['harga_jual'] : null;
  $new_qty = isset($_POST['quantity']) ? (int)$_POST['quantity'] : (int)$row['quantity'];
  $note = trim((string)($_POST['note'] ?? ''));
  $performed_by = $_SESSION['user']['user_id'] ?? ($_SESSION['user']['username'] ?? null);

  // Normalize SKU: empty -> NULL (to avoid UNIQUE '' duplicates)
  if ($sku === '') $sku = null;

  // Validasi
  if ($product_name === '') $err[] = 'Nama produk wajib diisi.';
  if ($new_qty < 0) $err[] = 'Qty tidak boleh negatif.';
  if ($harga_jual !== null && $harga_jual < 0) $err[] = 'Harga jual tidak boleh negatif.';

  // Cek uniqueness SKU bila kolom sku ada dan sku tidak NULL
  if (column_exists('products','sku') && $sku !== null) {
    $dup = fetch("SELECT id FROM products WHERE sku = ? AND id <> ? LIMIT 1", [$sku, (int)$row['product_id']]);
    if ($dup) $err[] = 'SKU sudah digunakan oleh produk lain. Gunakan SKU lain atau kosongkan field SKU.';
  }

  // handle foto upload
  $photo_rel = null;
  if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
    [$ok, $msg] = upload_image($_FILES['foto'], STOK_DIR);
    if (!$ok) {
      $err[] = 'Upload foto gagal: ' . $msg;
    } else {
      $p = str_replace('\\','/',$msg);
      $app = str_replace('\\','/', APP_DIR . '/');
      if (strpos($p,$app) === 0) $p = substr($p, strlen($app));
      if (preg_match('/^[A-Za-z]:\\//',$p) && ($pos = strpos($p,'uploads/')) !== false) $p = substr($p, $pos);
      $photo_rel = ltrim($p,'/');
    }
  }

  if (!$err) {
    begin();
    try {
      $product_id = (int)$row['product_id'];

      /* update product metadata if changed */
      $sets = []; $vals = [];
      if ($product_name !== $row['product_name']) { $sets[] = "name = ?"; $vals[] = $product_name; }
      if (column_exists('products','sku') && ($sku !== $row['sku'])) { $sets[] = "sku = ?"; $vals[] = $sku; }
      if (column_exists('products','kategori') && ($kategori !== $row['kategori'])) { $sets[] = "kategori = ?"; $vals[] = $kategori; }
      if (column_exists('products','unit') && ($unit !== $row['unit'])) { $sets[] = "unit = ?"; $vals[] = $unit; }
      if (column_exists('products','selling_price') && $harga_jual !== null && $harga_jual !== (float)$row['harga_jual']) { $sets[] = "selling_price = ?"; $vals[] = $harga_jual; }
      if ($photo_rel && column_exists('products','image')) { $sets[] = "image = ?"; $vals[] = $photo_rel; }

      if (!empty($sets)) {
        // hapus foto lama jika kita menulis foto baru (sebelum update file di DB)
        if ($photo_rel && column_exists('products','image')) {
          $old_image = $row['image'] ?? null;
          if ($old_image && str_starts_with($old_image, 'uploads/')) {
            $absOld = APP_DIR . '/' . str_replace('/', DIRECTORY_SEPARATOR, $old_image);
            if (is_file($absOld)) @unlink($absOld);
          }
        }

        $vals[] = $product_id;
        query("UPDATE products SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = ?", $vals);
      }

      /* qty change (lock + update + movement) */
      $old_qty = (int)$row['quantity'];
      $diff = $new_qty - $old_qty;
      if ($diff !== 0) {
        // lock row for update
        $lockedStmt = query("SELECT id, quantity FROM stock_balances WHERE id = ? FOR UPDATE", [$sb_id]);
        $locked = $lockedStmt->fetch(PDO::FETCH_ASSOC);
        if (!$locked) throw new Exception('Baris stok tidak ditemukan saat mengunci.');

        $current_qty = (float)$locked['quantity'];
        $new_total = $current_qty + $diff;
        if ($new_total < 0) throw new Exception('Operasi akan menyebabkan stok negatif.');

        query("UPDATE stock_balances SET quantity = ?, last_updated = NOW() WHERE id = ?", [$new_total, $sb_id]);

        // catat movement jika tabel ada
        if (table_exists('stock_movements')) {
          $movement_type = $diff > 0 ? 'manual_in' : 'manual_out';
          $movement_note = $note ?: ($diff > 0 ? 'Penambahan stok (manual)' : 'Pengurangan stok (manual)');
          query(
            "INSERT INTO stock_movements (product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, performed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [$product_id, $row['warehouse_id'] ?? null, $diff, $movement_type, 'stock_adjust', $sb_id, $movement_note, $performed_by]
          );
        }
      }

      commit();
      redirect('pages/stok/index.php?msg=Item+stok+diupdate');
      exit;
    } catch (Exception $e) {
      rollback();
      $err[] = 'Gagal menyimpan perubahan: ' . $e->getMessage();
    }
  }
}

/* prepare image url for view */
$imgUrl = null;
if (!empty($row['image'])) {
  $abs = APP_DIR . '/' . str_replace('/', DIRECTORY_SEPARATOR, $row['image']);
  if (is_file($abs)) $imgUrl = asset_url($row['image']) . '?v=' . urlencode(@filemtime($abs));
}

$warehouses = fetchAll("SELECT id,name FROM warehouses ORDER BY name");

include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card" style="max-width:900px">
  <h2>Edit Item Stok</h2>

  <?php if ($err): ?>
    <div class="alert error" style="background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;padding:10px;border-radius:10px;margin-bottom:10px">
      <?php foreach($err as $e) echo '<div>• '.e($e).'</div>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px">
      <div>
        <label>Nama Produk</label>
        <input type="text" name="product_name" required value="<?= e($_POST['product_name'] ?? $row['product_name']) ?>">
      </div>

      <?php if (column_exists('products','sku')): ?>
      <div>
        <label>SKU</label>
        <input type="text" name="sku" value="<?= e($_POST['sku'] ?? $row['sku']) ?>">
      </div>
      <?php endif; ?>

      <?php if (column_exists('products','kategori')): ?>
      <div>
        <label>Kategori</label>
        <input type="text" name="kategori" value="<?= e($_POST['kategori'] ?? $row['kategori']) ?>">
      </div>
      <?php else: ?>
      <div></div>
      <?php endif; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Qty (gulungan)</label>
        <input type="number" name="quantity" min="0" required value="<?= e($_POST['quantity'] ?? $row['quantity']) ?>">
      </div>

      <?php if (column_exists('products','unit')): ?>
      <div>
        <label>Satuan</label>
        <input type="text" name="unit" value="<?= e($_POST['unit'] ?? $row['unit']) ?>">
      </div>
      <?php else: ?>
      <div></div>
      <?php endif; ?>

      <?php if (column_exists('products','selling_price')): ?>
      <div>
        <label>Harga Jual</label>
        <input type="number" name="harga_jual" step="0.01" min="0" value="<?= e($_POST['harga_jual'] ?? $row['harga_jual']) ?>">
        <div class="muted" style="font-size:12px">Ubah harga jual di sini.</div>
      </div>
      <?php else: ?>
      <div></div>
      <?php endif; ?>

      <div>
        <label>Gudang</label>
        <select name="warehouse_id" disabled>
          <?php foreach ($warehouses as $w): $sel = (int)$w['id'] === (int)$row['warehouse_id'] ? 'selected' : ''; ?>
            <option value="<?= (int)$w['id'] ?>" <?= $sel ?>><?= e($w['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="muted" style="font-size:12px;margin-top:6px">Perubahan gudang tidak diizinkan di sini.</div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-top:12px">
      <?php if (column_exists('products','image')): ?>
      <div>
        <label>Foto Produk (opsional)</label>
        <input type="file" name="foto" accept="image/png,image/jpeg,image/webp">
        <div style="margin-top:8px">
          <?php if ($imgUrl): ?><img src="<?= $imgUrl ?>" style="max-width:140px;border-radius:8px;border:1px solid #eee"><?php else: ?><div style="width:140px;height:90px;border:1px dashed #ccc;display:flex;align-items:center;justify-content:center;color:#aaa">Tidak ada foto</div><?php endif; ?>
        </div>
      </div>
      <?php else: ?>
      <div></div>
      <?php endif; ?>

      <div>
        <label>Catatan / Alasan (opsional)</label>
        <input type="text" name="note" value="<?= e($_POST['note'] ?? '') ?>">
        <div class="muted" style="font-size:12px;margin-top:6px">Jika mengubah qty, berikan alasan untuk audit.</div>
      </div>
    </div>

    <div style="display:flex;gap:12px;margin-top:16px">
      <button class="btn" type="submit"><i class="fas fa-save"></i> Simpan</button>
      <a class="btn outline" href="<?= asset_url('pages/stok/index.php') ?>"><i class="fas fa-arrow-left"></i> Batal</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
