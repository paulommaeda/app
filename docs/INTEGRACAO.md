# Próximos serviços do portal

O plugin `portal-de-servicos` contém o núcleo do portal e o primeiro serviço,
`combustivel`. O futuro financeiro deve ser um plugin independente que registra
seu serviço neste núcleo. Nenhuma segunda tabela de usuários deve ser criada.

## Identidade e acesso

| Informação | Fonte |
| --- | --- |
| Conta autenticada | `get_current_user_id()` |
| Nome e e-mail | `wp_get_current_user()` / `get_userdata()` |
| URL do portal | `pds_portal_url()` |
| URL de um serviço | `pds_portal_url('financeiro')` |
| Permissão efetiva | `pds_user_can_access_service('financeiro')` |
| Registro de serviço | `pds_register_service()` no hook `pds_register_services` |
| Regras adicionais de acesso | Filtro `pds_service_access` |

As regras incluem serviço habilitado no site, conta não suspensa e ausência
de bloqueio individual. Essas regras são verificadas no servidor, também nas
rotas da API. Um usuário não recebe acesso aos dados financeiros de outra
conta só porque compartilha um veículo com ela.

## Registro do futuro plugin

Exemplo de estrutura (o financeiro ainda precisa implementar seu conteúdo):

```php
<?php
/**
 * Plugin Name: Meu Financeiro
 * Requires Plugins: portal-de-servicos
 */
defined('ABSPATH') || exit;

add_action('pds_register_services', static function () {
    pds_register_service('financeiro', array(
        'title' => 'Meu Financeiro',
        'description' => 'Receitas, despesas e uma visão clara das suas finanças.',
        'icon' => 'chart',
        'order' => 20,
        'render_callback' => 'meu_financeiro_render',
        'permission_callback' => static function ($user_id) {
            // Regra própria opcional. Nunca conceder acesso a dados de terceiros.
            return $user_id > 0;
        },
    ));
});

function meu_financeiro_render() {
    // Implementar a interface do serviço, limitada a get_current_user_id().
}
```

`conta` é uma rota reservada. Slugs duplicados não são registrados.
Ícones disponíveis: `grid`, `fuel`, `chart`, `user`, `car`, `history`, `shield`.
O callback imprime HTML; ele só é executado após verificar a permissão.
Use `esc_html`, `esc_attr` e `esc_url` para saídas dinâmicas.

O núcleo dispara `pds_register_services` em `init`, prioridade 20. Adicione seu
callback no carregamento normal do plugin, antes desse hook. Para scripts e
estilos do módulo, use `pds_enqueue_assets`, restrito às páginas do portal.

## Segurança dos dados financeiros

Cada tabela do novo plugin deve ter `owner_user_id` com o ID de `wp_users`.
Consulte sempre esse campo junto com o ID do registro, inclusive em alteração,
exclusão, exportação e relatórios. Não aceite do cliente o ID do proprietário.

Toda rota protegida deve ter uma `permission_callback`, por exemplo:

```php
'permission_callback' => static function () {
    if (!is_user_logged_in()) {
        return new WP_Error('login_required', 'Entre na sua conta.', array('status' => 401));
    }
    if (!pds_user_can_access_service('financeiro')) {
        return new WP_Error('not_allowed', 'Acesso não liberado.', array('status' => 403));
    }
    return true;
}
```

A verificação do serviço não substitui a checagem do proprietário do registro.
Use autenticação nativa e envie o nonce `wp_rest` no cabeçalho `X-WP-Nonce`.
Não crie sessões PHP nem endpoints PHP acessíveis diretamente.

## Eventos para sincronizar abastecimentos

| Hook | Argumentos |
| --- | --- |
| `pds_fuel_record_created` | `$payload, null` |
| `pds_fuel_record_updated` | `$payload, $previous_record` |
| `pds_fuel_record_deleted` | `$payload, null` |
| `pds_fuel_vehicle_deleted` | `$vehicle` |
| `pds_user_registered` | `$user_id` |

