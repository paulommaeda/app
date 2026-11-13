<?php defined( 'ABSPATH' ) || exit; ?>
<section data-expense-view="people" hidden>
    <div class="pds-finance-heading"><div><h2>Pessoas do cartão</h2><p class="pds-muted">Cadastre quem usa seus cartões. Você só precisa informar o nome.</p></div><button type="button" class="pds-button" data-person-new>Cadastrar pessoa</button></div>
    <p class="pds-finance-note">Ao registrar um gasto no crédito, marque “Este gasto é de outra pessoa” e selecione o nome. Cada parcela gera um recebimento previsto na data da fatura. A fatura e os gastos continuam juntos.</p>
    <div id="pds-people-list" class="pds-finance-grid"></div>
</section>
<dialog id="pds-person-dialog" class="pds-dialog" aria-labelledby="pds-person-title"><div class="pds-dialog-heading"><h2 id="pds-person-title">Cadastrar pessoa</h2><button type="button" class="pds-icon-button" data-person-close aria-label="Fechar pessoa">×</button></div><form id="pds-person-form" class="pds-form"><div class="pds-form-error pds-alert" role="alert" hidden></div><label>Nome<input name="name" type="text" minlength="2" maxlength="80" required placeholder="Ex.: Isabela Maeda"></label><button type="submit" class="pds-button pds-button-wide">Salvar pessoa</button></form></dialog>
