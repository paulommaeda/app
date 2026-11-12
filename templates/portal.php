<?php defined( 'ABSPATH' ) || exit; ?>
<div id="pds-app" class="pds-app">
    <noscript><div class="pds-alert">Ative o JavaScript do navegador para utilizar o portal.</div></noscript>
    <div id="pds-toast" class="pds-toast" role="status" aria-live="polite" hidden></div>
    <?php if ( ! is_user_logged_in() ) : ?>
    <div class="pds-auth-layout">
        <aside class="pds-auth-story">
            <a class="pds-brand" href="<?php echo esc_url( pds_portal_url() ); ?>"><span class="pds-brand-symbol"><?php echo PDS_Core::icon( 'grid' ); ?></span><span><?php echo esc_html( $brand ); ?></span></a>
            <div class="pds-auth-message"><span class="pds-eyebrow">MAIS SIMPLES. MAIS SEU.</span><h1>Sua rotina,<br>sob controle.</h1><p>Seus serviços reunidos em um lugar.<br>Mais clareza para as decisões do dia a dia.</p>
                <div class="pds-auth-visual" aria-hidden="true"><div class="pds-visual-icon"><?php echo PDS_Core::icon( 'fuel' ); ?></div><span class="pds-visual-line"></span><div><small>MEU COMBUSTÍVEL</small><strong>Cada quilômetro conta.</strong><span>Registre · Acompanhe · Economize</span></div></div>
            </div>
            <p class="pds-auth-foot"><?php echo PDS_Core::icon( 'shield' ); ?> Seus dados acessíveis apenas a você e a quem você autorizar.</p>
        </aside>
        <main class="pds-auth-main">
            <div class="pds-auth-card">
                <span class="pds-eyebrow">BEM-VINDO AO SEU PORTAL</span>
                <h2 id="pds-auth-title">Bom ter você por aqui.</h2>
                <p id="pds-auth-subtitle" class="pds-muted">Entre para acessar seus serviços.</p>
                <div id="pds-auth-tabs" class="pds-segment" aria-label="Acesso ao portal">
                    <button type="button" data-auth-view="login" class="is-active">Entrar</button>
                    <?php if ( ! empty( $settings['registration'] ) ) : ?><button type="button" data-auth-view="register">Criar conta</button><?php endif; ?>
                </div>
                <div id="pds-auth-error" class="pds-alert" role="alert" hidden></div>
                <div id="pds-auth-success" class="pds-alert pds-alert-success" role="status" hidden></div>
                <form data-auth-form="login" class="pds-form">
                    <label>E-mail<input name="email" type="email" autocomplete="username" placeholder="voce@exemplo.com" maxlength="100" required></label>
                    <label>Senha<input name="password" type="password" autocomplete="current-password" placeholder="Sua senha" maxlength="200" required></label>
                    <div class="pds-form-inline"><label class="pds-check"><input type="checkbox" name="remember" value="1"> Lembrar de mim</label><button type="button" class="pds-text-button" data-auth-view="forgot">Esqueci minha senha</button></div>
                    <button class="pds-button pds-button-wide" type="submit">Entrar no portal <?php echo PDS_Core::icon( 'arrow' ); ?></button>
                </form>
                <form data-auth-form="register" class="pds-form" hidden>
                    <label>Seu nome<input name="name" autocomplete="name" maxlength="150" placeholder="Como podemos chamar você?" required></label>
                    <label>E-mail<input name="email" type="email" autocomplete="email" maxlength="100" placeholder="voce@exemplo.com" required></label>
                    <label>Crie uma senha<input name="password" type="password" autocomplete="new-password" minlength="10" maxlength="200" placeholder="Pelo menos 10 caracteres" required></label>
                    <label>Confirme a senha<input name="password_confirm" type="password" autocomplete="new-password" minlength="10" maxlength="200" required></label>
                    <label class="pds-trap" aria-hidden="true">Site<input name="website" tabindex="-1" autocomplete="off"></label>
                    <?php if ( get_privacy_policy_url() ) : ?><label class="pds-check"><input type="checkbox" name="accepted_policy" value="1" required> <span>Li a <a href="<?php echo esc_url( get_privacy_policy_url() ); ?>" target="_blank" rel="noopener">política de privacidade</a>.</span></label><?php endif; ?>
                    <button class="pds-button pds-button-wide" type="submit">Criar minha conta <?php echo PDS_Core::icon( 'arrow' ); ?></button>
                </form>
                <form data-auth-form="forgot" class="pds-form" hidden>
                    <label>E-mail da sua conta<input name="email" type="email" autocomplete="email" maxlength="100" required></label>
                    <button class="pds-button pds-button-wide" type="submit">Enviar link de recuperação</button>
                    <button type="button" class="pds-text-button" data-auth-view="login">Voltar para entrar</button>
                </form>
                <form data-auth-form="reset" class="pds-form" hidden>
                    <label>Nova senha<input name="password" type="password" autocomplete="new-password" minlength="10" maxlength="200" required></label>
                    <label>Confirme a nova senha<input name="password_confirm" type="password" autocomplete="new-password" minlength="10" maxlength="200" required></label>
                    <button class="pds-button pds-button-wide" type="submit">Salvar nova senha</button>
                    <button type="button" class="pds-text-button" data-auth-view="forgot">Solicitar outro link</button>
                </form>
                <p class="pds-auth-note">Uma conta para todos os seus serviços.</p>
            </div>
        </main>
    </div>
    <?php else : $user = wp_get_current_user(); ?>
    <div class="pds-shell">
        <aside class="pds-sidebar">
            <a class="pds-brand" href="<?php echo esc_url( pds_portal_url() ); ?>"><span class="pds-brand-symbol"><?php echo PDS_Core::icon( 'grid' ); ?></span><span><?php echo esc_html( $brand ); ?></span></a>
            <div class="pds-sidebar-label">SEU ESPAÇO</div>
            <nav class="pds-nav" aria-label="Navegação principal">
                <a href="<?php echo esc_url( pds_portal_url() ); ?>" <?php echo ! $service ? 'aria-current="page"' : ''; ?>><?php echo PDS_Core::icon( 'grid' ); ?> Visão geral</a>
                <?php foreach ( $services as $slug => $module ) : if ( in_array( $slug, $settings['disabled_services'] ?? array(), true ) ) { continue; } ?>
                <a href="<?php echo esc_url( pds_portal_url( $slug ) ); ?>" <?php echo $service === $slug ? 'aria-current="page"' : ''; ?>><?php echo PDS_Core::icon( $module['icon'] ); ?> <?php echo esc_html( $module['title'] ); ?></a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url( pds_portal_url( 'conta' ) ); ?>" <?php echo $service === 'conta' ? 'aria-current="page"' : ''; ?>><?php echo PDS_Core::icon( 'user' ); ?> Minha conta</a>
            </nav>
            <div class="pds-sidebar-bottom"><span class="pds-private-note"><?php echo PDS_Core::icon( 'shield' ); ?> Seu espaço pessoal</span>
                <div class="pds-user-row"><span class="pds-avatar"><?php echo esc_html( strtoupper( function_exists( 'mb_substr' ) ? mb_substr( $user->display_name, 0, 1 ) : substr( $user->display_name, 0, 1 ) ) ); ?></span><div><strong><?php echo esc_html( $user->display_name ); ?></strong><span>Minha conta</span></div><button type="button" class="pds-icon-button" data-action="logout" aria-label="Sair da conta" title="Sair"><?php echo PDS_Core::icon( 'logout' ); ?></button></div>
            </div>
        </aside>
        <div class="pds-workspace">
            <header class="pds-topbar"><span>Seu portal <span class="pds-crumb">/ <?php echo esc_html( $service === 'conta' ? 'Minha conta' : ( $services[ $service ]['title'] ?? 'Visão geral' ) ); ?></span></span><div><span class="pds-date" data-current-date></span><button type="button" class="pds-icon-button pds-mobile-logout" data-action="logout" aria-label="Sair da conta"><?php echo PDS_Core::icon( 'logout' ); ?></button></div></header>
            <main class="pds-main" id="pds-main">
                <?php if ( get_user_meta( $user->ID, 'pds_suspended', true ) ) : ?>
                    <div class="pds-alert">O acesso aos serviços desta conta está suspenso. Entre em contato com o responsável pelo portal.</div>
                <?php endif; ?>
                <?php if ( ! $service ) : ?>
                <section class="pds-welcome"><span class="pds-eyebrow">SEU DIA, MAIS ORGANIZADO</span><h1>Olá, <?php echo esc_html( explode( ' ', trim( $user->display_name ) )[0] ); ?> <span class="pds-wave">✦</span></h1><p>O que vamos cuidar hoje?</p></section>
                <div class="pds-section-heading"><h2>Seus serviços</h2><span class="pds-tag">Tudo em um lugar</span></div>
                <div class="pds-service-grid">
                <?php foreach ( $services as $slug => $module ) : if ( in_array( $slug, $settings['disabled_services'] ?? array(), true ) ) { continue; } $allowed = pds_user_can_access_service( $slug ); ?>
                    <article class="pds-service-card <?php echo $allowed ? '' : 'pds-service-locked'; ?>"><div class="pds-service-top"><span class="pds-service-icon"><?php echo PDS_Core::icon( $module['icon'] ); ?></span><span class="pds-status"><?php echo $allowed ? 'Disponível' : 'Acesso não liberado'; ?></span></div><h3><?php echo esc_html( $module['title'] ); ?></h3><p><?php echo esc_html( $module['description'] ); ?></p>
                    <?php if ( 'combustivel' === $slug ) : ?><div class="pds-feature-tags"><span>Veículos</span><span>Consumo</span><span>Relatórios</span></div><?php endif; ?>
                    <?php if ( $allowed ) : ?><a class="pds-service-link" href="<?php echo esc_url( pds_portal_url( $slug ) ); ?>">Acessar serviço <?php echo PDS_Core::icon( 'arrow' ); ?></a><?php else : ?><span class="pds-service-link">Consulte o responsável pelo portal</span><?php endif; ?></article>
                <?php endforeach; ?>
                </div>
                <section class="pds-account-banner"><span class="pds-banner-icon"><?php echo PDS_Core::icon( 'user' ); ?></span><div><h3>Sua conta acompanha você.</h3><p>Mantenha seus dados atualizados e acesse todos os serviços com o mesmo login.</p></div><a href="<?php echo esc_url( pds_portal_url( 'conta' ) ); ?>" class="pds-button pds-button-secondary">Minha conta <?php echo PDS_Core::icon( 'arrow' ); ?></a></section>
                <?php elseif ( 'conta' === $service ) : ?>
                    <div class="pds-page-heading"><div><span class="pds-eyebrow">SEU PERFIL</span><h1>Minha conta</h1><p>Um único cadastro para seus serviços.</p></div></div>
                    <section class="pds-card pds-account-card"><h2>Dados da conta</h2><form id="pds-account-form" class="pds-form"><label>Nome<input name="name" value="<?php echo esc_attr( $user->display_name ); ?>" maxlength="150" autocomplete="name" required></label><label>E-mail<input name="email" type="email" value="<?php echo esc_attr( $user->user_email ); ?>" maxlength="100" autocomplete="email" required></label><hr><h3>Alterar senha</h3><p class="pds-muted">Para mudar o e-mail ou a senha, informe sua senha atual.</p><label>Senha atual<input name="current_password" type="password" autocomplete="current-password" maxlength="200"></label><label>Nova senha <span class="pds-muted">(opcional)</span><input name="new_password" type="password" autocomplete="new-password" minlength="10" maxlength="200" placeholder="Pelo menos 10 caracteres"></label><div class="pds-form-error pds-alert" role="alert" hidden></div><button type="submit" class="pds-button">Salvar alterações</button></form></section>
                    <?php do_action( 'pds_account_after' ); ?>
                <?php elseif ( isset( $services[ $service ] ) && pds_user_can_access_service( $service ) ) : ?>
                    <?php call_user_func( $services[ $service ]['render_callback'] ); ?>
                <?php else : ?>
                    <div class="pds-empty"><span class="pds-empty-icon"><?php echo PDS_Core::icon( 'shield' ); ?></span><h1>Serviço indisponível</h1><p>Este serviço não está disponível para sua conta.</p><a class="pds-button" href="<?php echo esc_url( pds_portal_url() ); ?>">Voltar ao portal</a></div>
                <?php endif; ?>
                <footer class="pds-footer"><?php echo esc_html( $brand ); ?> <span>Uma rotina mais simples.</span><?php if ( get_privacy_policy_url() ) : ?><a href="<?php echo esc_url( get_privacy_policy_url() ); ?>">Privacidade</a><?php endif; ?></footer>
            </main>
        </div>
    </div>
    <?php endif; ?>
</div>