Os eventos de abastecimento são disparados após a gravação/exclusão. O payload
contém `id`, `vehicle_id`, `created_by`, `filled_on`, `odometer`, `fuel_type`,
`price`, `liters`, `paid`, `full_tank`, `notes`, datas e os campos de integração:

| Campo | Exemplo e uso |
| --- | --- |
| `owner_user_id` | Conta proprietária do veículo; titular sugerido da despesa |
| `created_by` | Conta que registrou; pode ser um colaborador |
| `vehicle_name` | Descrição do veículo |
| `source` | `pds_combustivel:123`; chave estável para evitar duplicação |
| `currency` | `BRL` |
| `paid` | Valor efetivamente pago, já com descontos |
| `filled_on` | Data registrada pelo usuário |

No financeiro, crie uma chave única em `(owner_user_id, source)` e faça um
upsert na criação/edição. Na exclusão, remova ou estorne a despesa pela mesma
chave. Não use o usuário que está logado como titular durante a sincronização:
a operação pode ter sido executada por um colaborador ou ferramenta de privacidade.

Compartilhamento de combustível não concede permissão para abrir o financeiro
do proprietário. Se quiser atribuir uma despesa ao condutor, implemente uma
regra de negócio explícita no financeiro.

Não existe sincronização financeira neste pacote. Esses eventos estabelecem
o contrato para o próximo plugin. A sincronização também deverá prever backfill
dos abastecimentos anteriores à instalação do financeiro e reconciliação de
falhas, usando uma fila e a chave `source` para operações idempotentes.

## Planos e cobrança futura

Para restringir um serviço a uma assinatura ativa, o plugin de pagamentos pode
aplicar uma regra adicional. Ela não reverte bloqueios administrativos:

```php
add_filter('pds_service_access', static function ($allowed, $service, $user_id) {
    if ($service !== 'financeiro') {
        return $allowed;
    }
    return $allowed && minha_assinatura_esta_ativa($user_id);
}, 10, 3);
```

`minha_assinatura_esta_ativa` é ilustrativa e deverá ser implementada pelo
plugin de cobrança. O cadastro atual é gratuito; não há checkout, planos,
gateway ou renovação automática no plugin entregue.

## Dados e implantação

O plugin cria tabelas com o prefixo da instalação, sem alterar tabelas de
outros plugins: `pds_vehicles`, `pds_vehicle_shares`, `pds_fuel_records` e
`pds_rate_limits` e `pds_import_map`. Usuários e sessões continuam sendo os do WordPress.

Datas de abastecimento usam o fuso do site; timestamps internos são UTC.
Valores pagos têm duas casas; litros e preços de tabela têm três casas.
O cálculo usa tanques completos e acumula parciais até completar o tanque.
Atualizações de esquema usam `dbDelta`. Desativar/remover preserva dados.

A migração administrativa do banco original aceita SQL/JSON ou tabelas na
mesma conexão WordPress, com mapa de IDs e preservação de senhas compatíveis.
Veja MIGRACAO.md. As credenciais do app antigo, seus endpoints diretos e seu
service worker não são distribuídos.
Ative individualmente no site desejado se estiver em WordPress Multisite.


A importação dispara `pds_fuel_record_created` somente para abastecimentos novos
no destino, após confirmar a gravação, e `pds_user_imported($user_id, $source,
$legacy_id)` para novas contas. Repetições ignoradas não disparam esses eventos.


## Telegram (1.2.0)

O núcleo opcional de Telegram vincula o ID da conta a `wp_users.ID`. O filtro
`pds_telegram_reply` permite acrescentar outros serviços após autenticação da
conversa privada. Cada serviço precisa verificar sua própria permissão e
propriedade dos dados. Veja [TELEGRAM.md](TELEGRAM.md) para o contrato e configuração.

Tabelas adicionais: `pds_tg_links`, `pds_tg_updates` e `pds_tg_drafts`. Elas não
substituem `wp_users`. `pds_fuel_records.external_ref` é uma origem interna única
para impedir gravações duplicadas de uma mensagem; não é aceita do cliente REST.

