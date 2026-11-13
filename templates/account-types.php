<?php defined( 'ABSPATH' ) || exit; ?>
<section data-expense-view="account-types" hidden>
    <div class="pds-finance-heading"><div><h2>Tipos de conta</h2><p class="pds-muted">Organize suas contas financeiras com os tipos que você utiliza.</p></div><div class="pds-finance-actions" style="margin:0"><button type="button" class="pds-button pds-button-secondary" data-expense-tab="accounts">Voltar às contas</button><button type="button" class="pds-button" data-account-type-new>Cadastrar tipo</button></div></div>
    <p class="pds-finance-note">Conta corrente, poupança e dinheiro/carteira já vêm cadastrados. Você pode renomear, adicionar e arquivar tipos. Cada usuário tem seu próprio cadastro.</p>
    <div id="pds-account-types-list" class="pds-finance-grid"></div>
</section>
<dialog id="pds-account-type-dialog" class="pds-dialog" aria-labelledby="pds-account-type-title">
    <div class="pds-dialog-heading"><h2 id="pds-account-type-title">Cadastrar tipo de conta</h2><button type="button" class="pds-icon-button" data-account-type-close aria-label="Fechar tipo de conta">×</button></div>
    <form id="pds-account-type-form" class="pds-form"><div class="pds-form-error pds-alert" role="alert" hidden></div><label>Nome do tipo<input name="name" type="text" required minlength="2" maxlength="80" placeholder="Ex.: Conta digital, investimentos, benefícios"></label><button type="submit" class="pds-button pds-button-wide">Salvar tipo</button></form>
</dialog>
