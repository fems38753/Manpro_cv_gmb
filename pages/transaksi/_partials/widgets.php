<?php
// pages/transaksi/_partials/widgets.php
require_once __DIR__ . '/../../../functions/transaction.php';

if (!function_exists('trx_summary')) {
  function trx_summary() {
    return [
      'balance' => 0,
      'pemasukan' => 0,
      'pengeluaran' => 0,
      'saving' => 0,
    ];
  }
}

$sum = trx_summary();
?>
<div class="kpi">
  <div class="card">
    <div><i class="fas fa-wallet"></i> My Balance</div>
    <h1><?=rupiah($sum['balance'])?></h1>
  </div>
  <div class="card">
    <div><i class="fas fa-circle-arrow-down"></i> Pemasukan</div>
    <h1><?=rupiah($sum['pemasukan'])?></h1>
  </div>
  <div class="card">
    <div><i class="fas fa-circle-arrow-up"></i> Pengeluaran</div>
    <h1><?=rupiah($sum['pengeluaran'])?></h1>
  </div>
  <div class="card">
    <div><i class="fas fa-piggy-bank"></i> Total Saving</div>
    <h1><?=rupiah($sum['saving'])?></h1>
  </div>
</div>