## Provedores de reconhecimento (1.3.0)

O vínculo Telegram inclui `consent_provider` (`openai` ou `gemini`). Antes de
enviar conteúdo de um usuário a uma IA, um módulo deve conferir se esse valor
corresponde a `PDS_Telegram_Client::settings()["ai_provider"]`. A troca de provedor
é autorizada no portal e preserva a identidade WordPress. O módulo mantém suas
próprias verificações de acesso ao serviço e aos dados. O financeiro não deve
receber acesso apenas por um usuário compartilhar um veículo.

## Meus Gastos (1.4.0)

O serviço `gastos` usa os mesmos usuários e o mesmo bot. As tabelas
`pds_expenses`, `pds_expense_categories` e `pds_tg_expense_drafts` são privadas por
`user_id`. O campo `pds_tg_updates.record_service` distingue o registro de cada
módulo, preservando os registros anteriores como combustível.

O reconhecimento tem `intent` fuel/expense/other/unclear e o objeto nullable
`expense`. O roteador entrega o resultado uma única vez ao serviço escolhido,
que valida acesso e propriedade antes de gravar. Campos numéricos ambíguos não
são presumidos e uma pendência de outro serviço precisa ser resolvida.

Eventos `pds_expense_created`, `pds_expense_updated` e `pds_expense_deleted` recebem
registro e ID nativo do usuário. Um plugin financeiro futuro pode consumir
esses eventos respeitando a conta e evitando duplicar a origem interna.
Veja [GASTOS.md](GASTOS.md) para API, dados e alcance.

## Meu Financeiro (1.5.0)

`gastos` continua sendo o identificador de acesso. As novas tabelas InnoDB
`pds_fin_accounts`, `pds_fin_receipts`, `pds_fin_cards`, `pds_fin_invoices`,
`pds_fin_installments`, `pds_fin_bill_payments` e `pds_fin_adjustments` usam
`user_id` nativo e verificações de propriedade, sem exceção de leitura pessoal
para administradores. Rotas autenticadas sob `/pds/v1/finance/`: `accounts`,
`receipts`, `cards`, `plan`, `overview`, `invoices`, `payments`, `adjustments`,
`import/preview`, `import/apply`, `import/cancel`.

`pds_expenses.fuel_record_id` é único e opcional. O combustível nativo grava seu
espelho financeiro dentro da mesma transação. Um plugin adicional **não deve**
criar outra despesa ao consumir `pds_fuel_record_created`: a integração já existe.
O evento continua público para outros consumidores. O importador legado usa esse
evento para espelhar registros novos importados, de forma idempotente.

A compra (`pds_expenses`) mantém o total. A responsabilidade mensal fica nas
parcelas. Pagamento de fatura é saída da conta, não outra compra. Importação que
começa em i/N projeta só as parcelas de i até N; o total dessa compra importada
representa os compromissos restantes conhecidos. Datas de faturas são snapshots;
alterar a regra de cartão não reescreve faturas existentes.

Campos de crédito no objeto IA `expense`: `card_hint`, `account_hint`,
`installment_mode` e `installments`. O servidor resolve nomes contra recursos
do próprio usuário; nunca aceita IDs fornecidos pela IA. Em `intent=fuel`, o
objeto opcional informa apenas o pagamento do mesmo abastecimento.

## Recebimentos no Telegram (1.6.0)

O reconhecimento inclui `intent=receipt` e o objeto nullable `receipt`, com
`description`, `amount`, `received_on`, `account_hint`, `notes` e `uncertain_fields`.
O roteador distingue pendência de entrada, despesa e abastecimento, mesmo quando
as duas primeiras ações usam a permissão do serviço `gastos`. IDs internos de
contas não são aceitos da IA nem enviados no contexto da pendência.

