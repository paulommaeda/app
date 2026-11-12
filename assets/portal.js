/* Portal de Serviços. All private data stays on the server; no third-party scripts. */
(() => {
  'use strict';
  const root = document.getElementById('pds-app');
  if (!root || typeof PDS === 'undefined') return;
  const $ = (s, base = root) => base.querySelector(s);
  const $$ = (s, base = root) => [...base.querySelectorAll(s)];
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const num = (v, d = 2) => Number(v).toLocaleString('pt-BR', {minimumFractionDigits:d, maximumFractionDigits:d});
  const money = v => Number(v).toLocaleString('pt-BR', {style:'currency', currency:'BRL'});
  const date = v => /^\d{4}-\d{2}-\d{2}$/.test(v || '') ? v.split('-').reverse().join('/') : '—';
  const fuelName = v => ({G:'Gasolina', A:'Etanol', D:'Diesel'}[v] || v);
  const icon = name => '<svg class="pds-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ({fuel:'<path d="M4 21V4h10v17M2 21h14M4 11h10m3-6 4 4v9a2 2 0 0 1-4 0v-4h-3"/>',chart:'<path d="M3 3v18h18M7 16l4-5 4 2 6-8"/>',car:'<path d="m5 7 2-4h10l2 4 2 4v8H3v-8l2-4ZM3 11h18M7 15h1m8 0h1"/>',plus:'<path d="M12 5v14M5 12h14"/>',check:'<path d="m5 12 4 4L19 6"/>'}[name] || '') + '</svg>';
  let toastTimer;
  function toast(message) { const el = $('#pds-toast'); el.textContent = message; el.hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(() => { el.hidden = true; }, 5000); }
  function showError(el, message) { if (!el) return; el.textContent = message || ''; el.hidden = !message; }
  function run(action, onError) { Promise.resolve().then(action).catch(e => onError ? onError(e.message) : toast(e.message)); }
  async function busy(form, action) {
    const button = $('button[type="submit"]', form);
    if (button?.disabled) return;
    if (button) button.disabled = true;
    const err = $('.pds-form-error', form) || $('.pds-form-error', form.parentElement);
    showError(err, '');
    try { return await action(); } catch (e) { if (err) showError(err, e.message); else throw e; }
    finally { if (button) button.disabled = false; }
  }
  async function api(path, method = 'GET', body = null) {
    const headers = {'Accept':'application/json'};
    if (/^auth\/(login|register|forgot|reset)$/.test(path)) headers['X-PDS-CSRF'] = PDS.csrf;
    else if (PDS.nonce) headers['X-WP-Nonce'] = PDS.nonce;
    const options = {method, headers, credentials:'same-origin', cache:'no-store'};
    if (body !== null) { headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(body); }
    // rest_url() uses a query parameter when pretty permalinks are disabled.
    const url = new URL(PDS.api, window.location.href);
    const queryAt = path.indexOf('?');
    const route = queryAt === -1 ? path : path.slice(0,queryAt);
    if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route',url.searchParams.get('rest_route').replace(/\/?$/,'/') + route);
    else url.pathname = url.pathname.replace(/\/?$/,'/') + route;
    if (queryAt !== -1) new URLSearchParams(path.slice(queryAt+1)).forEach((v,k) => url.searchParams.set(k,v));
    let response;
    try { response = await fetch(url.href, options); } catch (_) { throw new Error('Não foi possível conectar. Confira sua conexão e tente novamente.'); }
    let result;
    try { result = await response.json(); } catch (_) { throw new Error('O servidor não retornou os dados esperados. Atualize a página e tente novamente.'); }
    if (!response.ok) throw new Error(result.message || 'Não foi possível concluir a operação.');
    return result;
  }
  const currentDate = $('[data-current-date]');
  if (currentDate) currentDate.textContent = new Date(PDS.today + 'T12:00:00').toLocaleDateString('pt-BR', {day:'numeric', month:'long', year:'numeric'});
  $$('[data-action="logout"]').forEach(button => button.addEventListener('click', () => {
    if (button.disabled) return; button.disabled = true;
    run(async () => { await api('auth/logout', 'POST', {}); window.location.assign(PDS.portalUrl); }, m => { button.disabled = false; toast(m); });
  }));

  if ($('[data-auth-form]')) {
    function authView(view) {
      $$('[data-auth-form]').forEach(f => { f.hidden = f.dataset.authForm !== view; });
      $$('[data-auth-view]').forEach(b => b.classList.toggle('is-active', b.dataset.authView === view));
      $('#pds-auth-tabs').hidden = !['login','register'].includes(view);
      const titles = {login:['Bom ter você por aqui.','Entre para acessar seus serviços.'],register:['Seu espaço começa aqui.','Crie uma conta e simplifique sua rotina.'],forgot:['Vamos recuperar seu acesso.','Enviaremos um link para o seu e-mail.'],reset:['Uma nova senha.','Escolha uma senha para voltar ao portal.']};
      $('#pds-auth-title').textContent = titles[view][0]; $('#pds-auth-subtitle').textContent = titles[view][1];
      showError($('#pds-auth-error'), ''); showError($('#pds-auth-success'), '');
    }
    $$('[data-auth-view]').forEach(b => b.addEventListener('click', () => authView(b.dataset.authView)));
    $$('[data-auth-form]').forEach(form => form.addEventListener('submit', event => {
      event.preventDefault(); const view = form.dataset.authForm;
      const button = $('button[type="submit"]', form); if (button.disabled) return;
      const data = Object.fromEntries(new FormData(form));
      if (data.password_confirm !== undefined && data.password !== data.password_confirm) { showError($('#pds-auth-error'), 'As senhas não conferem.'); return; }
      if (view === 'reset') { data.key = PDS.reset.key; data.login = PDS.reset.login; }
      button.disabled = true; showError($('#pds-auth-error'), ''); showError($('#pds-auth-success'), '');
      run(async () => {
        const result = await api('auth/' + view, 'POST', data);
        if (view === 'login' || view === 'register') {
          const url = new URL(window.location.href); ['key','login','pds_action'].forEach(k => url.searchParams.delete(k)); window.location.assign(url.href);
        } else {
          if (view === 'reset') { form.reset(); authView('login'); const url = new URL(location.href); ['key','login','pds_action'].forEach(k => url.searchParams.delete(k)); history.replaceState(null, '', url); }
          showError($('#pds-auth-success'), result.message);
        }
        button.disabled = false;
      }, message => { showError($('#pds-auth-error'), message); button.disabled = false; });
    }));
    if (PDS.reset.key && PDS.reset.login) authView('reset');
  }
  const accountForm = $('#pds-account-form');
  if (accountForm) accountForm.addEventListener('submit', e => { e.preventDefault(); run(() => busy(accountForm, async () => { await api('account', 'POST', Object.fromEntries(new FormData(accountForm))); toast('Conta atualizada.'); setTimeout(() => window.location.reload(), 500); })); });
  if (!$('#pds-fuel')) return;

  const state = {vehicles:[], id:null, tab:'dashboard', editingRecord:null, editingVehicle:null, records:new Map(), page:1, historyPages:1, reportFilter:null, report:null, generation:0, exporting:false};
  const vehicle = () => state.vehicles.find(v => v.id === state.id);
  const recordForm = $('#pds-record-form');
  const vehicleDialog = $('#pds-vehicle-dialog');
  const fuelError = $('#pds-fuel-error');
  const collectionPath = id => 'fuel/vehicles/' + id;
  const emptyChart = message => '<div class="pds-chart-empty">' + icon('chart') + '<span>' + esc(message) + '</span></div>';
  function resetRecord() {
    state.editingRecord = null; recordForm.reset(); recordForm.elements.filled_on.value = PDS.today;
    recordForm.elements.filled_on.max = PDS.today;
    $('#pds-record-title').textContent = 'Registrar abastecimento'; $('#pds-cancel-edit').hidden = true;
    $('button[type="submit"]', recordForm).innerHTML = 'Salvar abastecimento ' + icon('check');
    showError($('.pds-form-error', recordForm), ''); preview(); document.dispatchEvent(new CustomEvent('pds:fuel-edit',{detail:null}));
  }
  function setTab(tab) {
    if (!['dashboard','new','history','reports'].includes(tab)) return;
    state.tab = tab;
    $$('.pds-tabs [data-fuel-tab]').forEach(b => { const active = b.dataset.fuelTab === tab; b.classList.toggle('is-active', active); if (active) b.setAttribute('aria-current','page'); else b.removeAttribute('aria-current'); });
    $$('[data-fuel-view]').forEach(v => { v.hidden = !state.id || v.dataset.fuelView !== tab; });
    if (state.id && tab === 'history') run(() => loadHistory(1), m => showError(fuelError, m));
  }
  $$('[data-fuel-tab]').forEach(button => button.addEventListener('click', () => { if (button.dataset.fuelTab === 'new' && !state.editingRecord) resetRecord(); setTab(button.dataset.fuelTab); }));
  $('#pds-cancel-edit').addEventListener('click', () => { resetRecord(); setTab('history'); });
  function kpis(summary, report = false) {
    const cards = report ? [
      ['Gastos no período',money(summary.spent),'Valores efetivamente pagos','fuel'],
      ['Distância registrada',num(summary.distance,0) + ' km','Entre os abastecimentos','car'],
      ['Consumo médio',summary.consumption === null ? '—' : num(summary.consumption) + ' km/L','Média ponderada · tanques completos','chart'],
      ['Volume abastecido',num(summary.liters) + ' L',summary.count + ' abastecimento(s)','fuel']
    ] : [
      ['Total gasto',money(summary.spent),summary.count + ' abastecimento(s) registrados','fuel'],
      ['Distância registrada',num(summary.distance,0) + ' km','Entre os abastecimentos','car'],
      ['Consumo médio',summary.consumption === null ? '—' : num(summary.consumption) + ' km/L','Entre tanques completos','chart'],
      ['Custo por km',summary.cost_per_km === null ? '—' : money(summary.cost_per_km),'Nos intervalos medidos','chart']
    ];
    return cards.map(c => '<article class="pds-kpi"><div class="pds-kpi-label">' + esc(c[0]) + icon(c[3]) + '</div><strong>' + esc(c[1]) + '</strong><small>' + esc(c[2]) + '</small></article>').join('');
  }
  function barChart(data, mode = 'month') {
    const entries = Object.entries(data).sort(([a],[b]) => a.localeCompare(b)).slice(-12);
    if (!entries.length) return emptyChart('Seus gastos aparecerão aqui após o primeiro abastecimento.');
    const max = Math.max(...entries.map(e => Number(e[1])), 1);
    const label = key => mode === 'month' ? key.slice(5) + '/' + key.slice(2,4) : date(key).slice(0,5);
    return '<div class="pds-bar-chart" aria-label="Gastos em reais' + (entries.length === 12 ? ', últimas 12 datas exibidas' : '') + '">' + entries.map(([key,value]) => '<div class="pds-bar-row"><span>' + esc(label(key)) + '</span><div class="pds-bar-track"><span style="width:' + (Number(value)/max*100).toFixed(2) + '%"></span></div><strong>' + esc(money(value)) + '</strong></div>').join('') + '</div>' + (Object.keys(data).length > 12 ? '<p class="pds-chart-caption">Últimos 12 períodos. Exporte o CSV para consultar todos.</p>' : '');
  }
  function lineChart(points, field, unit) {
    if (!points.length) return emptyChart('Registre dois tanques completos para acompanhar esta evolução.');
    const visible = points.slice(-30); const values = visible.map(p => Number(p[field]));
    const max = Math.max(...values) * 1.2 || 1;
    const coords = values.map((v,i) => [32 + i * 336 / Math.max(values.length - 1,1), 155 - v/max*128]);
    const line = coords.map(p => p.map(n => n.toFixed(2)).join(',')).join(' ');
    const rows = visible.map(p => '<tr><td>' + esc(date(p.date)) + '</td><td>' + esc(num(p[field])) + ' ' + esc(unit) + '</td></tr>').join('');
    return '<svg viewBox="0 0 400 180" class="pds-line-chart" role="img" aria-label="' + esc(field === 'consumption' ? 'Consumo' : 'Custo por quilômetro') + ', ' + visible.length + ' intervalo(s)"><path d="M30 30H380M30 90H380M30 155H380" stroke="#edf1e8" stroke-width="1" fill="none"/><polyline points="' + line + '" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/>' + coords.map((p,i) => '<circle cx="' + p[0].toFixed(2) + '" cy="' + p[1].toFixed(2) + '" r="4" fill="currentColor"><title>' + esc(date(visible[i].date) + ': ' + num(values[i]) + ' ' + unit) + '</title></circle>').join('') + '</svg><div class="pds-chart-labels"><span>' + esc(date(visible[0].date)) + '</span><span>' + esc(date(visible[visible.length-1].date)) + '</span></div><details class="pds-chart-data"><summary>Ver valores dos intervalos</summary><table><thead><tr><th>Data</th><th>' + esc(unit) + '</th></tr></thead><tbody>' + rows + '</tbody></table></details>';
  }
  function recentRows(records) {
    return records.length ? records.map(r => '<div class="pds-record-row"><span class="pds-record-symbol">' + icon('fuel') + '</span><div class="pds-record-main"><strong>' + esc(fuelName(r.fuel_type)) + '</strong><small>' + esc(date(r.filled_on)) + ' · ' + esc(num(r.odometer,0)) + ' km</small></div><div class="pds-record-value"><strong>' + esc(money(r.paid)) + '</strong><small>' + esc(num(r.liters,3)) + ' L · ' + (r.full_tank ? 'Completo' : 'Parcial') + '</small></div></div>').join('') : emptyChart('Seu histórico começa no primeiro abastecimento.');
  }
  async function loadDashboard() {
    const id = state.id, generation = state.generation;
    if (!id) return;
    const data = await api(collectionPath(id) + '/report');
    if (id !== state.id || generation !== state.generation) return;
    $('#pds-dashboard-kpis').innerHTML = kpis(data.summary);
    $('#pds-savings').innerHTML = '<div>Economia com descontos<small>Diferença entre preço de tabela e valor pago' + (data.summary.surcharge ? ' · Acréscimos: ' + esc(money(data.summary.surcharge)) : '') + '</small></div><strong>' + esc(money(data.summary.discount)) + '</strong>';
    $('#pds-monthly-chart').innerHTML = barChart(data.monthly);
    $('#pds-cost-chart').innerHTML = lineChart(data.evolution, 'cost_per_km', 'R$/km');
    $('#pds-recent').innerHTML = recentRows(data.recent);
  }
  async function selectVehicle(id) {
    state.id = id; state.generation++; state.reportFilter = null; state.report = null; state.records.clear(); resetRecord();
    showError(fuelError, '');
    $('#pds-report-result').hidden = true; $('[data-action="export-report"]').disabled = true;
    const v = vehicle(); $('#pds-vehicle-badge').hidden = !v || v.is_owner;
    $('[data-action="manage-vehicle"]').disabled = !v || !v.is_owner;
    $('[data-action="manage-vehicle"]').title = v && !v.is_owner ? 'Somente o proprietário pode gerenciar o veículo.' : 'Editar ou compartilhar veículo';
    $('#pds-fuel-empty').hidden = !!id;
    $$('[data-fuel-view]').forEach(el => { el.hidden = true; });
    if (!id) { $('#pds-fuel-loading').hidden = true; return; }
    const generation = state.generation;
    $('#pds-fuel-loading').hidden = false;
    try { await loadDashboard(); if (generation === state.generation) setTab(state.tab); }
    finally { if (generation === state.generation) $('#pds-fuel-loading').hidden = true; }
  }
  async function loadVehicles(preferred) {
    const result = await api('fuel/vehicles'); state.vehicles = result.vehicles;
    const select = $('#pds-vehicle-select');
    select.innerHTML = state.vehicles.length ? state.vehicles.map(v => '<option value="' + v.id + '">' + esc(v.name + (v.plate ? ' · ' + v.plate : '') + (!v.is_owner ? ' (compartilhado)' : '')) + '</option>').join('') : '<option value="">Nenhum veículo cadastrado</option>';
    const id = state.vehicles.some(v => v.id === preferred) ? preferred : (state.vehicles[0]?.id || null);
    select.value = id || ''; await selectVehicle(id);
  }
  $('#pds-vehicle-select').addEventListener('change', e => run(() => selectVehicle(Number(e.target.value)), m => showError(fuelError,m)));
  function openVehicle(edit) {
    state.editingVehicle = edit ? state.id : null;
    const form = $('#pds-vehicle-form'); form.reset();
    showError($('.pds-form-error', form), ''); showError($('#pds-share-error'), '');
    $('#pds-share-form').reset(); $('#pds-share-list').innerHTML = '';
    $('#pds-vehicle-title').textContent = edit ? 'Gerenciar veículo' : 'Novo veículo';
    $('#pds-share-panel').hidden = !edit;
    if (edit) { const v = vehicle(); if (!v?.is_owner) return; form.elements.name.value = v.name; form.elements.plate.value = v.plate; run(loadShares,m => showError($('#pds-share-error'),m)); }
    vehicleDialog.showModal();
  }
  $$('[data-close-dialog]').forEach(b => b.addEventListener('click', () => b.closest('dialog').close()));
  $$('[data-action="new-vehicle"]').forEach(b => b.addEventListener('click', () => openVehicle(false)));
  $('[data-action="manage-vehicle"]').addEventListener('click', () => openVehicle(true));
  $('#pds-vehicle-form').addEventListener('submit', e => { e.preventDefault(); run(() => busy(e.target, async () => {
    const id = state.editingVehicle;
    const result = await api(id ? collectionPath(id) : 'fuel/vehicles', id ? 'PUT' : 'POST', Object.fromEntries(new FormData(e.target)));
    vehicleDialog.close(); toast(id ? 'Veículo atualizado.' : 'Veículo cadastrado.'); await loadVehicles(result.id);
  })); });
  async function loadShares() {
    const id = state.editingVehicle; if (!id) return;
    const data = await api(collectionPath(id) + '/shares');
    if (id !== state.editingVehicle) return;
    $('#pds-share-list').innerHTML = data.shares.length ? data.shares.map(s => '<div class="pds-share-person"><div><strong>' + esc(s.name) + '</strong><small>' + esc(s.email) + '</small>' + (s.status === 'pendente' ? '<small>Aguardando confirmação (legado)</small>' : '') + '</div><button type="button" class="pds-text-button pds-danger" data-remove-share="' + Number(s.user_id) + '">Remover</button></div>').join('') : '<p class="pds-muted">Este veículo ainda não foi compartilhado.</p>';
  }
  $('#pds-share-form').addEventListener('submit', e => { e.preventDefault(); run(() => busy(e.target, async () => {
    showError($('#pds-share-error'), ''); await api(collectionPath(state.editingVehicle) + '/shares', 'POST', Object.fromEntries(new FormData(e.target))); e.target.reset(); await loadShares(); toast('Veículo compartilhado.');
  }), m => showError($('#pds-share-error'), m)); });
  $('#pds-share-list').addEventListener('click', e => { const button = e.target.closest('[data-remove-share]'); if (!button) return; button.disabled = true;
    run(async () => { await api(collectionPath(state.editingVehicle) + '/shares/' + Number(button.dataset.removeShare), 'DELETE'); await loadShares(); toast('Acesso removido.'); }, m => { button.disabled = false; showError($('#pds-share-error'),m); });
  });
  async function confirmDelete(message) {
    const dialog = $('#pds-confirm-dialog'); if (dialog.open) return false;
    $('#pds-confirm-message').textContent = message;
    return new Promise(resolve => {
      const close = () => { dialog.removeEventListener('close', close); resolve(dialog.returnValue === 'yes'); };
      dialog.returnValue = 'no'; dialog.addEventListener('close', close); dialog.showModal();
      $('[data-confirm="no"]',dialog).focus();
    });
  }
  $$('[data-confirm]').forEach(b => b.addEventListener('click', () => b.closest('dialog').close(b.dataset.confirm)));
  $('[data-action="delete-vehicle"]').addEventListener('click', () => run(async () => {
    const id = state.editingVehicle, v = state.vehicles.find(v => v.id === id);
    if (!v || !await confirmDelete('Excluir “' + v.name + '” e todos os seus abastecimentos? Esta ação não pode ser desfeita.')) return;
    await api(collectionPath(id), 'DELETE'); vehicleDialog.close(); toast('Veículo excluído.'); await loadVehicles();
  }, m => showError($('#pds-share-error'),m)));
  function preview() {
    const price = Number(recordForm.elements.price.value), liters = Number(recordForm.elements.liters.value), paid = Number(recordForm.elements.paid.value);
    if (!(price > 0 && liters > 0 && paid > 0)) { $('#pds-fill-preview').innerHTML = '<p class="pds-muted">Informe preço, quantidade e valor pago para conferir os descontos.</p>'; return; }
    const table = Math.round((price*liters + Number.EPSILON)*100)/100;
    const difference = Math.round((table-paid)*100)/100;
    const rows = [['Valor de tabela',money(table)],[difference >= 0 ? 'Desconto total' : 'Acréscimo total',money(Math.abs(difference))],['Desconto por litro',money(Math.max(0,difference)/liters)],['Preço efetivo / litro',money(paid/liters)]];
    $('#pds-fill-preview').innerHTML = rows.map(r => '<div class="pds-preview-row"><span>' + esc(r[0]) + '</span><strong>' + esc(r[1]) + '</strong></div>').join('') + '<div class="pds-preview-row pds-preview-total"><span>Você pagou</span><strong>' + esc(money(paid)) + '</strong></div>';
  }
  recordForm.addEventListener('input', preview);
  recordForm.addEventListener('submit', e => { e.preventDefault(); run(() => busy(recordForm, async () => {
    if (!state.id) throw new Error('Cadastre ou selecione um veículo primeiro.');
    const data = Object.fromEntries(new FormData(recordForm)); data.full_tank = recordForm.elements.full_tank.checked;
    const id = state.id, editing = state.editingRecord;
    await api(editing ? 'fuel/records/' + editing : collectionPath(id) + '/records', editing ? 'PUT' : 'POST', data);
    resetRecord(); toast(editing ? 'Abastecimento atualizado.' : 'Abastecimento registrado.');
    await loadDashboard(); setTab('dashboard'); $('#pds-fuel').scrollIntoView({block:'start'});
  })); });
  let historyRequest = 0;
  async function loadHistory(page) {
    const id = state.id, requestNumber = ++historyRequest;
    if (!id) return;
    $('#pds-history').innerHTML = '<div class="pds-loading">Carregando histórico...</div>'; $('#pds-pagination').innerHTML = '';
    const data = await api(collectionPath(id) + '/records?page=' + page);
    if (id !== state.id || requestNumber !== historyRequest) return;
    state.page = data.page; state.historyPages = data.pages; state.records = new Map(data.records.map(r => [r.id,r]));
    $('#pds-history').innerHTML = data.records.length ? data.records.map(r => {
      const fields = [['Quilometragem',num(r.odometer,0) + ' km'],['Distância',r.distance > 0 ? num(r.distance,0) + ' km' : 'Referência inicial'],['Quantidade',num(r.liters,3) + ' L'],['Valor pago',money(r.paid)],['Preço de tabela / L',money(r.price)],['Preço efetivo / L',money(r.effective_price)],['Consumo',r.consumption === null ? '—' : num(r.consumption) + ' km/L'],['Desconto',money(r.discount)]];
      return '<article class="pds-card pds-history-card"><div class="pds-history-top"><div><h3>' + esc(date(r.filled_on)) + ' · ' + esc(fuelName(r.fuel_type)) + '</h3><div class="pds-history-meta"><span class="pds-tag">' + (r.full_tank ? 'Tanque completo' : 'Abastecimento parcial') + '</span></div></div>' + (r.can_modify ? '<div class="pds-history-actions"><button type="button" class="pds-text-button" data-edit-record="' + r.id + '">Editar</button><button type="button" class="pds-text-button pds-danger" data-delete-record="' + r.id + '">Excluir</button></div>' : '') + '</div><div class="pds-history-grid">' + fields.map(f => '<div>' + esc(f[0]) + '<strong>' + esc(f[1]) + '</strong></div>').join('') + '</div>' + (r.notes ? '<p class="pds-record-note">' + esc(r.notes) + '</p>' : '') + '</article>';
    }).join('') : emptyChart('Nenhum abastecimento registrado para este veículo.');
    if (data.pages > 1) $('#pds-pagination').innerHTML = '<button type="button" class="pds-button pds-button-secondary pds-button-small" data-page="' + (page-1) + '" ' + (page <= 1 ? 'disabled' : '') + '>Anterior</button><span>Página ' + page + ' de ' + data.pages + '</span><button type="button" class="pds-button pds-button-secondary pds-button-small" data-page="' + (page+1) + '" ' + (page >= data.pages ? 'disabled' : '') + '>Próxima</button>';
  }
  $('#pds-pagination').addEventListener('click', e => { const b = e.target.closest('[data-page]'); if (b && !b.disabled) run(() => loadHistory(Number(b.dataset.page)),m => showError(fuelError,m)); });
  $('#pds-history').addEventListener('click', e => {
    const edit = e.target.closest('[data-edit-record]'), remove = e.target.closest('[data-delete-record]');
    if (edit) {
      const r = state.records.get(Number(edit.dataset.editRecord)); if (!r) return;
      state.editingRecord = r.id;
      ['filled_on','odometer','fuel_type','price','liters','paid','notes'].forEach(k => { recordForm.elements[k].value = r[k]; });
      recordForm.elements.full_tank.checked = r.full_tank; document.dispatchEvent(new CustomEvent('pds:fuel-edit',{detail:r}));
      $('#pds-record-title').textContent = 'Editar abastecimento'; $('#pds-cancel-edit').hidden = false;
      $('button[type="submit"]',recordForm).textContent = 'Salvar alterações'; showError($('.pds-form-error',recordForm),''); preview(); setTab('new');
      $('#pds-record-title').scrollIntoView({block:'center'});
    }
    if (remove) run(async () => {
      const id = Number(remove.dataset.deleteRecord), vId = state.id;
      if (!await confirmDelete('Excluir este abastecimento? Os indicadores serão recalculados.')) return;
      remove.disabled = true;
      try { await api('fuel/records/' + id,'DELETE'); toast('Abastecimento excluído.'); if (state.id === vId) { await loadHistory(1); await loadDashboard(); } }
      finally { remove.disabled = false; }
    },m => showError(fuelError,m));
  });
  const reportForm = $('#pds-report-form');
  reportForm.elements.from.value = PDS.today.slice(0,8) + '01'; reportForm.elements.to.value = PDS.today;
  reportForm.addEventListener('submit', e => { e.preventDefault(); run(() => busy(reportForm, async () => {
    const filter = Object.fromEntries(new FormData(reportForm)); if (filter.from > filter.to) throw new Error('A data inicial deve ser anterior à data final.');
    if (!state.id) throw new Error('Cadastre um veículo primeiro.');
    const id = state.id, generation = state.generation;
    $('#pds-report-result').hidden = true; $('[data-action="export-report"]').disabled = true;
    const data = await api(collectionPath(id) + '/report?' + new URLSearchParams(filter));
    if (id !== state.id || generation !== state.generation) return;
    state.reportFilter = filter; state.report = data;
    $('#pds-report-caption').textContent = date(filter.from) + ' a ' + date(filter.to) + ' · ' + data.summary.count + ' abastecimento(s)';
    $('#pds-report-kpis').innerHTML = kpis(data.summary,true);
    $('#pds-report-chart').innerHTML = barChart(data.daily,'day');
    $('#pds-consumption-chart').innerHTML = lineChart(data.evolution,'consumption','km/L');
    $('#pds-report-result').hidden = false; $('[data-action="export-report"]').disabled = !data.summary.count;
  })); });
  function csvCell(value) {
    let text = String(value ?? '');
    if (/^[\s]*[=+\-@\t\r]/.test(text)) text = "'" + text;
    return '"' + text.replace(/"/g,'""') + '"';
  }
  async function exportCsv(filtered, button) {
    if (state.exporting || !state.id) return;
    const id = state.id, name = vehicle().name, filter = filtered ? state.reportFilter : null;
    if (filtered && !filter) return;
    state.exporting = true; button.disabled = true;
    try {
      const records = []; let page = 1, pages = 1;
      do {
        const params = new URLSearchParams({...filter, page, per_page:100});
        const data = await api(collectionPath(id) + '/records?' + params); records.push(...data.records); pages = data.pages; page++;
      } while (page <= pages);
      if (!records.length) { toast('Não há abastecimentos para exportar.'); return; }
      const headings = ['ID','Veículo','Data','Quilometragem (km)','Combustível','Preço tabela/L (R$)','Litros','Valor pago (R$)','Desconto (R$)','Consumo (km/L)','Custo/km (R$)','Tanque completo','Observações'];
      const decimal = v => v === null ? '' : Number(v).toFixed(3).replace('.',',');
      const rows = records.map(r => [r.id,name,date(r.filled_on),decimal(r.odometer),fuelName(r.fuel_type),decimal(r.price),decimal(r.liters),decimal(r.paid),decimal(r.discount),decimal(r.consumption),decimal(r.cost_per_km),r.full_tank?'Sim':'Não',r.notes]);
      const blob = new Blob(['\ufeff' + [headings,...rows].map(row => row.map(csvCell).join(';')).join('\r\n')], {type:'text/csv;charset=utf-8;'});
      const url = URL.createObjectURL(blob), anchor = document.createElement('a'); anchor.href = url; anchor.download = 'abastecimentos-' + id + (filter ? '-' + filter.from + '-' + filter.to : '') + '.csv'; anchor.click(); setTimeout(() => URL.revokeObjectURL(url),1000);
      toast('CSV exportado.');
    } finally { state.exporting = false; button.disabled = false; }
  }
  $$('[data-action="export"],[data-action="export-report"]').forEach(b => b.addEventListener('click', () => run(() => exportCsv(b.dataset.action === 'export-report',b),m => showError(fuelError,m))));
  resetRecord();
  run(() => loadVehicles(),m => { $('#pds-fuel-loading').hidden = true; showError(fuelError,m); });
})();
