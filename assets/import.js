(() => {
  'use strict';
  const box = document.getElementById('pds-import-progress');
  const button = document.getElementById('pds-import-start');
  if (!box || !button) return;
  let running = false;
  button.addEventListener('click', async () => {
    if (running) return;
    running = true; button.disabled = true;
    const error = document.getElementById('pds-import-error');error.hidden = true;
    try {
      let done = false;
      while (!done) {
        const body = new URLSearchParams({action:'pds_import_batch',job:box.dataset.job,_ajax_nonce:box.dataset.nonce});
        const response = await fetch(box.dataset.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
        let result;
        try { result = await response.json(); } catch (_) { throw new Error('Não foi possível ler a resposta. Recarregue a página para continuar.'); }
        if (!response.ok || !result.success) throw new Error(result.data?.message || 'Não foi possível processar a etapa. Recarregue a página e continue.');
        const data = result.data; done = data.done;
        document.getElementById('pds-import-bar').value = data.processed;
        document.getElementById('pds-import-status').textContent = data.processed + ' de ' + data.total + ' registros processados.' + (done ? ' Importação concluída.' : '');
        Object.entries(data.counts).forEach(([key,value]) => { const el = box.querySelector('[data-import-count="'+key+'"]');if (el) el.textContent = value.toLocaleString('pt-BR'); });
        if (data.error) throw new Error(data.error);
        if (done) { button.hidden = true; break; }
        await new Promise(resolve => setTimeout(resolve,120));
      }
    } catch (e) {
      error.hidden = false;error.querySelector('p').textContent = e.message || 'Verifique sua conexão e tente novamente.';button.textContent = 'Continuar importação';
    } finally { running = false;button.disabled = false; }
  });
})();
