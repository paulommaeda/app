<?php defined( 'ABSPATH' ) || exit; ?>
<section class="pds-card pds-account-card pds-telegram-card" id="pds-telegram-account" style="margin-top:22px">
    <div class="pds-card-heading"><h2>Telegram</h2><span id="pds-tg-badge" class="pds-tag">Carregando</span></div>
    <p class="pds-muted">Registre abastecimentos por áudio ou texto, direto na conversa com o bot.</p>
    <p class="pds-muted">O Telegram recebe suas mensagens, e o conteúdo necessário é enviado a <strong id="pds-tg-provider-name"><?php echo esc_html( PDS_Telegram_Client::provider_name() ); ?></strong> para reconhecimento, somente nos serviços e veículos liberados para sua conta.</p>
    <p id="pds-tg-gemini-note" class="pds-muted" hidden>Na faixa gratuita do Gemini, o Google pode usar o conteúdo para melhorar seus produtos. Evite incluir informações pessoais desnecessárias nas mensagens.</p>
    <div id="pds-tg-message" class="pds-alert" role="status" hidden></div>
    <div id="pds-tg-unlinked" hidden>
        <label class="pds-check"><input id="pds-tg-accept" type="checkbox"><span>Concordo com esse uso para registrar meus abastecimentos.</span></label>
        <button type="button" class="pds-button" id="pds-tg-connect" style="margin-top:18px">Vincular meu Telegram</button>
        <div id="pds-tg-link-result" hidden><p class="pds-muted" style="margin:18px 0 10px">Abra o bot e toque em Iniciar. O link expira em dez minutos.</p><a id="pds-tg-open" class="pds-button pds-button-secondary" target="_blank" rel="noopener">Abrir no Telegram</a><p class="pds-muted" style="margin-top:10px">Se o Telegram já estiver aberto, envie este comando:</p><code id="pds-tg-command" style="display:block;overflow-wrap:anywhere;font-size:11px;margin:8px 0 16px"></code><button type="button" id="pds-tg-refresh" class="pds-text-button">Já vinculei, atualizar</button></div>
    </div>
    <form id="pds-tg-preferences" class="pds-form" style="margin-top:20px" hidden>
        <div id="pds-tg-renew-consent" hidden><p class="pds-muted">O provedor de reconhecimento mudou. Confirme o uso das suas mensagens pelo provedor indicado acima para continuar registrando pelo Telegram.</p><label class="pds-check"><input id="pds-tg-accept-provider" type="checkbox"><span>Concordo com o uso das mensagens por esse provedor.</span></label></div>
        <label>Veículo padrão<select id="pds-tg-vehicle"><option value="0">Perguntar quando houver mais de um</option></select></label>
        <label class="pds-check"><input id="pds-tg-confirm" type="checkbox"><span>Quero conferir o resumo antes de salvar.</span></label>
        <p id="pds-tg-global-confirm" class="pds-muted" hidden>O portal exige confirmação para todos os usuários.</p>
        <p id="pds-tg-limits" class="pds-muted"></p>
        <div class="pds-form-inline"><button type="submit" class="pds-button">Salvar preferências</button><button type="button" id="pds-tg-unlink" class="pds-text-button pds-danger">Desvincular Telegram</button></div>
    </form>
</section>
