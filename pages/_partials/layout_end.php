<?php  ?>
  </main>
  <script src="<?=asset_url('assets/js/script.js')?>?v=1">
    // Cegah submit ganda (klik dobel / refresh cepat)
  (function(){
    const form = document.querySelector('form');
    if (!form) return;
    form.addEventListener('submit', function(){
      const btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; btn.textContent = 'Menyimpan...'; }
    }, { once: true });
  })();
  </script>
</body>
</html>
