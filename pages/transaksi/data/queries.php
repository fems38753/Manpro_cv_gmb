<?php
// pages/transaksi/data/queries.php
require_once __DIR__ . '/../../../config/database.php';

/* -----------------------
   Utility internal
   ----------------------- */
function _safe_col(string $col): string {
  if (preg_match('/^[A-Za-z0-9_]+$/', $col)) return $col;
  throw new Exception("Invalid column identifier: $col");
}

/* -----------------------
   Column detection
   ----------------------- */
function trx_pick_date_col(): string {
  if (function_exists('pick_column')) {
    $c = pick_column('transaksi_keuangan', ['tgl_transaksi','tanggal','date','created_at','tgl']);
    if ($c) return _safe_col($c);
  }
  return 'tgl_transaksi';
}

function trx_pick_nominal_col(): string {
  if (function_exists('pick_column')) {
    $c = pick_column('transaksi_keuangan', ['nominal','total','jumlah','nilai','amount']);
    if ($c) return _safe_col($c);
  }
  return 'nominal';
}

function trx_pick_ket_col(): string {
  if (function_exists('pick_column')) {
    $c = pick_column('transaksi_keuangan', ['keterangan','catatan','note','deskripsi','description']);
    if ($c) return _safe_col($c);
  }
  return 'keterangan';
}

function trx_pick_pk_col(): string {
  try {
    $row = fetch("SHOW KEYS FROM transaksi_keuangan WHERE Key_name='PRIMARY'");
    if ($row && !empty($row['Column_name'])) return _safe_col($row['Column_name']);
  } catch (Throwable $e) { /* ignore */ }

  if (function_exists('pick_column')) {
    $c = pick_column('transaksi_keuangan', ['id','trx_id','transaksi_id','keu_id']);
    if ($c) return _safe_col($c);
  }
  return 'id';
}

/* -----------------------
   List + pagination (include method & created_by name)
   ----------------------- */
function trx_list(string $filter='all', int $page=1, int $perPage=100, ?string $from=null, ?string $to=null, ?string $q=null): array {
  $pk  = trx_pick_pk_col();
  $tgl = trx_pick_date_col();
  $nom = trx_pick_nominal_col();
  $ket = trx_pick_ket_col();

  $where = [];
  $params = [];

  if ($filter === 'pendapatan')  $where[] = "tipe = 'pendapatan'";
  if ($filter === 'pengeluaran') $where[] = "tipe = 'pengeluaran'";

  if ($from) { $where[] = "$tgl >= ?"; $params[] = $from; }
  if ($to)   { $where[] = "$tgl <= ?"; $params[] = $to; }

  if ($q !== null && $q !== '') {
    $where[] = "(COALESCE($ket,'') LIKE ? OR COALESCE(tipe,'') LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%";
  }

  $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

  $totalRow = fetch("SELECT COUNT(*) AS c FROM transaksi_keuangan $wsql", $params);
  $total = (int)($totalRow['c'] ?? 0);

  $page = max(1, (int)$page);
  $perPage = max(1, min(1000, (int)$perPage));
  $offset = ($page - 1) * $perPage;

  // ambil created_by username (jika kolom created_by ada & table users ada)
  $join_user = '';
  $sel_created_by = 'NULL AS created_by_name';
  if (table_exists('users') && column_exists('transaksi_keuangan','created_by')) {
    $join_user = "LEFT JOIN users u ON u.user_id = tk.created_by";
    $sel_created_by = "COALESCE(u.username, CONCAT('user:', tk.created_by)) AS created_by_name";
  }

  // include method (jika kolom ada)
  $sel_method = column_exists('transaksi_keuangan','method') ? "tk.method AS method" : "NULL AS method";

  $sql = "
    SELECT
      tk.`$pk` AS id,
      tk.$tgl AS tgl,
      tk.tipe AS tipe,
      tk.$nom AS nominal,
      COALESCE(tk.$ket,'') AS keterangan,
      $sel_method,
      $sel_created_by
    FROM transaksi_keuangan tk
    $join_user
    $wsql
    ORDER BY tk.$tgl DESC, tk.`$pk` DESC
    LIMIT ? OFFSET ?
  ";

  $execParams = array_merge($params, [$perPage, $offset]);
  $rows = fetchAll($sql, $execParams);

  return ['rows'=>$rows, 'total'=>$total];
}

/* -----------------------
   Single + CRUD (include method & created_by)
   ----------------------- */

function trx_get_by_id(int $id): ?array {
  $pk  = trx_pick_pk_col();
  $tgl = trx_pick_date_col();
  $nom = trx_pick_nominal_col();
  $ket = trx_pick_ket_col();

  $sel_method = column_exists('transaksi_keuangan','method') ? "method" : "NULL AS method";
  $sel_created_by = column_exists('transaksi_keuangan','created_by') && table_exists('users')
    ? "tk.created_by, COALESCE(u.username, CONCAT('user:',tk.created_by)) AS created_by_name"
    : (column_exists('transaksi_keuangan','created_by') ? "tk.created_by, NULL AS created_by_name" : "NULL AS created_by, NULL AS created_by_name");

  $join_user = (column_exists('transaksi_keuangan','created_by') && table_exists('users')) ? "LEFT JOIN users u ON u.user_id = tk.created_by" : "";

  $sql = "SELECT tk.`$pk` AS id, tk.$tgl AS tgl, tk.tipe, tk.$nom AS nominal, COALESCE(tk.$ket,'') AS keterangan, $sel_method, $sel_created_by
          FROM transaksi_keuangan tk
          $join_user
          WHERE tk.`$pk` = ? LIMIT 1";

  $row = fetch($sql, [$id]);
  return $row ?: null;
}

