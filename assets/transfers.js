/* Private internal transfers: one record, two account balances, no expense/income. */
(() => {
  'use strict';
  const F=window.PDSFinance,E=window.PDSExpenses,$=id=>document.getElementById(id);
  if(!F||!E||!$('pds-transfer-form'))return;
  const {api,esc,money,date,button,badge,empty,choices}=F;
  const root=$('pds-expenses'),form=$('pds-transfer-form'),dialog=$('pds-transfer-dialog'),filter=$('pds-transfers-filter');
  const state={tab:'dashboard',page:1,rows:[],generation:0,request:null};
  function error(message){const target=$('pds-expense-error');target.textContent=message;target.hidden=!message;}
  const action=fn=>Promise.resolve().then(fn).catch(e=>error(e.message));
  function sync(){const selected=filter.elements.account_id.value;filter.elements.account_id.innerHTML=choices(F.state.accounts,selected,'Todas as contas',true);if(state.tab==='transfers')action(()=>load());}
  function selection(){
    const from=form.elements.from_account_id.value,to=form.elements.to_account_id;
    for(const option of to.options)option.disabled=!!from&&option.value===from;
    if(to.value===from)to.value='';
    const a=F.state.accounts.find(a=>String(a.id)===from),b=F.state.accounts.find(a=>String(a.id)===to.value);
    $('pds-transfer-balances').textContent=[a?'Origem · '+a.name+': '+money(a.balance):'',b?'Destino · '+b.name+': '+money(b.balance):''].filter(Boolean).join(' | ');
    form.elements.transferred_on.min=[a?.opening_on,b?.opening_on].filter(Boolean).sort().pop()||'';
  }
  function open(from=0){
    error('');
    if(F.state.accounts.filter(a=>a.active).length<2){E.tab('accounts');F.toast('Cadastre pelo menos duas contas ativas em Contas para registrar uma transferência.');return;}
    form.reset();form.querySelector('.pds-form-error').hidden=true;state.request=null;
    form.elements.from_account_id.innerHTML=choices(F.state.accounts,0,'Escolha a origem');
    form.elements.to_account_id.innerHTML=choices(F.state.accounts,0,'Escolha o destino');
    form.elements.transferred_on.value=PDS.today;
    if(from&&F.state.accounts.some(a=>a.id===from&&a.active))form.elements.from_account_id.value=String(from);
    selection();dialog.showModal();
  }
  async function load(){
    const generation=++state.generation;const query=new URLSearchParams(new FormData(filter));query.set('page',String(state.page));
    $('pds-transfers-loading').hidden=false;$('pds-transfers-list').setAttribute('aria-busy','true');
    try{
      const data=await api('finance/transfers?'+query);if(generation!==state.generation)return;
      if(data.page>data.pages){state.page=data.pages;await load();return;}
      state.rows=data.transfers;
      $('pds-transfers-count').textContent=data.total+' transferência(s) no período';
      $('pds-transfers-list').innerHTML=data.transfers.length?data.transfers.map(t=>'<article class="pds-card pds-transfer-item"><div class="pds-transfer-route"><strong>'+esc(t.from_account_name)+'</strong><span>para</span><strong>'+esc(t.to_account_name)+'</strong><small>'+esc(date(t.transferred_on))+'</small>'+(t.notes?'<small>'+esc(t.notes)+'</small>':'')+'</div><div class="pds-transfer-value"><strong>'+esc(money(t.amount))+'</strong>'+badge(t.status==='reversed'?'Estornada':'Registrada')+'</div><div class="pds-finance-actions">'+(t.status==='completed'?button('Estornar registro','data-transfer-reverse="'+t.id+'"'):'')+'</div></article>').join(''):empty('Nenhuma transferência neste período.');
      $('pds-transfers-pagination').innerHTML=data.pages>1?button('Anterior','data-transfer-page="'+(data.page-1)+'" '+(data.page<=1?'disabled':''))+'<span>Página '+data.page+' de '+data.pages+'</span>'+button('Próxima','data-transfer-page="'+(data.page+1)+'" '+(data.page>=data.pages?'disabled':'')):'';
    }finally{if(generation===state.generation){$('pds-transfers-loading').hidden=true;$('pds-transfers-list').setAttribute('aria-busy','false');}}
  }
  form.addEventListener('change',event=>{if(['from_account_id','to_account_id'].includes(event.target.name))selection();});
  form.addEventListener('submit',event=>{
    event.preventDefault();F.busy(form,async()=>{
      const data=Object.fromEntries(new FormData(form));
      if(data.from_account_id===data.to_account_id)throw new Error('Escolha contas de origem e destino diferentes.');
      const fingerprint=JSON.stringify(data);
      // Keep the same key after a network timeout, even if the server already committed.
      if(!state.request||state.request.fingerprint!==fingerprint){const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);state.request={fingerprint,key:Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('')};}
      const result=await api('finance/transfers','POST',{...data,request_key:state.request.key});
      dialog.close();F.toast(result.status==='reversed'?'Este registro já foi estornado.':'Transferência registrada.');
      state.page=1;await F.refresh();if(state.tab==='transfers')await load();
    });
  });
  filter.addEventListener('submit',event=>{event.preventDefault();state.page=1;error('');action(()=>load());});
  root.addEventListener('click',event=>{
    const b=event.target.closest('button');if(!b)return;
    if(b.hasAttribute('data-transfer-new'))open();
    if(b.hasAttribute('data-transfer-from'))open(Number(b.dataset.transferFrom));
    if(b.hasAttribute('data-transfer-close'))dialog.close();
    if(b.hasAttribute('data-transfer-page')){state.page=Number(b.dataset.transferPage);action(()=>load());}
    if(b.hasAttribute('data-transfer-reverse'))action(async()=>{
      const row=state.rows.find(t=>t.id===Number(b.dataset.transferReverse));if(!row)return;
      if(!await E.confirm('Estornar o registro de '+money(row.amount)+' de '+row.from_account_name+' para '+row.to_account_name+'? O efeito original nos dois saldos será desfeito e o histórico será mantido. Isso não movimenta dinheiro no banco.'))return;
      b.disabled=true;try{await api('finance/transfers/'+row.id+'/reverse','POST',{});F.toast('Registro estornado.');await F.refresh();await load();}finally{b.disabled=false;}
    });
  });
  document.addEventListener('pds:expenses-ready',sync);
  document.addEventListener('pds:finance-tab',event=>{state.tab=event.detail;if(state.tab==='transfers')action(()=>load());else state.generation++;});
  if(E.state.ready)sync();
})();
