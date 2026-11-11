<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap" style="max-width:1000px">
    <h1>Importar app antigo</h1>
    <p>Importe os usuários, veículos, compartilhamentos e abastecimentos do aplicativo PHP original. Os usuários serão contas do próprio WordPress.</p>
    <?php if ( $message ) : ?><div class="notice notice-error"><p><?php echo esc_html( $message ); ?></p></div><?php endif; ?>
    <?php if ( $job && ! $state ) : ?><div class="notice notice-warning"><p>A preparação anterior não está disponível. Envie o mesmo backup com o mesmo identificador de origem para continuar sem duplicações.</p></div><?php endif; ?>
    <?php if ( $state ) : ?>
    <div style="background:white;padding:24px;margin:24px 0;border:1px solid #dcdcde;border-radius:6px">
        <h2><?php echo $state['done'] ? 'Importação concluída' : 'Conferir e importar'; ?></h2>
        <p>Origem: <code><?php echo esc_html( $state['source'] ); ?></code></p>
        <table class="widefat striped" style="max-width:650px"><thead><tr><th>Dados encontrados</th><th>Quantidade</th></tr></thead><tbody><?php foreach ( array( 'users' => 'Usuários', 'vehicles' => 'Veículos', 'shares' => 'Compartilhamentos', 'records' => 'Abastecimentos' ) as $key => $label ) : ?><tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( number_format_i18n( $state['sizes'][ $key ] ) ); ?></td></tr><?php endforeach; ?></tbody></table>
        <?php if ( $state['warnings'] ) : ?><div class="notice notice-warning inline"><p><strong>Conferências da origem:</strong></p><ul style="list-style:disc;padding-left:20px"><?php foreach ( $state['warnings'] as $warning ) : ?><li><?php echo esc_html( $warning ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <p>Contas existentes com o mesmo e-mail serão vinculadas sem alterar sua senha ou permissões. Contas novas recebem o papel <strong>Cliente do portal</strong>, mesmo se eram administradoras no app antigo.</p>
        <div id="pds-import-progress" data-job="<?php echo esc_attr( $job ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'pds_import_' . $job ) ); ?>" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
            <p id="pds-import-status" role="status" aria-live="polite"><?php echo esc_html( $state['processed'] . ' de ' . $state['total'] . ' registros processados.' ); ?></p>
            <progress id="pds-import-bar" max="<?php echo esc_attr( $state['total'] ); ?>" value="<?php echo esc_attr( $state['processed'] ); ?>" style="width:100%;max-width:650px;height:22px"></progress>
            <div id="pds-import-error" class="notice notice-error inline" role="alert" <?php echo $state['error'] ? '' : 'hidden'; ?>><p><?php echo esc_html( $state['error'] ); ?></p></div>
            <table class="widefat striped" style="max-width:650px;margin:20px 0"><tbody><?php foreach ( self::LABELS as $key => $label ) : ?><tr><th><?php echo esc_html( $label ); ?></th><td data-import-count="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( number_format_i18n( $state['counts'][ $key ] ) ); ?></td></tr><?php endforeach; ?></tbody></table>
            <?php if ( ! $state['done'] ) : ?><button type="button" class="button button-primary" id="pds-import-start"><?php echo $state['processed'] ? 'Continuar importação' : 'Iniciar importação'; ?></button><?php endif; ?>
            <a class="button" href="<?php echo esc_url( pds_portal_url() ); ?>" target="_blank" rel="noopener">Abrir o portal</a>
        </div>
        <?php if ( ! $state['done'] ) : ?><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top:20px"><input type="hidden" name="action" value="pds_import_cancel"><input type="hidden" name="job" value="<?php echo esc_attr( $job ); ?>"><?php wp_nonce_field( 'pds_import_cancel_' . $job ); ?><button type="submit" class="button-link-delete">Descartar a preparação</button><p class="description">Registros já importados permanecem no WordPress. A preparação expira após uma hora sem processamento. Os dados antigos na origem não são alterados.</p></form><?php endif; ?>
    </div>
    <?php endif; ?>
    <h2>Preparar <?php echo $state ? 'outra ' : 'a '; ?>importação</h2>
    <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="pds_import_preview"><?php wp_nonce_field( 'pds_import_preview' ); ?>
        <table class="form-table" role="presentation">
            <tr><th><label for="pds-source-type">Origem dos dados</label></th><td><select name="source_type" id="pds-source-type"><option value="file">Backup SQL ou JSON</option><option value="database">Tabelas no banco deste WordPress</option></select><p class="description">Se o app usava outro banco, exporte as quatro tabelas pelo phpMyAdmin e envie o backup completo com os dados.</p></td></tr>
            <tr><th><label for="pds-legacy-file">Backup do app antigo</label></th><td><input type="file" name="legacy_file" id="pds-legacy-file" accept=".sql,.json"><p class="description">Até 10 MB e 50 mil registros. Tabelas: usuarios, veiculos, veiculos_compartilhados e abastecimentos. O arquivo é lido como dados e não fica disponível publicamente.</p></td></tr>
            <tr><th><label for="pds-table-prefix">Prefixo das tabelas antigas</label></th><td><input type="text" name="table_prefix" id="pds-table-prefix" maxlength="40" placeholder="Deixe vazio se não houver prefixo"><p class="description">Exemplo: para <code>antigo_usuarios</code>, informe <code>antigo_</code>. Não informe o prefixo do WordPress se as tabelas antigas não o usavam.</p></td></tr>
            <tr><th><label for="pds-source-key">Identificador desta origem</label></th><td><input type="text" name="source_key" id="pds-source-key" value="app-original" maxlength="64" pattern="[a-z0-9][a-z0-9_\-]{0,63}" required><p class="description">Mantenha o mesmo identificador ao repetir ou retomar o mesmo banco antigo. Isso evita duplicações e preserva as edições feitas no novo app.</p></td></tr>
            <tr><th>Tanques antigos</th><td><label><input type="checkbox" name="full_tank" value="1" checked> Considerar os abastecimentos antigos como tanque completo</label><p class="description">O app original não distinguia tanques parciais. Desmarque se não puder confirmar; você poderá ajustar os registros depois.</p></td></tr>
            <tr><th>Valor pago ausente</th><td><label><input type="checkbox" name="missing_paid" value="1"> Quando o campo estiver ausente, usar preço de tabela × litros</label><p class="description">Marque apenas se essa era a regra no banco antigo. Valores pagos existentes, inclusive zero, serão preservados.</p></td></tr>
        </table>
        <?php submit_button( 'Conferir dados antes de importar' ); ?>
    </form>
    <p>Finalize os lançamentos no app antigo antes da migração definitiva. Nenhum registro é gravado no destino durante a conferência. O processamento ocorre em etapas e pode ser retomado.</p>
</div>