`pds_fin_receipts.source` indica manual/telegram. O `external_ref` único é
controlado pelo servidor e impede duplicar a mesma origem Telegram. A API REST
ignora origem/identificador fornecidos pelo cliente e oculta esses identificadores
internos nas consultas. `PDS_Finance::save_receipt()` mantém validação, propriedade
e transação comuns. `pds_tg_updates.record_service=recebimentos` identifica a
resposta já salva para retomada da fila sem confundir IDs de outros módulos.

Pendências em `pds_tg_receipt_drafts` pertencem ao usuário e Telegram vinculados,
expiram em duas horas e participam da limpeza diária, desvinculação e apagamento
de privacidade. As colunas novas preservam os recebimentos existentes com
origem manual por padrão. O modelo e as chaves da IA não mudam na atualização.

## Previsões e recorrências (1.7.0)

`pds_fin_recurring` guarda definições mensais do próprio usuário, com tipo
`payable`/`receipt`, dia âncora, início, término opcional e cursor de geração.
`pds_fin_payables` guarda ocorrências, com status pending/paid/canceled e vínculo
único opcional ao gasto da baixa. Recebimentos continuam em `pds_fin_receipts`,
com `recurring_id` e `occurrence_month` opcionais e status canceled para previsões
descartadas. A restrição única (recurring_id,occurrence_month) impede duplicação.

Rotas sob `/pds/v1/finance/`: `payables`, `payables/{id}`, `payables/{id}/pay`,
`payables/{id}/reopen`, `recurring`, `recurring/{id}` e `receipts/plan`. As rotas
aplicam autenticação, nonce, permissão de `gastos` e propriedade. `payables` lista
por mês e situação; faturas são derivadas dos registros existentes do cartão.

O evento horário `pds_finance_planning` e as consultas materializam previsões
sem marcar pagamentos/recebimentos como realizados. A geração é retomável,
serializada por definição e limitada por lote. O saldo soma apenas recebimentos
received, despesas realizadas e pagamentos de fatura. O fluxo previsto considera
contas pending, recebimentos pending e faturas ainda abertas, sem duplicar custos.

`pds_expenses.payable_id` vincula exclusivamente uma baixa. A gravação da conta
paga e do gasto ocorre na mesma transação, incluindo eventuais parcelas do cartão.
O parâmetro interno in_transaction de save_entry nunca é recebido do corpo REST.
Desfazer baixa também é atômico e respeita faturas com pagamentos registrados.

O reconhecimento aceita `intent=payable` com descrição, vencimento, valor
previsto/indefinido, categoria, conta bancária opcional e recorrência. `receipt`
aceita status received/pending e os mesmos campos de repetição. Dados internos
de ID, cursor, referências e flags não são enviados à IA. `pds_tg_payable_drafts`
e record_service=contas_pagar separam as pendências e as respostas gravadas.

## Transferências internas — 1.8.0

Todas as rotas abaixo usam sessão/nonce WordPress e a permissão do serviço
`gastos`, sempre com propriedade verificada nas duas contas.

* `GET /pds/v1/finance/transfers`: filtros `month` (YYYY-MM), `account_id` opcional,
  `status` (`all`, `completed`, `reversed`) e `page` (50 por página). Retorna
  `transfers`, `total`, `page` e `pages`; cada linha inclui nomes da origem/destino.
* `POST /pds/v1/finance/transfers`: `from_account_id`, `to_account_id`, `amount`,
  `transferred_on` (YYYY-MM-DD), `notes` opcional e `request_key` obrigatório
  (16–80 caracteres: letras, números, `_` ou `-`; um UUID serve).
* `POST /pds/v1/finance/transfers/{id}/reverse`: estorna somente o registro próprio,
  preserva histórico e é idempotente. Não é uma transferência bancária.

Um cliente deve reutilizar `request_key` ao repetir o mesmo pedido por timeout.
Com conteúdo diferente, a mesma chave retorna HTTP 409. A chave é vinculada ao
usuário no servidor e não é exposta na listagem. Transferências estornadas não
são recriadas por reenvio do pedido antigo. Contas são bloqueadas em ordem de ID
na transação e cada transferência é uma linha única. Não são criados registros
em `pds_expenses` ou `pds_fin_receipts`.