/**
 * trx_create: menerima array: tipe,tgl,nominal,keterangan,method,created_by
 */
function trx_create(array $data): int {
  $pk  = trx_pick_pk_col();
  $tgl = trx_pick_date_col();
  $nom = trx_pick_nominal_col();
  $ket = trx_pick_ket_col();

  $tipe = $data['tipe'] ?? 'pendapatan';
  $date = $data['tgl'] ?? date('Y-m-d');
  $nominal = (float)($data['nominal'] ?? 0);
  $keterangan = $data['keterangan'] ?? null;
  $method = column_exists('transaksi_keuangan','method') ? ($data['method'] ?? null) : null;
  $created_by = column_exists('transaksi_keuangan','created_by') ? ($data['created_by'] ?? null) : null;

  begin();
  try {
    $cols = ['tipe', $tgl, $nom, $ket];
    $place = ['?','?','?','?'];
    $vals = [$tipe, $date, $nominal, $keterangan];

    if (column_exists('transaksi_keuangan','method')) { $cols[] = 'method'; $place[] = '?'; $vals[] = $method; }
    if (column_exists('transaksi_keuangan','created_by')) { $cols[] = 'created_by'; $place[] = '?'; $vals[] = $created_by; }

    $cols[] = 'created_at'; $place[] = 'NOW()';

    $sql = "INSERT INTO transaksi_keuangan (" . implode(',', $cols) . ") VALUES (" . implode(',', $place) . ")";
    query($sql, $vals);
    $id = (int) lastId();
    commit();
    return $id;
  } catch (Exception $e) {
    rollback();
    throw $e;
  }
}

/**
 * trx_update($id, $data)
 * allowed keys: tipe,tgl,nominal,keterangan,method,created_by
 */
function trx_update(int $id, array $data): bool {
  $pk  = trx_pick_pk_col();
  $tgl = trx_pick_date_col();
  $nom = trx_pick_nominal_col();
  $ket = trx_pick_ket_col();

  $sets = []; $params = [];

  if (isset($data['tipe'])) { $sets[] = "tipe = ?"; $params[] = $data['tipe']; }
  if (isset($data['tgl'])) { $sets[] = "$tgl = ?"; $params[] = $data['tgl']; }
  if (isset($data['nominal'])) { $sets[] = "$nom = ?"; $params[] = (float)$data['nominal']; }
  if (array_key_exists('keterangan', $data)) { $sets[] = "$ket = ?"; $params[] = $data['keterangan']; }
  if (column_exists('transaksi_keuangan','method') && array_key_exists('method',$data)) { $sets[] = "method = ?"; $params[] = $data['method']; }
  if (column_exists('transaksi_keuangan','created_by') && array_key_exists('created_by',$data)) { $sets[] = "created_by = ?"; $params[] = $data['created_by']; }

  if (empty($sets)) return false;

  $params[] = $id;
  $sql = "UPDATE transaksi_keuangan SET " . implode(', ', $sets) . " WHERE `$pk` = ?";

  begin();
  try {
    query($sql, $params);
    commit();
    return true;
  } catch (Exception $e) {
    rollback();
    throw $e;
  }
}

/**
 * trx_delete($id)
 */
function trx_delete(int $id): bool {
  $pk = trx_pick_pk_col();
  begin();
  try {
    query("DELETE FROM transaksi_keuangan WHERE `$pk` = ?", [$id]);
    commit();
    return true;
  } catch (Exception $e) {
    rollback();
    throw $e;
  }
}

/* -----------------------
   Summary
   ----------------------- */
function trx_summary(?string $from = null, ?string $to = null): array {
  $nom = trx_pick_nominal_col();
  $tgl = trx_pick_date_col();

  $where = [];
  $params = [];
  if ($from) { $where[] = "$tgl >= ?"; $params[] = $from; }
  if ($to) { $where[] = "$tgl <= ?"; $params[] = $to; }
  $wsql = $where ? ('AND '.implode(' AND ',$where)) : '';

  $inRow = fetch("SELECT COALESCE(SUM($nom),0) AS s FROM transaksi_keuangan WHERE tipe='pendapatan' $wsql", $params);
  $outRow = fetch("SELECT COALESCE(SUM($nom),0) AS s FROM transaksi_keuangan WHERE tipe='pengeluaran' $wsql", $params);

  $in = (float)($inRow['s'] ?? 0);
  $out = (float)($outRow['s'] ?? 0);
  $saldo = $in - $out;

  return ['in'=>$in, 'out'=>$out, 'saldo'=>$saldo];
}
