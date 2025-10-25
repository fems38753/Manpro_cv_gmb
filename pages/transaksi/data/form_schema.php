<?php
// pages/transaksi/_data/form_schema.php
// Revised form schema & helpers for Transaksi module
// Provides dynamic account options (if accounts table exists) and sensible defaults.
// Copy this file to pages/transaksi/_data/form_schema.php

require_once __DIR__ . '/../../../config/database.php';

/**
 * Ambil daftar akun (id => "code — name") jika tabel accounts ada.
 * Jika tidak ada, kembalikan array kosong.
 */
function trx_get_account_options(): array {
  if (!function_exists('table_exists') || !table_exists('accounts')) return [];
  try {
    $rows = fetchAll("SELECT id, COALESCE(code,'') AS code, COALESCE(name,'') AS name FROM accounts ORDER BY code, name");
    $out = [];
    foreach ($rows as $r) {
      $label = trim(($r['code'] !== '' ? $r['code'].' — ' : '') . $r['name']);
      $out[(int)$r['id']] = $label ?: ('Account ' . $r['id']);
    }
    return $out;
  } catch (Exception $e) {
    return [];
  }
}

/**
 * Usulkan akun default berdasarkan tipe akun:
 * - untuk debit (kas) cari akun dengan nama 'kas' atau type asset
 * - untuk credit (pendapatan) cari akun type income/revenue
 * Mengembalikan array ['debit' => id|null, 'credit' => id|null]
 */
function trx_suggest_accounts(): array {
  $suggest = ['debit' => null, 'credit' => null];
  if (!function_exists('table_exists') || !table_exists('accounts')) return $suggest;

  // try debit -> prefer name "kas" or "bank", fallback to type 'asset'
  try {
    $row = fetch("SELECT id FROM accounts WHERE LOWER(name) LIKE '%kas%' LIMIT 1");
    if ($row && !empty($row['id'])) $suggest['debit'] = (int)$row['id'];
    if (!$suggest['debit']) {
      $row = fetch("SELECT id FROM accounts WHERE LOWER(name) LIKE '%bank%' LIMIT 1");
      if ($row && !empty($row['id'])) $suggest['debit'] = (int)$row['id'];
    }
    if (!$suggest['debit']) {
      $row = fetch("SELECT id FROM accounts WHERE LOWER(type) = 'asset' LIMIT 1");
      if ($row && !empty($row['id'])) $suggest['debit'] = (int)$row['id'];
    }

    // credit -> prefer accounts type income/revenue or name containing 'pendapatan'
    $row = fetch("SELECT id FROM accounts WHERE LOWER(type) IN ('income','revenue') LIMIT 1");
    if ($row && !empty($row['id'])) $suggest['credit'] = (int)$row['id'];
    if (!$suggest['credit']) {
      $row = fetch("SELECT id FROM accounts WHERE LOWER(name) LIKE '%pendapatan%' LIMIT 1");
      if ($row && !empty($row['id'])) $suggest['credit'] = (int)$row['id'];
    }
  } catch (Exception $e) {
    // ignore and return nulls
  }

  return $suggest;
}

/**
 * Main schema function.
 * Returns an array describing form fields. Consumers can read this and render forms.
 *
 * Field structure:
 *  - label (string)
 *  - type  (date|select|number|text|textarea|accounts)
 *  - required (bool)
 *  - default (mixed)
 *  - options (for select) => associative array value => label
 *  - min (for number)
 *  - placeholder / help (optional)
 */
function trx_form_fields(): array {
  // available payment methods
  $methods = [
    'cash'     => 'Tunai',
    'transfer' => 'Transfer',
    'kredit'   => 'Kredit',
    'lainnya'  => 'Lainnya'
  ];

  // account options and suggestions
  $accountOptions = trx_get_account_options();
  $suggest = trx_suggest_accounts();

  // convert account options to select options; if none available keep empty to be filled by UI
  $acct_opts = $accountOptions; // id => label

  return [
    'tgl' => [
      'label' => 'Tanggal',
      'type' => 'date',
      'required' => true,
      'default' => date('Y-m-d'),
      'help' => 'Tanggal transaksi (YYYY-MM-DD)'
    ],

    'jenis' => [
      'label' => 'Jenis',
      'type' => 'select',
      'required' => true,
      'options' => [
        'pendapatan' => 'Pendapatan',
        'pengeluaran' => 'Pengeluaran'
      ],
      'default' => 'pendapatan',
      'help' => 'Pilih apakah ini pemasukan atau pengeluaran'
    ],

    'debit_account' => [
      'label' => 'Akun Debit',
      'type' => 'select',
      'required' => true,
      'options' => $acct_opts,
      'default' => $suggest['debit'],
      'placeholder' => $acct_opts ? null : 'Tambahkan akun di modul Keuangan dahulu',
      'help' => 'Akun yang didebet (mis. Kas/Bank)'
    ],

    'credit_account' => [
      'label' => 'Akun Kredit',
      'type' => 'select',
      'required' => true,
      'options' => $acct_opts,
      'default' => $suggest['credit'],
      'placeholder' => $acct_opts ? null : 'Tambahkan akun di modul Keuangan dahulu',
      'help' => 'Akun yang dikredit (mis. Pendapatan/Beban)'
    ],

    'nominal' => [
      'label' => 'Nominal',
      'type' => 'number',
      'required' => true,
      'min' => 0.01,
      'step' => '0.01',
      'default' => '',
      'help' => 'Jumlah uang transaksi (desimal diperbolehkan)'
    ],

    'metode' => [
      'label' => 'Metode Pembayaran',
      'type' => 'select',
      'required' => false,
      'options' => $methods,
      'default' => 'cash',
      'help' => 'Metode penerimaan/pengeluaran'
    ],

    'keterangan' => [
      'label' => 'Keterangan',
      'type' => 'textarea',
      'required' => false,
      'default' => '',
      'help' => 'Catatan singkat, referensi atau informasi tambahan'
    ],

    // hidden/auxiliary
    'created_by' => [
      'label' => 'Created by',
      'type' => 'hidden',
      'required' => false,
      'default' => $_SESSION['user']['user_id'] ?? ($_SESSION['user']['username'] ?? null)
    ]
  ];
}