`PDS_Finance::account_balance()` inclui o efeito líquido das transferências
realizadas, respeitando a data inicial e o saldo histórico solicitado. O estorno
remove o efeito original também de consultas históricas. O fluxo de caixa
continua excluindo transferências dos indicadores de receitas/despesas.

## Tipos de conta e datas de pagamento — 1.9.0

* `GET /pds/v1/finance/account-types`: retorna `account_types`, com `id`, `code`,
  `name`, `active` e `account_count`, limitado ao proprietário autenticado.
* `POST /pds/v1/finance/account-types`: `name` e `active` opcional (booleano).
* `PUT /pds/v1/finance/account-types/{id}`: `name` e/ou `active`.
* `DELETE /pds/v1/finance/account-types/{id}`: arquiva, sem remover vínculos.

O código `code` é gerado pelo servidor e não muda quando o tipo é renomeado.
Use esse código no campo `kind` de `/finance/accounts`. Os códigos anteriores
`checking`, `savings` e `cash` continuam válidos. A resposta de contas passa a
incluir `kind_name` e `kind_active`; `/expenses/categories` inclui `account_types`
para alimentar os seletores do portal. Tipos arquivados só podem permanecer
nas contas que já os utilizavam. Nome/código/contas são sempre do mesmo usuário.

* `PUT /pds/v1/finance/payments/{id}` com `paid_on` (YYYY-MM-DD) corrige a data
  de um pagamento de fatura já registrado.
* `PUT /pds/v1/finance/payables/{id}/payment-date` com `paid_on` corrige a data
  da baixa de uma conta paga e de seu gasto associado.

As duas correções validam datas reais até hoje e não alteram valores ou vínculos
financeiros, mesmo que outros campos sejam enviados. A correção de baixa no
crédito preserva parcelas/faturas existentes; corrige a data do gasto e não
recalcula ciclos. Operações são transacionais, com propriedade e permissão do
serviço `gastos`. A correção da baixa emite `pds_expense_updated` após confirmar a
transação. Tipos e pagamentos estão incluídos na privacidade e na preservação da
atualização nativa do plugin.

## Pessoas e reembolsos de cartão — 1.10.0

O administrador ativa `shared_card` em `pds_settings` pelo formulário protegido
do painel. O padrão é desativado. Rotas de pessoas usam a sessão/nonce, a permissão
do serviço `gastos` e a ativação dessa opção:

* `GET /pds/v1/finance/people`: `people` com `id`, `name` e `active` próprios.
* `POST /pds/v1/finance/people`: `name` (2–80 caracteres); `active` booleano opcional.
* `PUT /pds/v1/finance/people/{id}`: `name` e/ou `active`.
* `DELETE /pds/v1/finance/people/{id}`: arquivamento, mantendo vínculos existentes.

`/expenses/categories` informa `shared_card_enabled` e `people` para a interface.
Em gastos de cartão, `person_id` positivo atribui a compra a uma pessoa própria
ativa; `0` remove a atribuição; ausência preserva a atribuição anterior. Outros
pagamentos não aceitam pessoa. O mesmo campo é aceito no pagamento particular
pelo autor de um abastecimento. A resposta do gasto inclui `person_id` e
`person_name`; itens de fatura também incluem esses campos.

Recebimentos automáticos usam `source=shared_card`, `person_id`,
`shared_expense_id`, `shared_installment_no`, `shared_installment_total`,
`shared_planned_amount` e `shared_planned_on`. O índice único por compra/pessoa/parcela
impede duplicação, incluindo compras com vários participantes. A conta inicial é `account_id=0` enquanto não recebido.
`GET /finance/receipts` aceita `person_id` opcional (sempre próprio), usa junção
opcional de conta e retorna `pending_total` e `total_count` do período filtrado.

