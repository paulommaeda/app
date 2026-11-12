/* Forecasts and settlements share the existing private WordPress financial service. */
(() => {
  'use strict';
  const E=window.PDSExpenses,F=window.PDSFinance,root=document.getElementById('pds-expenses');if(!root||!E||!F)return;
  const $=id=>document.getElementById(id),{api,esc,money,date,month,input,select,pair,option,button,badge,note,choices,localized}=F;
  const state={items:[],recurring:[],editing:null,generation:0};
  const labels={pending:'Pendente',paid:'Pago',overdue:'Vencida',partial:'Pagamento parcial',empty:'Sem saldo a pagar',canceled:'Cancelada'};
  const error=m=>{const el=$('pds-planning-error');el.textContent=m||'';el.hidden=!m;};
  const action=fn=>Promise.resolve().then(fn).catch(e=>error(e.message));
  const catChoices=(id=0)=>option('','Escolha a categoria')+E.state.categories.filter(c=>c.active).map(c=>option(c.id,c.name,c.id===Number(id))).join('');
  const defaultCategory=()=>E.state.categories.find(c=>c.active&&c.name==='Contas e serviços')?.id||E.state.categories.find(c=>c.active&&c.name==='Outros')?.id||0;
  function recurrenceMarkup(dateValue){return '<div class="pds-form pds-plan-recurrence">'+select('recurrence','Repetição',option('once','Uma única vez')+option('monthly','Todo mês'))+'<div data-plan-monthly class="pds-form" hidden>'+pair(input('billing_day','Dia dos próximos vencimentos','number','min="1" max="31" value="'+Number((dateValue||PDS.today).slice(8))+'"'),input('occurrences','Quantidade de meses (opcional)','number','min="1" max="120" placeholder="Sem término definido"'))+note('A primeira data é a informada acima. Dias que não existem no mês usam o último dia. Sem quantidade, mantemos previsões para os próximos 12 meses e continuamos gerando mensalmente. Todas ficam pendentes até a baixa manual.')+'</div></div>';}
  function wireRecurrence(form,container,dateName,gate=null){
    form._pdsRecurringAbort?.abort();const abort=new AbortController();form._pdsRecurringAbort=abort;let dayEdited=false;
    const update=()=>{const allowed=!gate||form.elements[gate].value==='pending',monthly=allowed&&form.elements.recurrence.value==='monthly';container.hidden=!allowed;container.querySelector('[data-plan-monthly]').hidden=!monthly;form.elements.recurrence.disabled=!allowed;for(const key of ['billing_day','occurrences'])form.elements[key].disabled=!monthly;};
    form.addEventListener('change',e=>{if(e.target.name==='billing_day')dayEdited=true;if(e.target.name===dateName&&!dayEdited)form.elements.billing_day.value=Number(form.elements[dateName].value.slice(8))||1;if(['recurrence',gate,dateName].includes(e.target.name))update();},{signal:abort.signal});update();
  }
  document.addEventListener('pds:finance-form',event=>{
    const {kind,id,row}=event.detail;if(kind!=='receipt')return;const form=$('pds-finance-form');form._pdsRecurringAbort?.abort();
    if(id){if(row?.recurring_id)$('pds-finance-fields').insertAdjacentHTML('beforeend',note('Esta é uma ocorrência mensal. A edição vale só para este lançamento; a baixa continua manual. Gerencie a série em Contas a pagar → Gerenciar recorrências.'));return;}
    const div=document.createElement('div');div.innerHTML=recurrenceMarkup(form.elements.received_on.value);$('pds-finance-fields').append(div);wireRecurrence(form,div,'received_on','status');
  });
  function cards(rows){return rows.length?rows.map(row=>{
    const invoice=row.kind==='invoice',value=invoice?row.outstanding:(row.status==='paid'?row.actual_amount:row.amount);
    const status=labels[row.state]||row.state;
    let actions='';
    if(invoice)actions=button('Conferir / pagar fatura','data-plan-invoice="'+row.id+'"');
    else if(row.status==='pending')actions=button('Dar baixa','data-plan-pay="'+row.id+'"',false)+button('Editar previsão','data-plan-edit="'+row.id+'"')+button('Cancelar','data-plan-cancel="'+row.id+'"');
    else actions=button(row.status==='paid'?'Desfazer baixa':'Reabrir previsão','data-plan-reopen="'+row.id+'"')+(row.status==='canceled'?button('Editar previsão','data-plan-edit="'+row.id+'"'):'');
    return '<article class="pds-card pds-plan-item"><div class="pds-finance-heading"><h3>'+esc(row.description)+'</h3>'+badge(status,row.state==='overdue'?'pds-finance-overdue':'')+'</div><span class="pds-muted">'+(invoice?'Em aberto':row.status==='paid'?'Valor realizado':'Valor previsto')+'</span><strong class="pds-finance-number">'+(value===null?'A definir':esc(money(value)))+'</strong><p class="pds-muted">Vence '+esc(date(row.due_on))+(row.recurring_id?' · Recorrente mensal':'')+(row.source==='telegram'?' · Telegram':'')+'</p>'+(invoice?note('Total da fatura: '+money(row.amount)+' · Pago: '+money(row.paid_amount)+'. O valor acompanha as compras e parcelas do cartão.'):(row.status==='paid'?note('Pago em '+date(row.paid_on)+'. Previsto originalmente: '+(row.amount===null?'a definir':money(row.amount))):''))+'<div class="pds-finance-actions">'+actions+'</div></article>';
  }).join(''):F.empty('Nenhuma conta neste mês. Cadastre uma conta única ou uma recorrência mensal. As faturas dos cartões ativos aparecem aqui.');}
  function recurringCards(){return state.recurring.length?state.recurring.map(s=>'<article class="pds-plan-series"><div><strong>'+esc(s.description)+'</strong><small>'+(s.kind==='receipt'?'Recebimento a prever':'Conta a pagar')+' · Mensal, dia '+s.due_day+' · '+(s.amount===null?'Valor a definir':esc(money(s.amount)))+'</small><small>Início '+esc(date(s.first_due))+' · '+(s.end_month?'Até '+esc(month(s.end_month)):'Sem término definido')+' · '+(s.active?'Ativa':'Encerrada')+'</small></div><div class="pds-finance-actions">'+(s.active?button('Editar previsões','data-plan-series-edit="'+s.id+'"')+button('Encerrar recorrência','data-plan-series-end="'+s.id+'"'):'')+'</div></article>').join(''):note('Nenhuma recorrência cadastrada. Ao cadastrar uma conta ou um recebimento previsto, escolha Todo mês.');}
  async function load(){const generation=++state.generation;error('');$('pds-planning-loading').hidden=false;try{
    const [data,series]=await Promise.all([api('finance/payables?'+new URLSearchParams(new FormData($('pds-payables-filter')))),api('finance/recurring')]);if(generation!==state.generation)return;
    state.items=data.items;state.recurring=series.recurring;
    $('pds-payables-summary').innerHTML='<div class="pds-finance-grid pds-plan-summary">'+[['A pagar no mês',money(data.pending_total)],['Pagamentos registrados',money(data.paid_total)],['Contas sem valor definido',data.unknown_count]].map(([label,value])=>'<article class="pds-card"><span class="pds-muted">'+esc(label)+'</span><strong class="pds-finance-number">'+esc(value)+'</strong></article>').join('')+'</div>';
    $('pds-payables-list').innerHTML=cards(data.items);$('pds-recurring-list').innerHTML=recurringCards();if(data.warnings.length)error(data.warnings.join(' '));
  }finally{if(generation===state.generation)$('pds-planning-loading').hidden=true;}}
  document.addEventListener('pds:finance-tab',e=>{if(e.detail==='payables')action(load);});
  let firstReady=true;document.addEventListener('pds:expenses-ready',()=>{if(firstReady){firstReady=false;if(new URL(location.href).searchParams.get('fin_tab')==='payables')E.tab('payables');}});
  if(E.state.ready&&firstReady){firstReady=false;if(new URL(location.href).searchParams.get('fin_tab')==='payables')E.tab('payables');}
  $('pds-payables-filter').addEventListener('submit',e=>{e.preventDefault();action(load);});
  function open(kind,row=null){state.editing={kind,row};const form=$('pds-planning-form');form._pdsRecurringAbort?.abort();let fields='',title='';
    if(kind==='create'||kind==='edit'){
      title=kind==='create'?'Cadastrar conta a pagar':'Editar previsão';fields=input('description','Descrição da conta','text','required maxlength="180" placeholder="Ex.: conta de luz, telefone, aluguel"')+pair(input('amount','Valor previsto (R$, opcional)','text','inputmode="decimal" placeholder="A definir"'),input('due_on','Primeiro vencimento','date','required value="'+PDS.today+'"'))+select('category_id','Categoria',catChoices(row?.category_id||defaultCategory()),'required')+select('account_id','Conta prevista para pagamento (opcional)',choices(E.state.accounts,row?.account_id,'Escolher na baixa'))+input('notes','Observações (opcional)','text','maxlength="500"')+(kind==='create'?recurrenceMarkup(PDS.today):note('A alteração vale somente para esta previsão. Gerencie as próximas ocorrências pelo painel de recorrências.'))+note('Cadastrar a previsão não cria uma despesa e não altera seu saldo. Informe o valor real e a data quando efetuar o pagamento.');
    }
    if(kind==='pay'){
      title='Dar baixa na conta';fields=note(row.description+' · Vencimento '+date(row.due_on))+pair(input('amount','Valor efetivamente pago (R$)','text','required inputmode="decimal" value="'+(row.amount===null?'':esc(localized(row.amount)))+'"'),input('paid_on','Data do pagamento realizado','date','required max="'+PDS.today+'" value="'+PDS.today+'"'))+select('payment_method','Forma de pagamento',option('pix','Pix')+option('debit','Débito')+option('cash','Dinheiro')+option('transfer','Transferência')+option('credit','Crédito à vista'))+'<div data-plan-bank>'+select('account_id','Conta de saída',choices(E.state.accounts,row.account_id,'Escolha a conta'),'required')+'</div><div data-plan-card hidden>'+select('card_id','Cartão de crédito',choices(E.state.cards,0,'Escolha o cartão'))+'</div>'+select('category_id','Categoria do gasto',catChoices(row.category_id),'required')+note('A baixa gera uma única despesa. No crédito, a despesa entra na fatura e o saldo bancário muda quando a fatura for paga.');
    }
    if(kind==='series'){
      title='Editar recorrência';fields=input('description','Descrição','text','required maxlength="180"')+pair(input('amount',row.kind==='receipt'?'Valor previsto (R$)':'Valor previsto (R$, opcional)','text','inputmode="decimal" '+(row.kind==='receipt'?'required':'')),input('billing_day','Dia dos próximos vencimentos','number','required min="1" max="31" value="'+row.due_day+'"'))+select('account_id',row.kind==='receipt'?'Conta de destino':'Conta prevista',choices(E.state.accounts,row.account_id,row.kind==='receipt'?'Escolha a conta':'Escolher na baixa'),row.kind==='receipt'?'required':'')+(row.kind==='payable'?select('category_id','Categoria',catChoices(row.category_id),'required'):'')+input('notes','Observações (opcional)','text','maxlength="500"')+note('A alteração vale para previsões pendentes deste mês em diante e para as novas gerações. Pagamentos e recebimentos realizados, cancelamentos e meses anteriores são preservados.');
    }
    $('pds-planning-title').textContent=title;$('pds-planning-fields').innerHTML=fields;form.querySelector('.pds-form-error').hidden=true;
    if(row&&kind!=='pay')for(const[key,value]of Object.entries(row)){if(form.elements[key])form.elements[key].value=key==='amount'?(value===null?'':localized(value)):String(value??'');}
    if(kind==='create')wireRecurrence(form,$('pds-planning-fields').querySelector('.pds-plan-recurrence'),'due_on');
    if(kind==='pay'){const toggle=()=>{const credit=form.elements.payment_method.value==='credit';form.querySelector('[data-plan-bank]').hidden=credit;form.querySelector('[data-plan-card]').hidden=!credit;form.elements.account_id.disabled=credit;form.elements.account_id.required=!credit;form.elements.card_id.disabled=!credit;form.elements.card_id.required=credit;};form.elements.payment_method.addEventListener('change',toggle);toggle();}
    $('pds-planning-dialog').showModal();
  }
  $('pds-planning-form').addEventListener('submit',event=>{event.preventDefault();F.busy(event.currentTarget,async()=>{const {kind,row}=state.editing,data=Object.fromEntries(new FormData(event.currentTarget));const path=kind==='series'?'finance/recurring/'+row.id:kind==='pay'?'finance/payables/'+row.id+'/pay':'finance/payables'+(kind==='edit'?'/'+row.id:'');await api(path,kind==='series'||kind==='edit'?'PUT':'POST',data);$('pds-planning-dialog').close();F.toast(kind==='pay'?'Pagamento registrado.':'Previsão salva.');await F.refresh();});});
  root.addEventListener('click',event=>{const b=event.target.closest('button');if(!b)return;
    if(b.hasAttribute('data-plan-close'))b.closest('dialog').close();if(b.hasAttribute('data-plan-new'))open('create');
    if(b.hasAttribute('data-plan-receipt')){F.openForm('receipt');const form=$('pds-finance-form');form.elements.status.value='pending';form.elements.status.dispatchEvent(new Event('change',{bubbles:true}));}
    if(b.dataset.planInvoice)action(()=>F.openInvoice(Number(b.dataset.planInvoice)));
    if(b.dataset.planPay||b.dataset.planEdit){const id=Number(b.dataset.planPay||b.dataset.planEdit),row=state.items.find(i=>i.kind==='payable'&&i.id===id);if(row)open(b.dataset.planPay?'pay':'edit',row);}
    if(b.dataset.planSeriesEdit){const row=state.recurring.find(s=>s.id===Number(b.dataset.planSeriesEdit));if(row)open('series',row);}
    if(b.dataset.planCancel)action(async()=>{if(await E.confirm('Cancelar somente esta conta prevista? Nenhum pagamento será registrado.')){await api('finance/payables/'+b.dataset.planCancel,'DELETE');await F.refresh();}});
    if(b.dataset.planReopen)action(async()=>{if(await E.confirm('Reabrir esta conta? Se houver uma baixa, a despesa e seu efeito no saldo serão desfeitos.')){await api('finance/payables/'+b.dataset.planReopen+'/reopen','POST',{});await F.refresh();}});
    if(b.dataset.planSeriesEnd)action(async()=>{if(await E.confirm('Encerrar esta recorrência e cancelar as previsões pendentes deste mês em diante? Movimentos realizados e meses anteriores serão preservados.')){await api('finance/recurring/'+b.dataset.planSeriesEnd,'DELETE');await F.refresh();}});
  });
})();
