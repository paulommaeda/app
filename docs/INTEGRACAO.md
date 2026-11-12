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
