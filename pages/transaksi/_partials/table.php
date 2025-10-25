<?php
// pages/transaksi/_partials/table.php
$tab   = $_GET['tab']  ?? 'all';
$page  = (int)($_GET['page'] ?? 1);
$limit = 10;

$payload = trx_list($tab, $page, $limit);
$rows  = $payload['rows'];
$total = $payload['total'];
$pages = max(1, (int)ceil($total/$limit));
?>
<table class="table">
  <thead>
    <tr>
      <th>Tanggal</th>
      <th>Jenis</th>
      <th>Nominal</th>
      <th>Keterangan</th>
      <th>Created by</th>
      <th class="no-print">Edit</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($rows as $r): ?>
    <tr>
      <td><?=e(date('d M Y', strtotime($r['tgl'])))?></td>
      <td><?=e(ucfirst($r['jenis']))?></td>
      <td><?=rupiah($r['nominal'])?></td>
      <td><?=e($r['keterangan'])?></td>
      <td><?=e($r['created_by'])?></td>
      <td class="no-print">
        <a title="Edit" href="<?=asset_url('pages/transaksi/edit.php?id='.$r['id'])?>"><i class="fas fa-pen"></i></a>
        &nbsp;
        <a title="Hapus" onclick="return confirm('Hapus transaksi ini?')" href="<?=asset_url('pages/transaksi/delete.php?id='.$r['id'])?>"><i class="fas fa-trash"></i></a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if(!$rows): ?>
    <tr><td colspan="6" class="muted">Belum ada data.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<!-- Pagination -->
<?php if($pages > 1): ?>
  <div class="pagination no-print">
    <?php
      $base = '?'.http_build_query(['tab'=>$tab]);
      for($p=1;$p<=$pages;$p++){
        $active = $p===$page ? 'active' : '';
        echo "<a class=\"$active\" href=\"$base&page=$p\">$p</a>";
      }
    ?>
  </div>
<?php endif; ?>
