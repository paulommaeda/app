<?php defined( 'ABSPATH' ) || exit; ?>
<section data-expense-view="spaces" hidden>
    <div class="pds-finance-heading"><div><h2>Compartilhamento e atividade</h2><p class="pds-muted">Cada participante usa sua própria conta WordPress e seu próprio Telegram.</p></div><button type="button" class="pds-button pds-button-secondary" id="pds-space-leave" hidden>Sair deste financeiro</button></div>
    <div id="pds-space-error" class="pds-alert" role="alert" hidden></div>
    <div class="pds-card pds-space-info"><p id="pds-space-help"></p><p>No Telegram, use <strong>/financeiros</strong> para listar os financeiros e <strong>/financeiro ID</strong> para escolher onde registrar. Aguarde a confirmação da troca. A escolha no portal é independente.</p><p>Consulta: acompanha os dados. Lançamentos: registra gastos, recebimentos, previsões, transferências e baixas. Administração: também edita, exclui e gerencia cadastros e participantes.</p></div>
    <div id="pds-space-management" hidden>
        <div class="pds-finance-heading"><h3>Participantes</h3><button type="button" class="pds-button" id="pds-space-invite">Convidar pessoa</button></div>
        <div id="pds-space-members" class="pds-card"></div>
        <h3>Convites pendentes</h3><div id="pds-space-invites" class="pds-card"></div>
        <div id="pds-space-invite-result" class="pds-card" role="status" hidden></div>
    </div>
    <div class="pds-finance-heading"><div><h3>Atividade dos lançamentos</h3><p class="pds-muted">Autoria dos registros. Contas previstas e movimentos realizados aparecem identificados pelo tipo.</p></div><button type="button" class="pds-button pds-button-secondary" id="pds-space-reload">Atualizar</button></div>
    <div id="pds-space-activity" class="pds-card"></div>
    <div class="pds-space-pages"><button type="button" class="pds-button pds-button-secondary" id="pds-space-previous">Anterior</button><span id="pds-space-page"></span><button type="button" class="pds-button pds-button-secondary" id="pds-space-next">Próxima</button></div>
</section>
<dialog id="pds-space-dialog" class="pds-dialog" aria-labelledby="pds-space-title"><div class="pds-dialog-heading"><h2 id="pds-space-title"></h2><button type="button" class="pds-icon-button" data-space-close aria-label="Fechar">×</button></div><form id="pds-space-form" class="pds-form"><div class="pds-form-error pds-alert" role="alert" hidden></div><div id="pds-space-fields" class="pds-form"></div><button type="submit" class="pds-button">Salvar</button></form></dialog>
