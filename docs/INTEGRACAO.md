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
