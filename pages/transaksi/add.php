<?php
// pages/transaksi/add.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur', 'staff_keuangan']);

$_active    = 'transaksi';
$page_title = 'Tambah Transaksi';

$u       = current_user();
$user_id = (int)($u['user_id'] ?? 0);

$errors = [];

/* default form values */
$defaults = [
  'tgl' => date('Y-m-d'),
  'jenis' => 'pendapatan',
  'nominal' => '',
  'method' => 'cash',
  'keterangan' => ''
];

$methods = [
  'cash' => 'Tunai',
  'transfer' => 'Transfer',
  'kredit' => 'Kredit',
  'lainnya' => 'Lainnya'
];

/* handle submit */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!check_csrf($_POST['csrf'] ?? '')) {
    $errors[] = 'Sesi kadaluarsa, muat ulang halaman.';
  }

  $tgl = trim($_POST['tgl'] ?? $defaults['tgl']);
  $jenis = ($_POST['jenis'] ?? $defaults['jenis']) === 'pengeluaran' ? 'pengeluaran' : 'pendapatan';
  $nominal = (float)($_POST['nominal'] ?? 0);
  $method = trim($_POST['method'] ?? $defaults['method']);
  $ket = trim($_POST['keterangan'] ?? '');

  /* validate */
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $errors[] = 'Tanggal tidak valid.';
  if ($nominal <= 0) $errors[] = 'Nominal harus lebih dari 0.';
  if ($method !== '' && !array_key_exists($method, $methods)) $errors[] = 'Metode tidak valid.';

  /* graceful detect columns */
  $has_method = column_exists('transaksi_keuangan','method');
  $has_created_by = column_exists('transaksi_keuangan','created_by');

  if (!$errors) {
    try {
      // build dynamic insert
      $cols = [];
      $vals = [];

      // tipe
      $cols[] = 'tipe'; $vals[] = $jenis;

      // date column detection: try common names, otherwise default tgl_transaksi
      $dateCol = null;
      if (column_exists('transaksi_keuangan','tgl_transaksi')) $dateCol = 'tgl_transaksi';
      elseif (column_exists('transaksi_keuangan','tanggal')) $dateCol = 'tanggal';
      elseif (column_exists('transaksi_keuangan','date')) $dateCol = 'date';
      elseif (column_exists('transaksi_keuangan','created_at')) $dateCol = 'created_at';

      if ($dateCol) {
        $cols[] = $dateCol;
        // if created_at (datetime) we supply datetime, else date
        if ($dateCol === 'created_at') $vals[] = date('Y-m-d H:i:s', strtotime($tgl . ' 00:00:00'));
        else $vals[] = date('Y-m-d', strtotime($tgl));
      }

      // nominal
      if (column_exists('transaksi_keuangan','nominal')) {
        $cols[] = 'nominal'; $vals[] = $nominal;
      } else {
        // fallback: try other candidate names, insert only if found
        $alt = pick_column('transaksi_keuangan',['total','jumlah','nilai','amount']);
        if ($alt) { $cols[] = $alt; $vals[] = $nominal; }
      }

      // keterangan
      if (column_exists('transaksi_keuangan','keterangan')) {
        $cols[] = 'keterangan'; $vals[] = $ket ?: null;
      } else {
        $altk = pick_column('transaksi_keuangan',['catatan','note','deskripsi','description']);
        if ($altk) { $cols[] = $altk; $vals[] = $ket ?: null; }
      }

      // method (opsional)
      if ($has_method) {
        $cols[] = 'method'; $vals[] = $method ?: null;
      }

      // created_by (opsional)
      if ($has_created_by) {
        $cols[] = 'created_by'; $vals[] = $user_id ?: null;
      }

      if (empty($cols)) {
        throw new Exception('Skema tabel transaksi_keuangan tidak ditemukan atau tidak kompatibel.');
      }

      // auto created_at handled by DB default (if available), so not insert

      // prepare SQL
      $fields = implode(', ', array_map(function($c){ return "`$c`"; }, $cols));
      $placeholders = implode(', ', array_fill(0, count($vals), '?'));
      $sql = "INSERT INTO transaksi_keuangan ($fields) VALUES ($placeholders)";

      query($sql, $vals);

      redirect('pages/transaksi/index.php?msg=ok');
      exit;
    } catch (Exception $e) {
      $errors[] = 'Gagal menyimpan transaksi: ' . e($e->getMessage());
    }
  }
}

/* view */
include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card" style="max-width:820px">
  <h2>Tambah Transaksi</h2>

  <?php if($errors): ?>
    <div class="alert" style="background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;border-radius:10px;padding:10px 12px;margin-bottom:12px">
      <?php foreach($errors as $err) echo '• '.e($err).'<br>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" oninput="formatNominal()">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <label>Tanggal</label>
        <input type="date" name="tgl" required value="<?= e($_POST['tgl'] ?? $defaults['tgl']) ?>">
      </div>

      <div>
        <label>Jenis</label>
        <select name="jenis">
          <option value="pendapatan" <?= (($_POST['jenis'] ?? $defaults['jenis']) === 'pendapatan') ? 'selected' : '' ?>>Pendapatan</option>
          <option value="pengeluaran" <?= (($_POST['jenis'] ?? $defaults['jenis']) === 'pengeluaran') ? 'selected' : '' ?>>Pengeluaran</option>
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Nominal</label>
        <input type="number" name="nominal" min="0" step="0.01" required value="<?= e($_POST['nominal'] ?? $defaults['nominal']) ?>">
      </div>

      <div>
        <label>Metode (opsional)</label>
        <select name="method">
          <?php foreach($methods as $k=>$v): ?>
            <option value="<?=e($k)?>" <?= (($_POST['method'] ?? $defaults['method']) === $k) ? 'selected' : '' ?>><?=e($v)?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div style="margin-top:12px">
      <label>Keterangan</label>
      <input type="text" name="keterangan" value="<?= e($_POST['keterangan'] ?? $defaults['keterangan']) ?>">
    </div>

    <div style="display:flex;gap:12px;margin-top:16px">
      <button class="btn" type="submit"><i class="fas fa-save"></i> Simpan</button>
      <a class="btn outline" href="<?= asset_url('pages/transaksi/index.php') ?>"><i class="fas fa-arrow-left"></i> Batal</a>
    </div>
  </form>
</div>

<script>
function formatNominal(){
  const el = document.querySelector('input[name="nominal"]');
  if(!el) return;
  const v = parseFloat(el.value || '0');
  if (!Number.isNaN(v)) el.value = Math.round(v * 100) / 100;
}
</script>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