A gravação de uma compra sincroniza previsões na mesma transação. Correções de
vencimento e conferência de parcelas importadas também sincronizam previsões.
Valores, pessoas ou datas previstas de reembolsos recebidos não são sobrescritos;
correções incompatíveis retornam HTTP 409 e revertem a transação.

A API nativa de recebimentos confirma reembolsos com conta própria e data real,
ou restaura o estado pending com os dados previstos. Os campos de vínculo e o
nome da previsão são controlados pelo servidor. `DELETE /finance/receipts/{id}`
cancela um reembolso vinculado, mantendo sua identidade para não recriá-lo. Os
campos enviados pelo cliente não podem forjar esses vínculos. Desativar a opção
bloqueia novas atribuições, preservando manutenção e baixa dos registros antigos.


## Divisão entre vários participantes — 1.11.0

As mesmas rotas de gastos aceitam `sharing` como objeto ou JSON (até 12.000
caracteres). O campo legado `person_id` continua válido para atribuições integrais;
em compras com divisão registrada, clientes antigos que omitem `sharing`
preservam a divisão existente. `person_name` lista todos os participantes e
`sharing_context` contém o acordo e as parcelas reais para edição.

Exemplo para metade sua e metade da pessoa 17, em uma compra de R$ 500/5:

```json
{
  "sharing": {
    "mode": "equal",
    "include_owner": true,
    "start_installment": 1,
    "people": [{"person_id": 17}]
  }
}
```

Modos: `none`, `single` (usa `person_id`), `equal` e `amounts`. Em `amounts`,
cada item de `people` tem `person_id` e `amount` monetário; a base é a soma das
parcelas a partir de `start_installment`. Com `include_owner=true`, a diferença
é a parte do autor; caso contrário, a soma deve fechar com a base. Pessoas devem
ser próprias, diferentes e ativas (arquivadas são aceitas somente para manter
vínculos existentes). Há até 20 pessoas externas por compra.

* `GET /pds/v1/finance/expenses/{id}/sharing?start_installment=3`: contexto próprio,
  com descrição, primeira parcela existente, parcela inicial, quantidade total,
  valor restante, parcelas efetivas e divisão proporcional.
* `PUT /pds/v1/finance/expenses/{id}/sharing`: recebe `sharing` e atualiza somente
  o acordo e os reembolsos. Não altera os valores das parcelas ou da compra.

Ambas as rotas exigem a permissão financeira, autenticação e propriedade. Novas
divisões exigem `shared_card` ativado. A prévia de importação aceita `sharing`
em cada edição; candidatos de reconciliação incluem `expense_id`. A confirmação
aplica o acordo à parcela importada e às seguintes na mesma transação.

`pds_fin_splits` armazena os pesos por compra/pessoa; `person_id=0` representa o
autor e não gera reembolso. Gastos incluem `sharing_mode` e `sharing_start`.
Recebimentos incluem `shared_invoice_amount`, o valor bruto da parcela de origem.
A migração substitui o índice antigo `shared_part` por `shared_person_part`
(compra, pessoa, parcela) e preenche a base dos reembolsos legados sem criar
novos usuários ou alterar valores. As operações de esquema são idempotentes.

A distribuição usa centavos inteiros e maiores restos, com desempate por ID.
Conserva os totais de cada parcela e de cada pessoa, evitando arredondamentos
acumulados. A partir de um novo acordo, parcelas anteriores mantêm seus vínculos.
Mudanças que invalidem reembolsos já recebidos retornam 409 e revertem toda a
operação; é possível escolher uma parcela posterior como início do novo acordo.
Divisões e campos adicionais integram exportação/apagamento e atualização nativa.


## Assinaturas do cartão — 1.12.0

Rotas com a mesma autenticação/nonce, propriedade e permissão do serviço financeiro:

* `GET /pds/v1/finance/subscriptions`: `subscriptions`, `monthly_total` e `warnings`.
* `POST /finance/subscriptions`: `name`, `merchant`, `card_id`, `category_id`,
  `amount`, `billing_day` (1–31), `first_invoice` opcional (AAAA-MM), `notes`
  opcional e `status` (`active`, `paused`, `canceled`). `source_expense_id`
  opcional vincula uma compra própria à vista já existente, sem duplicá-la.
