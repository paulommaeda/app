<?php defined( 'ABSPATH' ) || exit; ?>
<section data-expense-view="subscriptions" hidden>
    <div class="pds-finance-heading"><div><h2>Assinaturas do cartão</h2><p>Seus serviços mensais, com previsão na próxima fatura após cada fechamento.</p></div><button type="button" class="pds-button" data-sub-new>Cadastrar assinatura</button></div>
    <div id="pds-subscriptions-summary" class="pds-finance-grid pds-finance-stats"></div>
    <p class="pds-finance-note">Cobranças previstas entram na fatura e no fluxo de caixa previsto. Para registrar o gasto realizado, importe a cobrança ou confirme pela fatura. Pausar, cancelar ou excluir organiza as previsões aqui; o cancelamento com o fornecedor é feito no próprio serviço.</p>
    <form id="pds-subscriptions-filter" class="pds-form pds-finance-filter"><label>Situação<select name="status"><option value="">Todas</option><option value="active">Ativas</option><option value="paused">Pausadas</option><option value="canceled">Canceladas</option></select></label><label>Cartão<select name="card_id"><option value="">Todos os cartões</option></select></label><button class="pds-button pds-button-secondary" type="submit">Aplicar filtros</button></form>
    <p id="pds-subscriptions-loading" class="pds-finance-note" role="status" hidden>Carregando assinaturas…</p><div id="pds-subscriptions-warnings" class="pds-alert" hidden></div>
    <div id="pds-subscriptions-list" class="pds-finance-grid"></div>
    <div class="pds-finance-heading"><div><h3>Possíveis assinaturas identificadas</h3><p>Serviços reconhecidos pelo nome ou compras à vista presentes em mais de uma fatura. Confira se a cobrança é mensal antes de ativar.</p></div><button type="button" class="pds-button pds-button-secondary" data-sub-reload>Atualizar identificação</button></div>
    <div id="pds-subscriptions-suggestions" class="pds-finance-grid"></div>
</section>
<dialog id="pds-subscription-dialog" class="pds-dialog" aria-labelledby="pds-subscription-title"><div class="pds-dialog-heading"><h2 id="pds-subscription-title">Cadastrar assinatura</h2><button type="button" class="pds-icon-button" data-sub-close aria-label="Fechar assinatura">×</button></div>
    <form id="pds-subscription-form" class="pds-form"><div class="pds-form-error pds-alert" role="alert" hidden></div><div id="pds-subscription-fields" class="pds-form"></div><button type="submit" class="pds-button pds-button-wide">Salvar assinatura</button></form>
</dialog>
<dialog id="pds-subscription-charge-dialog" class="pds-dialog" aria-labelledby="pds-subscription-charge-title"><div class="pds-dialog-heading"><h2 id="pds-subscription-charge-title">Confirmar cobrança realizada</h2><button type="button" class="pds-icon-button" data-sub-close aria-label="Fechar cobrança">×</button></div>
    <form id="pds-subscription-charge-form" class="pds-form"><div class="pds-form-error pds-alert" role="alert" hidden></div><label>Valor cobrado (R$)<input name="amount" inputmode="decimal" required></label><label>Data real da cobrança<input name="spent_on" type="date" required></label><label>Categoria<select name="category_id" required></select></label><p class="pds-finance-note">A previsão será substituída por um único gasto no cartão, mantendo a mesma fatura. Isso não registra o pagamento da fatura.</p><button type="submit" class="pds-button pds-button-wide">Confirmar cobrança</button></form>
</dialog>
