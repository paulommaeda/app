/* One allocation editor for expenses, fuel, statement preview and existing invoice lines. */
(() => {
  'use strict';
  const F=window.PDSFinance||window.PDSPaymentFinanceBridge;if(!F)return;
  const $=id=>document.getElementById(id),{esc,money,option}=F;
  const cents=value=>{const s=String(value??'').trim();const n=Number(s.includes(',')?s.replace(/\./g,'').replace(',','.'):s);return Number.isFinite(n)?Math.round(n*100):0;};
  const decimal=value=>(value/100).toFixed(2).replace('.',',');
  function apportion(amount,weights){const sum=weights.reduce((a,b)=>a+BigInt(b),0n);if(sum<=0n)return weights.map(()=>0);const parts=weights.map((w,i)=>({i,q:Number(BigInt(amount)*BigInt(w)/sum),r:BigInt(amount)*BigInt(w)%sum}));let left=amount-parts.reduce((a,p)=>a+p.q,0);const order=[...parts].sort((a,b)=>a.r===b.r?a.i-b.i:a.r>b.r?-1:1);for(let i=0;i<left;i++)parts[order[i].i].q++;return parts.map(p=>p.q);}
  function mount(node,opts){
    node._allocation?.destroy();const abort=new AbortController(),initial=opts.initial||{mode:'none'},people=opts.people||[],oldIds=new Set((initial.people||[]).map(p=>Number(p.person_id)));if(initial.person_id)oldIds.add(Number(initial.person_id));
    let credit=opts.credit!==false,mode=initial.mode==='none'?'single':initial.mode,checked=initial.mode!=='none',owner=['none','single'].includes(initial.mode)?true:!!initial.include_owner;
    let participants=(initial.people||[]).map(p=>({id:p.person_id,amount:decimal(cents(p.amount))}));if(!participants.length)participants=[{id:initial.person_id||'',amount:''}];
    const choice=(id,first)=>option('',first)+people.filter(p=>p.active||oldIds.has(p.id)).map(p=>option(p.id,p.name+(!p.active?' (arquivada)':''),Number(id)===p.id)).join('');
    node.classList.add('pds-allocation-editor');
    node.innerHTML='<label class="pds-check"><input type="checkbox" name="is_shared_purchase" data-fin-share-toggle '+(checked?'checked':'')+'><span>Este gasto é de outra pessoa ou será dividido</span></label><input type="hidden" name="person_id" value="0" data-fin-person-none><input type="hidden" name="sharing" data-allocation-payload><div data-allocation-settings class="pds-form"><label>Como dividir?<select data-allocation-mode>'+option('single','Uma pessoa paga tudo',mode==='single')+option('equal','Dividir igualmente',mode==='equal')+option('amounts','Definir valores por pessoa',mode==='amounts')+'</select></label><div data-fin-person><label>Quem usou o cartão?<select name="person_id" data-allocation-single>'+choice(initial.person_id,'Escolha a pessoa')+'</select></label></div><div data-allocation-multi class="pds-form"><label class="pds-check"><input type="checkbox" data-allocation-owner '+(owner?'checked':'')+'><span>Eu também participo da divisão</span></label><div data-allocation-rows></div><button type="button" class="pds-button pds-button-secondary pds-button-small" data-allocation-add>Adicionar pessoa</button><p class="pds-finance-note" data-allocation-amount-help>Informe os valores sobre as parcelas a dividir. Com sua parte marcada, o restante fica com você.</p></div><label data-allocation-start-label>Aplicar a partir da parcela<input type="number" data-allocation-start min="'+opts.sourceStart+'" max="'+opts.count+'" value="'+opts.start+'" step="1" required></label><div data-allocation-summary class="pds-finance-note" aria-live="polite"></div><p class="pds-finance-note">A compra continua inteira na fatura. Só as partes das outras pessoas geram recebimentos previstos, nos mesmos vencimentos.</p></div>';
    const rows=node.querySelector('[data-allocation-rows]'),single=node.querySelector('[data-allocation-single]'),toggle=node.querySelector('[data-fin-share-toggle]'),ownerInput=node.querySelector('[data-allocation-owner]'),startInput=node.querySelector('[data-allocation-start]');
    function renderRows(){rows.innerHTML=participants.map((p,i)=>'<div class="pds-allocation-person" data-allocation-row><label>Pessoa '+(i+1)+'<select data-allocation-person>'+choice(p.id,'Escolha a pessoa')+'</select></label><label data-allocation-value-label>Valor (R$)<input type="text" inputmode="decimal" data-allocation-amount value="'+esc(p.amount)+'" placeholder="0,00"></label><button type="button" class="pds-text-button" data-allocation-remove="'+i+'" aria-label="Remover pessoa '+(i+1)+'">Remover</button></div>').join('');}
    function read(){
      const start=Number(startInput.value)||opts.sourceStart;if(!credit||!toggle.checked)return{mode:'none',start_installment:start};
      if(mode==='single')return{mode:'single',person_id:single.value,start_installment:start};
      return{mode,include_owner:ownerInput.checked,start_installment:start,people:[...rows.querySelectorAll('[data-allocation-row]')].map(row=>({person_id:row.querySelector('[data-allocation-person]').value,...(mode==='amounts'?{amount:row.querySelector('[data-allocation-amount]').value}:{})}))};
    }
    function update(){
      mode=node.querySelector('[data-allocation-mode]').value;checked=toggle.checked;const shared=credit&&checked,split=shared&&mode!=='single',custom=split&&mode==='amounts';node.hidden=!credit;node.querySelector('[data-allocation-settings]').hidden=!shared;node.querySelector('[data-fin-person]').hidden=!shared||mode!=='single';node.querySelector('[data-allocation-multi]').hidden=!split;
      single.disabled=!shared||mode!=='single';single.required=shared&&mode==='single';node.querySelector('[data-fin-person-none]').disabled=shared&&mode==='single';node.querySelector('[data-allocation-mode]').disabled=!shared||!opts.enabled;ownerInput.disabled=!split||!opts.enabled;
      toggle.disabled=!opts.enabled&&initial.mode==='none';startInput.disabled=!shared||!opts.enabled;startInput.max=String(opts.getCount?.()||opts.count);node.querySelector('[data-allocation-start-label]').hidden=Number(startInput.max)<=1;node.querySelector('[data-allocation-add]').disabled=!opts.enabled||participants.length>=20;node.querySelector('[data-allocation-amount-help]').hidden=!custom;
      rows.querySelectorAll('[data-allocation-row]').forEach(row=>{const select=row.querySelector('[data-allocation-person]'),amount=row.querySelector('[data-allocation-amount]');select.disabled=!split||!opts.enabled;select.required=split;amount.disabled=!custom;amount.required=custom;amount.readOnly=!opts.enabled;row.querySelector('[data-allocation-value-label]').hidden=!custom;row.querySelector('[data-allocation-remove]').disabled=!opts.enabled||participants.length<=1;});
      const data=read();node.querySelector('[data-allocation-payload]').value=JSON.stringify(data);const basis=Math.max(0,opts.getBasis(data.start_installment)),summary=node.querySelector('[data-allocation-summary]');let message='Valor das parcelas a dividir: '+money(basis/100)+'.';
      if(shared){const ids=mode==='single'?[single.value]:data.people.map(p=>p.person_id),names=ids.map(id=>people.find(p=>String(p.id)===String(id))?.name||'Escolha a pessoa');let values;
        if(mode==='amounts'){values=data.people.map(p=>cents(p.amount));const used=values.reduce((a,b)=>a+b,0);if(used>basis||(!data.include_owner&&used!==basis))message+=' Confira a soma dos valores.';if(data.include_owner)message+=' Sua parte: '+money(Math.max(0,basis-used)/100)+'.';}
        else{const hasOwner=mode!=='single'&&data.include_owner,order=ids.map((id,i)=>({id:Number(id),i})).sort((a,b)=>a.id-b.id),all=apportion(basis,Array(ids.length+(hasOwner?1:0)).fill(1));values=Array(ids.length).fill(0);order.forEach((p,i)=>values[p.i]=all[i+(hasOwner?1:0)]);if(hasOwner)message+=' Sua parte: '+money(all[0]/100)+'.';}
        summary.innerHTML='<p>'+esc(message)+'</p>'+names.map((name,i)=>'<p>'+esc(name)+' · '+esc(money((values[i]||0)/100))+' a receber</p>').join('');
      }else summary.textContent=message;
      opts.onChange?.(data);
    }
    renderRows();
    node.addEventListener('click',event=>{const b=event.target.closest('button');if(!b)return;if(b.hasAttribute('data-allocation-add')){participants=[...rows.querySelectorAll('[data-allocation-row]')].map(r=>({id:r.querySelector('select').value,amount:r.querySelector('input').value}));participants.push({id:'',amount:''});renderRows();update();}if(b.hasAttribute('data-allocation-remove')){participants=[...rows.querySelectorAll('[data-allocation-row]')].map(r=>({id:r.querySelector('select').value,amount:r.querySelector('input').value}));participants.splice(Number(b.dataset.allocationRemove),1);renderRows();update();}},{signal:abort.signal});
    node.addEventListener('input',update,{signal:abort.signal});node.addEventListener('change',update,{signal:abort.signal});
    if(opts.form)for(const kind of ['input','change'])opts.form.addEventListener(kind,event=>{if(!node.contains(event.target))update();},{signal:abort.signal});
    const api={read,update,setCredit(value){credit=value;update();},destroy(){abort.abort();}};node._allocation=api;update();return api;
  }
  function enhance(context){
    const{container,form,value,amountField}=context,node=container.querySelector('[data-fin-sharing]');if(!node)return;
    const saved=value?.sharing_context,sourceStart=saved?.source_start||value?.installment_start||1,count=()=>Number(form.elements.installments.value)||1;
    const basis=start=>{const amount=cents(form.elements[amountField].value),n=count(),remaining=n-sourceStart+1;if(saved?.schedule&&n===saved.installment_total&&amount===saved.schedule.reduce((a,p)=>a+cents(p.amount),0))return saved.schedule.filter(p=>p.installment_no>=start).reduce((a,p)=>a+cents(p.amount),0);if(remaining<1||start>n)return 0;const q=Math.floor(amount/remaining),r=amount%remaining;return q*(n-start+1)+Math.max(0,r-(start-sourceStart));};
    const initial=saved?.sharing||(value?.person_id?{mode:'single',person_id:value.person_id,people:[{person_id:value.person_id}],start_installment:sourceStart}:{mode:'none'});
    mount(node,{initial,people:F.state.people,enabled:F.state.sharedCardEnabled,sourceStart,count:count(),getCount:count,start:initial.start_installment||sourceStart,getBasis:basis,credit:form.elements.payment_method.value==='credit',form});
  }
  window.PDSAllocation={mount,enhance};document.addEventListener('pds:payment-editor',e=>enhance(e.detail));for(const context of F.paymentEditors?.values()||[])enhance(context);
  const E=window.PDSExpenses,dialog=$('pds-allocation-dialog'),form=$('pds-allocation-form');if(!E||!dialog||!form)return;let editor=null,save=null,savedLabel='Divisão salva.';
  const action=fn=>Promise.resolve().then(fn).catch(e=>{const el=$('pds-expense-error');el.textContent=e.message;el.hidden=false;});
  function show(context,onSave){
    save=onSave;savedLabel=context.isImport?'Divisão definida para a importação.':'Divisão salva.';form.querySelector('.pds-form-error').hidden=true;$('pds-allocation-description').textContent=context.description+' · Parcelas '+context.start_installment+'/'+context.installment_total+' em diante'+(context.isImport?' · Será aplicada ao confirmar a importação.':'');
    const initial=context.sharing?.mode&&context.sharing.mode!=='none'?context.sharing:{mode:'equal',include_owner:true,people:[],start_installment:context.start_installment};
    editor=mount($('pds-allocation-fields'),{initial,people:F.state.people,enabled:F.state.sharedCardEnabled,sourceStart:context.source_start,start:context.start_installment,count:context.installment_total,getBasis:start=>context.schedule.filter(p=>p.installment_no>=start).reduce((a,p)=>a+cents(p.amount),0)});dialog.showModal();
  }
  form.addEventListener('submit',event=>{event.preventDefault();F.busy(form,async()=>{await save(editor.read());dialog.close();F.toast(savedLabel);});});
  $('pds-expenses').addEventListener('click',event=>{const b=event.target.closest('button');if(!b)return;if(b.hasAttribute('data-allocation-close'))dialog.close();
    if(b.dataset.allocationExpense)action(async()=>{const id=Number(b.dataset.allocationExpense),start=Number(b.dataset.allocationStart);const context=await F.api('finance/expenses/'+id+'/sharing?start_installment='+start);show(context,async sharing=>{await F.api('finance/expenses/'+id+'/sharing','PUT',{sharing});await F.refresh();});});
    if(b.hasAttribute('data-allocation-import'))action(async()=>{
      const index=Number(b.dataset.allocationImport),edits=F.importEdits();for(let i=0;i<edits.length;i++){const edit=edits[i];if(cents(edit.amount)>0)edit.amount=cents(edit.amount)/100;for(const key of ['current_installment','total_installments','category_id','match_id'])if(/^\d+$/.test(String(edit[key])))edit[key]=Number(edit[key]);Object.assign(F.state.preview.rows[i],edit);}const row=F.state.preview.rows[index],start=Number(row.current_installment),n=Number(row.total_installments),amount=cents(row.amount);if(start<1||n<start||n>120||amount<1)throw new Error('Confira valor e parcelas antes de dividir.');
      const match=row.candidates.find(c=>c.id===Number(row.match_id));let context;
      if(match?.expense_id){context=await F.api('finance/expenses/'+match.expense_id+'/sharing?start_installment='+start);for(const p of context.schedule)if(p.installment_no===start)p.amount=amount/100;}
      else context={description:row.description,source_start:start,start_installment:start,installment_total:n,schedule:Array.from({length:n-start+1},(_,i)=>({installment_no:start+i,amount:amount/100})),sharing:{mode:'none'}};
      if(row.sharing)context.sharing=row.sharing;context.isImport=true;
      show(context,async sharing=>{F.state.preview.rows[index].sharing=sharing;F.renderPreview();});
    });
  });
})();
