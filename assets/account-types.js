(() => {
  'use strict';
  const F=window.PDSFinance,E=window.PDSExpenses,$=id=>document.getElementById(id);
  if(!F||!E||!$('pds-account-type-form'))return;
  const {esc,button,badge}=F,form=$('pds-account-type-form'),dialog=$('pds-account-type-dialog');let editing=0;
  const error=m=>{const el=$('pds-expense-error');el.textContent=m;el.hidden=!m;};
  const action=fn=>Promise.resolve().then(fn).catch(e=>error(e.message));
  function render(){const rows=F.state.accountTypes;$('pds-account-types-list').innerHTML=rows.length?rows.map(t=>'<article class="pds-card pds-account-type-item"><div class="pds-finance-heading"><h3>'+esc(t.name)+'</h3>'+badge(t.active?'Ativo':'Arquivado')+'</div><p class="pds-muted">'+t.account_count+' conta(s) vinculada(s)</p><div class="pds-finance-actions">'+button('Editar nome','data-account-type-edit="'+t.id+'"')+button(t.active?'Arquivar':'Reativar','data-account-type-toggle="'+t.id+'"')+'</div></article>').join(''):F.empty('Cadastre seu primeiro tipo de conta.');}
  function open(id=0){const row=F.state.accountTypes.find(t=>t.id===id);if(id&&!row)return;editing=id;form.reset();form.querySelector('.pds-form-error').hidden=true;form.elements.name.value=row?.name||'';$('pds-account-type-title').textContent=id?'Editar tipo de conta':'Cadastrar tipo de conta';dialog.showModal();}
  form.addEventListener('submit',e=>{e.preventDefault();F.busy(form,async()=>{await F.api('finance/account-types'+(editing?'/'+editing:''),editing?'PUT':'POST',Object.fromEntries(new FormData(form)));dialog.close();F.toast('Tipo de conta salvo.');await F.refresh();});});
  $('pds-expenses').addEventListener('click',event=>{
    const b=event.target.closest('button');if(!b)return;
    if(b.hasAttribute('data-account-types-manage'))E.tab('account-types');
    if(b.hasAttribute('data-account-type-new'))open();
    if(b.hasAttribute('data-account-type-close'))dialog.close();
    if(b.dataset.accountTypeEdit)open(Number(b.dataset.accountTypeEdit));
    if(b.dataset.accountTypeToggle)action(async()=>{const row=F.state.accountTypes.find(t=>t.id===Number(b.dataset.accountTypeToggle));if(!row)return;if(row.active&&!await E.confirm('Arquivar o tipo '+row.name+'? As contas e o histórico que já usam esse tipo serão preservados.'))return;await F.api('finance/account-types/'+row.id,row.active?'DELETE':'PUT',row.active?null:{active:true});F.toast(row.active?'Tipo arquivado.':'Tipo reativado.');await F.refresh();});
  });
  document.addEventListener('pds:expenses-ready',render);
  document.addEventListener('pds:finance-tab',e=>{if(e.detail==='account-types')render();});
  if(E.state.ready)render();
})();
