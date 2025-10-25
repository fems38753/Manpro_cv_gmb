<?php
// includes/functions.php
// Utility helpers for MANPRO

require_once __DIR__ . '/../config/database.php'; // defines query(), fetch(), table_exists(), etc.

/** Format rupiah */
function formatRupiah($number) {
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

/** Upload file (wrapper ringan) */
function uploadFile(array $file, string $target_dir, array $allowed_types = ['jpg','jpeg','png','gif'], int $maxBytes = 5_000_000) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success'=>false,'message'=>'Upload tidak valid'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success'=>false,'message'=>'Upload error code '.$file['error']];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_types, true)) {
        return ['success'=>false,'message'=>'Hanya file '.implode(',', $allowed_types).' yang diizinkan'];
    }
    if ($file['size'] > $maxBytes) {
        return ['success'=>false,'message'=>'Ukuran file terlalu besar'];
    }
    ensure_dir($target_dir); // helper ada di config/database.php
    $name = bin2hex(random_bytes(8)).'.'.$ext;
    $dest = rtrim($target_dir,'/').'/'.$name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success'=>false,'message'=>'Gagal menyimpan file'];
    }
    // return relative path (relative to APP_DIR)
    $rel = str_replace('\\','/',$dest);
    if (strpos($rel, str_replace('\\','/', APP_DIR.'/')) === 0) {
        $rel = substr($rel, strlen(str_replace('\\','/', APP_DIR.'/')));
    }
    $rel = ltrim($rel, '/');
    return ['success'=>true,'filename'=>$name,'path'=>$rel];
}

/**
 * Ambil statistik keuangan per periode (monthly/yearly etc).
 * Menggunakan transaksi_keuangan jika ada, else fallback ke penjualan/pembelian.
 * $period: 'monthly','yearly' (untuk sekarang)
 */
function getFinancialStats($period = 'monthly') {
    $rows = [];

    if (table_exists('transaksi_keuangan')) {
        if ($period === 'monthly') {
            $rows = fetchAll("
                SELECT DATE_FORMAT(tgl_transaksi,'%b %Y') AS period,
                       SUM(CASE WHEN tipe='pendapatan' THEN nominal ELSE 0 END) AS pemasukan,
                       SUM(CASE WHEN tipe='pengeluaran' THEN nominal ELSE 0 END) AS pengeluaran
                FROM transaksi_keuangan
                WHERE YEAR(tgl_transaksi) >= YEAR(CURDATE()) - 1
                GROUP BY YEAR(tgl_transaksi), MONTH(tgl_transaksi)
                ORDER BY YEAR(tgl_transaksi), MONTH(tgl_transaksi)
            ");
        } else { // yearly
            $rows = fetchAll("
                SELECT YEAR(tgl_transaksi) AS period,
                       SUM(CASE WHEN tipe='pendapatan' THEN nominal ELSE 0 END) AS pemasukan,
                       SUM(CASE WHEN tipe='pengeluaran' THEN nominal ELSE 0 END) AS pengeluaran
                FROM transaksi_keuangan
                GROUP BY YEAR(tgl_transaksi)
                ORDER BY YEAR(tgl_transaksi)
            ");
        }
        return $rows;
    }

    // fallback: use penjualan/pembelian
    $penjDate = table_exists('penjualan') ? pick_date_column('penjualan') : null;
    $penjTot  = table_exists('penjualan') ? pick_total_column('penjualan') : null;
    $pembDate = table_exists('pembelian') ? pick_date_column('pembelian') : null;
    $pembTot  = table_exists('pembelian') ? pick_total_column('pembelian') : null;

    if ($penjDate && $penjTot && $pembDate && $pembTot) {
        if ($period === 'monthly') {
            return fetchAll("
              SELECT DATE_FORMAT($penjDate, '%b %Y') AS period,
                     (SELECT COALESCE(SUM($penjTot),0) FROM penjualan WHERE DATE_FORMAT($penjDate,'%b %Y') = DATE_FORMAT(p.$penjDate,'%b %Y')) AS pemasukan,
                     (SELECT COALESCE(SUM($pembTot),0) FROM pembelian WHERE DATE_FORMAT($pembDate,'%b %Y') = DATE_FORMAT(p.$penjDate,'%b %Y')) AS pengeluaran
              FROM penjualan p
              GROUP BY YEAR($penjDate), MONTH($penjDate)
              ORDER BY YEAR($penjDate), MONTH($penjDate)
            ");
        } else {
            return fetchAll("
              SELECT YEAR($penjDate) AS period,
                     (SELECT COALESCE(SUM($penjTot),0) FROM penjualan WHERE YEAR($penjDate) = YEAR(p.$penjDate)) AS pemasukan,
                     (SELECT COALESCE(SUM($pembTot),0) FROM pembelian WHERE YEAR($pembDate) = YEAR(p.$penjDate)) AS pengeluaran
              FROM penjualan p
              GROUP BY YEAR($penjDate)
              ORDER BY YEAR($penjDate)
            ");
        }
    }

    return [];
}
