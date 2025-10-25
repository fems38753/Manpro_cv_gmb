<?php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

$_active    = 'transaksi';
$page_title = 'Edit Transaksi';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect('pages/transaksi/index.php?msg=Data+tidak+valid'); }

// ambil row apa pun struktur kolomnya
$row = fetch("SELECT * FROM transaksi_keuangan WHERE id = ? LIMIT 1", [$id]);
if (!$row) { redirect('pages/transaksi/index.php?msg=Data+tidak+ditemukan'); }

$err = [];

// deteksi kolom opsional
$has_method = column_exists('transaksi_keuangan', 'method');
$has_created_by = column_exists('transaksi_keuangan', 'created_by');
$users_table = table_exists('users');

$methods = [
  'cash' => 'Tunai',
  'transfer' => 'Transfer',
  'kredit' => 'Kredit',
  'lainnya' => 'Lainnya'
];

// ambil daftar user bila perlu
$user_options = [];
if ($users_table && function_exists('fetchAll')) {
  try {
    $user_options = fetchAll("SELECT user_id, username, nama FROM users ORDER BY username");
  } catch (Exception $e) {
    $user_options = [];
  }
}

// helper ambil nama user
function _user_label($u) {
  if (!$u) return '';
  return trim(($u['username'] ?? '') . ($u['nama'] ? ' — ' . $u['nama'] : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!check_csrf($_POST['csrf'] ?? '')) $err[] = 'Sesi kadaluarsa.';

  // input (nama kolom sesuai file lama)
  $tgl = trim($_POST['tgl_transaksi'] ?? ($row['tgl_transaksi'] ?? ''));
  $tipe = $_POST['tipe'] ?? ($row['tipe'] ?? '');
  $nominal = $_POST['nominal'] ?? ($row['nominal'] ?? 0);
  $keterangan = trim($_POST['keterangan'] ?? ($row['keterangan'] ?? ''));

  // method & created_by (opsional)
  $method_in = $has_method ? (trim($_POST['method'] ?? ($row['method'] ?? ''))) : null;
  $created_by_in = null;
  if ($has_created_by) {
    if ($users_table && current_user() && (get_user_role_name(current_user()) === 'direktur')) {
      // direktur boleh pilih created_by (user id)
      $created_by_in = (int)($_POST['created_by'] ?? ($row['created_by'] ?? 0)) ?: null;
    } else {
      // selain direktur, preserve or set to current user
      $created_by_in = (int)($row['created_by'] ?? (current_user()['user_id'] ?? 0)) ?: null;
    }
  }

  // validations
  if (!$tgl || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $err[] = 'Tanggal tidak valid.';
  if (!in_array($tipe, ['pendapatan','pengeluaran'], true)) $err[] = 'Tipe tidak valid.';
  if ($nominal === '' || !is_numeric($nominal) || (float)$nominal < 0) $err[] = 'Nominal harus angka ≥ 0.';
  if ($has_method && $method_in !== null && $method_in !== '' && !array_key_exists($method_in, $methods)) $err[] = 'Metode pembayaran tidak valid.';
  if ($has_created_by && $created_by_in !== null && $created_by_in !== 0 && $users_table) {
    // pastikan user exist
    $ucheck = fetch("SELECT user_id FROM users WHERE user_id = ? LIMIT 1", [$created_by_in]);
    if (!$ucheck) $err[] = 'User pilihan (Dibuat Oleh) tidak ditemukan.';
  }

  if (!$err) {
    // build update fields dynamically
    $sets = []; $params = [];

    // tanggal kolom: prefer tgl_transaksi (existing schema). Pastikan row column exists
    if (array_key_exists('tgl_transaksi', $row)) {
      $sets[] = "tgl_transaksi = ?";
      $params[] = date('Y-m-d', strtotime($tgl));
    } else {
      // fallback: if created_at exists we update created_at as datetime
      if (array_key_exists('created_at', $row)) {
        $sets[] = "created_at = ?";
        $params[] = date('Y-m-d H:i:s', strtotime($tgl . ' 00:00:00'));
      } else {
        // try other common names
        if (column_exists('transaksi_keuangan','tanggal')) {
          $sets[] = "tanggal = ?"; $params[] = date('Y-m-d', strtotime($tgl));
        } elseif (column_exists('transaksi_keuangan','date')) {
          $sets[] = "date = ?"; $params[] = date('Y-m-d', strtotime($tgl));
        } else {
          // nothing, but this is unusual
        }
      }
    }

    $sets[] = "tipe = ?"; $params[] = $tipe;
    // nominal column may exist under different name (but row had 'nominal' from fetch "*")
    if (array_key_exists('nominal',$row)) {
      $sets[] = "nominal = ?"; $params[] = (float)$nominal;
    } else {
      // find other candidate
      $colAlt = pick_column('transaksi_keuangan', ['total','jumlah','nilai','amount']);
      if ($colAlt) { $sets[] = "$colAlt = ?"; $params[] = (float)$nominal; }
    }

    // keterangan column
    if (array_key_exists('keterangan',$row)) {
      $sets[] = "keterangan = ?"; $params[] = $keterangan !== '' ? $keterangan : null;
    } else {
      $colAlt = pick_column('transaksi_keuangan', ['catatan','note','deskripsi','description']);
      if ($colAlt) { $sets[] = "$colAlt = ?"; $params[] = $keterangan !== '' ? $keterangan : null; }
    }

    if ($has_method) {
      $sets[] = "method = ?"; $params[] = $method_in !== '' ? $method_in : null;
    }
    if ($has_created_by) {
      $sets[] = "created_by = ?"; $params[] = $created_by_in !== null ? $created_by_in : null;
    }

    // perform update
    $params[] = $id;
    $sql = "UPDATE transaksi_keuangan SET " . implode(', ', $sets) . " WHERE id = ?";
    try {
      query($sql, $params);
      redirect('pages/transaksi/index.php?msg=Perubahan+disimpan');
      exit;
    } catch (Exception $e) {
      $err[] = 'Gagal menyimpan perubahan: ' . e($e->getMessage());
    }
  }
}

// prepare display values
$display = [
  'tgl' => $row['tgl_transaksi'] ?? ($row['tanggal'] ?? ($row['date'] ?? ($row['created_at'] ?? date('Y-m-d')))),
  'tipe' => $row['tipe'] ?? '',
  'nominal' => $row['nominal'] ?? ($row['total'] ?? 0),
  'keterangan' => $row['keterangan'] ?? ($row['catatan'] ?? ''),
  'method' => $row['method'] ?? '',
  'created_by' => $row['created_by'] ?? null
];

// fetch created_by name if possible
$created_by_name = '';
if ($has_created_by && $users_table && !empty($display['created_by'])) {
  $u = fetch("SELECT user_id, username, nama FROM users WHERE user_id = ? LIMIT 1", [$display['created_by']]);
  if ($u) $created_by_name = _user_label($u);
}

// prepare user list only for direktur
$can_change_creator = false;
$current_user = current_user();
if ($has_created_by && $users_table && $current_user && get_user_role_name($current_user) === 'direktur') {
  $can_change_creator = true;
  // $user_options already loaded earlier (if needed)
  try {
    $user_options = fetchAll("SELECT user_id, username, nama FROM users ORDER BY username");
  } catch (Exception $e) {
    $user_options = [];
  }
}

include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card" style="max-width:760px">
  <h2>Edit Transaksi</h2>

  <?php if($err): ?>
    <div class="alert error" style="background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;padding:10px 12px;border-radius:10px;margin-bottom:10px">
      <?php foreach($err as $e) echo '<div>• '.e($e).'</div>'; ?>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <label>Tanggal</label>
        <input type="date" name="tgl_transaksi" value="<?= e(date('Y-m-d', strtotime($display['tgl']))) ?>" required>
      </div>

      <div>
        <label>Tipe</label>
        <?php $cur = $_POST['tipe'] ?? $display['tipe']; ?>
        <select name="tipe" required>
          <option value="pendapatan" <?= $cur === 'pendapatan' ? 'selected' : '' ?>>Pendapatan</option>
          <option value="pengeluaran" <?= $cur === 'pengeluaran' ? 'selected' : '' ?>>Pengeluaran</option>
        </select>
      </div>
    </div>

    <div style="margin-top:12px">
      <label>Nominal</label>
      <input type="number" name="nominal" step="0.01" min="0" value="<?= e($_POST['nominal'] ?? $display['nominal']) ?>" required>
    </div>

    <div style="margin-top:12px">
      <label>Keterangan</label>
      <textarea name="keterangan" rows="3"><?= e($_POST['keterangan'] ?? $display['keterangan']) ?></textarea>
    </div>

    <?php if ($has_method): ?>
      <div style="margin-top:12px">
        <label>Metode (opsional)</label>
        <?php $mcur = $_POST['method'] ?? $display['method']; ?>
        <select name="method">
          <option value="">(kosong)</option>
          <?php foreach ($methods as $k=>$v): ?>
            <option value="<?= e($k) ?>" <?= ($mcur === $k) ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if ($has_created_by): ?>
      <div style="margin-top:12px">
        <label>Dibuat Oleh</label>
        <?php if ($can_change_creator): ?>
          <select name="created_by">
            <option value="">(pilih user)</option>
            <?php foreach($user_options as $u): ?>
              <option value="<?= (int)$u['user_id'] ?>" <?= ((int)($_POST['created_by'] ?? $display['created_by']) === (int)$u['user_id']) ? 'selected' : '' ?>>
                <?= e(_user_label($u)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <div style="padding:8px;border:1px solid var(--line);border-radius:6px;background:#fafafa">
            <?= e($_POST['created_by_name'] ?? $created_by_name ?: '—') ?>
          </div>
          <input type="hidden" name="created_by" value="<?= e($_POST['created_by'] ?? $display['created_by']) ?>">
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn" type="submit"><i class="fas fa-save"></i> Simpan</button>
      <a class="btn outline" href="<?= asset_url('pages/transaksi/index.php') ?>"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
