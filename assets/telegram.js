(() => {
  'use strict';
  const card = document.getElementById('pds-telegram-account');
  if (!card || typeof PDS === 'undefined') return;
  const $ = id => document.getElementById(id);
  let busy = false;
  let provider = '', needsConsent = false;
  function message(text, error = false) { const el=$('pds-tg-message');el.textContent=text;el.hidden=!text;el.classList.toggle('pds-alert-success',!error); }
  async function api(path,method='GET',data=null) {
    const url=new URL(PDS.api,location.href);
    if(url.searchParams.has('rest_route')) url.searchParams.set('rest_route',url.searchParams.get('rest_route').replace(/\/?$/,'/')+path);
    else url.pathname=url.pathname.replace(/\/?$/,'/')+path;
    const options={method,credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':PDS.nonce,'Accept':'application/json'}};
    if(data!==null){options.headers['Content-Type']='application/json';options.body=JSON.stringify(data);}
    const response=await fetch(url,options);let result;
    try{result=await response.json();}catch(_){throw new Error('Não foi possível ler a configuração. Atualize a página.');}
    if(!response.ok)throw new Error(result.message||'Não foi possível concluir.');return result;
  }
  async function refresh() {
    const data=await api('telegram/link');
    provider=data.ai_provider;needsConsent=data.needs_consent;
    $('pds-tg-provider-name').textContent=data.provider_name;
    $('pds-tg-gemini-note').hidden=provider!=='gemini';
    $('pds-tg-renew-consent').hidden=!needsConsent;
    $('pds-tg-badge').textContent=!data.enabled?'Indisponível':data.linked?'Vinculado':'Não vinculado';
    $('pds-tg-unlinked').hidden=!data.enabled||data.linked;$('pds-tg-preferences').hidden=!data.enabled||!data.linked;
    if(!data.enabled){message('A integração ainda não foi ativada pelo responsável pelo portal.');return;}
    if(data.linked){
      $('pds-tg-link-result').hidden=true;$('pds-tg-command').textContent='';$('pds-tg-open').removeAttribute('href');
      const select=$('pds-tg-vehicle');select.replaceChildren(new Option('Perguntar quando houver mais de um','0'));
      data.vehicles.forEach(v=>select.add(new Option(v.name+(!v.is_owner?' (compartilhado)':''),String(v.id))));select.value=String(data.default_vehicle_id);
      $('pds-tg-confirm').checked=data.confirm_before_save;$('pds-tg-global-confirm').hidden=!data.global_confirmation;
      $('pds-tg-limits').textContent='Até '+data.voice_seconds+' segundos por áudio e '+data.daily_limit+' mensagens com reconhecimento por dia. Abastecimentos e gastos com dados completos são salvos automaticamente quando a confirmação está desmarcada.';
    }
  }
  async function run(action){if(busy)return;busy=true;card.querySelectorAll('button').forEach(b=>b.disabled=true);message('');try{await action();}catch(e){message(e.message||'Confira sua conexão e tente novamente.',true);}finally{busy=false;card.querySelectorAll('button').forEach(b=>b.disabled=false);}}
  $('pds-tg-connect').addEventListener('click',()=>run(async()=>{
    if(!$('pds-tg-accept').checked)throw new Error('Confirme o uso das mensagens para reconhecimento.');
    const result=await api('telegram/link','POST',{accepted:true,ai_provider:provider});$('pds-tg-open').href=result.url;$('pds-tg-command').textContent=result.code;$('pds-tg-link-result').hidden=false;
  }));
  $('pds-tg-refresh').addEventListener('click',()=>run(refresh));
  $('pds-tg-preferences').addEventListener('submit',event=>{event.preventDefault();run(async()=>{if(needsConsent&&!$('pds-tg-accept-provider').checked)throw new Error('Confirme o uso das mensagens pelo novo provedor.');await api('telegram/preferences','PUT',{default_vehicle_id:Number($('pds-tg-vehicle').value),confirm_before_save:$('pds-tg-confirm').checked,ai_provider:provider,accepted:$('pds-tg-accept-provider').checked});await refresh();$('pds-tg-accept-provider').checked=false;message('Preferências salvas.');});});
  $('pds-tg-unlink').addEventListener('click',()=>run(async()=>{await api('telegram/link','DELETE',{});await refresh();message('Telegram desvinculado.');}));
  run(refresh);
})();
