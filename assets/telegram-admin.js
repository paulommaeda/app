(() => {
  'use strict';
  const select = document.getElementById('pds-ai-provider');
  if (!select) return;
  const refresh = () => document.querySelectorAll('[data-pds-provider]').forEach(row => {
    row.hidden = row.dataset.pdsProvider !== select.value;
  });
  select.addEventListener('change', refresh);
  refresh();
})();
