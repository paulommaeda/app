<?php defined( 'ABSPATH' ) || exit; ?>
<section data-expense-view="transfers" hidden>
    <div class="pds-finance-heading"><div><h2>Transferências entre contas</h2><p class="pds-muted">Mova valores entre suas contas sem somar gastos ou recebimentos.</p></div><button type="button" class="pds-button" data-transfer-new>Nova transferência</button></div>
    <p class="pds-finance-note">Registre aqui uma transferência que você já fez. O valor sai da origem e entra no destino; o total entre as duas contas permanece igual.</p>
    <form id="pds-transfers-filter" class="pds-card pds-form pds-finance-filter">
        <label>Mês<input type="month" name="month" value="<?php echo esc_attr( current_time( 'Y-m' ) ); ?>" required></label>
        <label>Conta<select name="account_id"><option value="">Todas as contas</option></select></label>
        <label>Situação<select name="status"><option value="all">Todas</option><option value="completed">Registradas</option><option value="reversed">Estornadas</option></select></label>
        <button type="submit" class="pds-button pds-button-secondary">Filtrar</button>
    </form>
    <p id="pds-transfers-loading" role="status" hidden>Consultando transferências…</p>
    <p id="pds-transfers-count" class="pds-muted" role="status" aria-live="polite"></p>
    <div id="pds-transfers-list" aria-busy="false"></div>
    <div id="pds-transfers-pagination" class="pds-finance-actions"></div>
</section>
<dialog id="pds-transfer-dialog" class="pds-dialog" aria-labelledby="pds-transfer-title">
    <div class="pds-dialog-heading"><h2 id="pds-transfer-title">Registrar transferência</h2><button type="button" class="pds-icon-button" data-transfer-close aria-label="Fechar transferência">×</button></div>
    <form id="pds-transfer-form" class="pds-form">
        <div class="pds-form-error pds-alert" role="alert" hidden></div>
        <div class="pds-form-grid"><label>Conta de origem<select name="from_account_id" required></select></label><label>Conta de destino<select name="to_account_id" required></select></label></div>
        <div id="pds-transfer-balances" class="pds-finance-note" aria-live="polite"></div>
        <div class="pds-form-grid"><label>Valor (R$)<input name="amount" type="text" inputmode="decimal" required placeholder="0,00"></label><label>Data da transferência<input name="transferred_on" type="date" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required></label></div>
        <label>Observações (opcional)<input name="notes" type="text" maxlength="500" placeholder="Ex.: Reserva do mês"></label>
        <p class="pds-finance-note">O registro atualiza os saldos no aplicativo. A transferência bancária deve ser feita por você no banco.</p>
        <button type="submit" class="pds-button pds-button-wide">Registrar transferência</button>
    </form>
</dialog>