* `PUT /finance/subscriptions/{id}`: edição parcial dos campos e da situação;
  `first_invoice` é imutável após cadastrar. Atualiza apenas previsões abertas
  sem pagamentos. Reativação retoma na próxima fatura aberta.
* `DELETE /finance/subscriptions/{id}`: exclusão da lista, com histórico preservado.
* `GET /finance/subscriptions/suggestions`: sugestões com compra de origem,
  descrição, cartão, valor, quantidade de faturas e motivo, sem ativação automática.
* `GET /finance/subscriptions/source/{expense_id}`: formulário preenchido a partir
  da compra própria; rejeita parcelamentos e outros meios de pagamento.
* `POST /finance/subscription-charges/{id}/confirm`: `amount`, `spent_on` e
  `category_id` opcionais para conferir a cobrança real; `sharing` opcional usa
  a mesma divisão das compras normais. Repetições retornam o mesmo gasto.
* `DELETE /finance/subscription-charges/{id}`: remove somente a ocorrência prevista,
  impedindo regeneração. Rejeita cobranças realizadas ou faturas com pagamentos.

`pds_fin_subscriptions` guarda configurações e o cursor `next_month` por usuário.
`pds_fin_subscription_charges` guarda a ocorrência por assinatura/mês de fatura,
com descrição, valor e data próprios. `expense_id` único fica nulo na previsão.
Estados: `planned`, `confirming` (apenas dentro da transação), `confirmed`,
`canceled` (pausa/cancelamento) e `skipped` (ocorrência explicitamente removida).
Exclusão de cadastro usa `status=deleted` e libera seu nome para recadastro,
preservando o histórico. Compras de cadastros excluídos podem ser reassociadas
sem recriar o gasto.

A ação horária `pds_finance_subscriptions` processa até 50 usuários por rodada,
com cursor persistido, respeitando bloqueios do serviço. Consultas financeiras
executam a mesma sincronização idempotente. O fechamento efetivo determina até
qual fatura gerar; uma rodada recupera até 120 meses por assinatura.

`invoice_totals` inclui `subscription_forecast`, já somado em `total` e em aberto.
Itens previstos usam `id="subscription:{charge_id}"`, `expense_id=0`,
`is_subscription_forecast=true`, `subscription_id` e `subscription_charge_id`.
Eles não existem na tabela de gastos. Na prévia da importação, candidatos podem
usar esse ID textual em `match_id`. A confirmação valida novamente usuário,
cartão, mês, pagamento, valor e data antes de substituir a previsão pelo gasto.
Cobranças importadas usam a origem nativa `statement`; confirmações manuais usam
`subscription`. Campos internos, vínculos e a troca transacional não são
controlados por parâmetros REST de gastos comuns.

Faturas com pagamentos permitem substituição pelo mesmo valor, mantendo totais
pagos e em aberto; alterações de valor retornam 409. Recebimentos e pagamentos
não são criados pela geração ou confirmação. Novos cadastros, ocorrências e
campos integram privacidade e atualização nativa.


## Extrato de contas e correções — 1.13.0

Rotas autenticadas com nonce e a mesma permissão do serviço `gastos`:

* `GET /pds/v1/finance/accounts/{account_id}/statement`: `from`, `to`, `page`
  e `per_page` (1–100, padrão 50). Período de até dois anos. Retorna `account`,
  `movements`, `balance_before`, `credits`, `debits`, `opening_in_period`,
  `balance_after`, `total`, `page`, `pages` e `per_page`.
* `GET /finance/accounts/{account_id}/statement/{kind}/{id}`: contexto de edição,
  com `editor`, `data`, `edit_note` e `delete_message` próprios da origem.
* `PUT` na mesma rota: data real em `date`, valor em `amount` e os campos próprios
  do editor. `account_id` move recebimentos, gastos e pagamentos para outra conta
  própria. Transferências usam `from_account_id` e `to_account_id`. A rota preserva
  os vínculos e não aceita alterar origem, proprietário ou situação por parâmetros.
* `DELETE` na mesma rota: desfaz o efeito financeiro usando a operação original.

`kind` é `opening`, `receipt`, `expense`, `payment` ou `transfer`. `id` é o ID
original do movimento; para saldo inicial, o ID da conta. Uma despesa vinculada
pode retornar `editor=payable` ou `editor=fuel`, preservando `kind=expense`.
O movimento precisa efetivamente pertencer ao extrato da conta indicada.

A listagem usa UNION ALL das tabelas nativas em snapshot consistente, com datas
já realizadas e regras equivalentes ao saldo da conta. O saldo inicial é separado
da renda/despesa do período. A ordenação estável conserva os saldos entre páginas.
Cada item tem `key`, `kind`, `id`, `date`, `description`, `source`, `notes`,
`amount` absoluto, `signed_amount` e `balance`. Não existe tabela paralela de extrato.

`PUT /finance/transfers/{id}` permite corrigir transferências concluídas. Mantém
`request_key`, ID e uma única linha, bloqueia as contas em ordem de ID e valida
origem/destino próprios, situação e datas iniciais. Reversões continuam usando
`POST /finance/transfers/{id}/reverse` e não podem ser reativadas por edição.

A correção de uma baixa mantém a transação externa e usa uma opção interna de
`save_entry`, restrita ao vínculo de conta a pagar conferido pelo servidor. Clientes
REST não podem forjar essa opção. Pagamentos de fatura são corrigidos sob bloqueio
da fatura/pagamento, considerando a soma dos demais pagamentos. Abastecimentos
seguem o método original de gravação, com sincronização e validação de quilometragem.
Recebimentos recorrentes e reembolsos excluídos mantêm uma ocorrência cancelada.

Editar a data inicial de uma conta verifica também recebimentos, despesas diretas
e pagamentos já incluídos no saldo, além das transferências existentes. A consulta
e as alterações respeitam propriedade, arquivamento e bloqueios de serviço.


## Dashboard mensal por parcelas — 1.14.0

`GET /pds/v1/expenses/report` passa a usar `basis=invoice_installments` como
semântica padrão, preservando a autenticação, filtros e formato das agregações.
Gastos fora do cartão (e crédito legado sem cartão) usam valor/data originais;
compras com cartão usam `pds_fin_installments.amount` e o vencimento da respectiva
fatura. Nenhum movimento ou saldo novo é armazenado. A leitura agrega as fontes
em snapshot consistente e restringe todas as junções ao proprietário.

Sem `to`, o relatório termina no último dia do mês atual. Um mês calendário
completo é comparado com o mês anterior completo; períodos personalizados mantêm
a comparação com a janela anterior de mesma duração. Filtros de data em crédito
usam `invoices.due_on`. Categoria, pagamento, descrição, estabelecimento e notas
continuam sendo filtrados nos dados da compra original, mesmo que ela tenha sido
cadastrada em outro mês. Histórico e exportação de compras mantêm seus filtros
pela data original e o valor integral cadastrado.

`summary.count` conta lançamentos/parcelas no período; `largest` é o maior valor
individual do período. `summary.card_spent` soma somente parcelas de cartões.
Categorias, pagamentos e evolução derivam da mesma fonte e valores. `recent`
retorna até cinco lançamentos do período com o ID original da compra, `amount`
da parcela, `spent_on` do vencimento, `purchase_on`, `is_installment`,
`installment_no`, `installment_total` e `invoice_month`, além dos metadados
usuais. Não inclui o valor integral do contrato no item mensal.

`GET /expenses/entries` e `GET /expenses/entries/{id}` continuam disponíveis para
consulta/edição da compra com seu valor original. Edições, exclusões e importações
reconciliadas refletem no relatório pelo cronograma existente. Assinaturas apenas
previstas e pagamentos de faturas não são despesas adicionais nessa fonte.
