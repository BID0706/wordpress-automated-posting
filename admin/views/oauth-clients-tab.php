<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// $oauth_clients is available from settings-page.php scope
?>

<div class="ille-pg-card">
    <div class="ille-pg-card__header">
        <h2>OAuth Clients</h2>
        <span class="ille-pg-badge">Built-in mode</span>
    </div>
    <p class="ille-pg-hint">Register clients (ChatGPT, Groq, etc.) to allow them to authenticate via OAuth 2.0. The client secret is shown once on creation — copy it immediately.</p>

    <?php /* Registered clients table */ ?>
    <div id="ille-oauth-clients-list">
    <?php if ( empty( $oauth_clients ) ) : ?>
        <p class="ille-pg-hint" id="ille-oauth-no-clients">No OAuth clients registered yet.</p>
    <?php else : ?>
        <table class="ille-pg-table" id="ille-oauth-clients-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Client ID</th>
                    <th>Redirect URIs</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $oauth_clients as $client ) :
                $created_display = ! empty( $client['created_at'] ) ? date( 'M j, Y', strtotime( $client['created_at'] ) ) : '—';
            ?>
                <tr data-client-id="<?php echo esc_attr( $client['client_id'] ); ?>">
                    <td><?php echo esc_html( $client['name'] ); ?></td>
                    <td><code><?php echo esc_html( $client['client_id'] ); ?></code></td>
                    <td>
                        <?php foreach ( $client['redirect_uris'] as $uri ) : ?>
                            <div><code><?php echo esc_html( $uri ); ?></code></div>
                        <?php endforeach; ?>
                    </td>
                    <td><?php echo esc_html( $created_display ); ?></td>
                    <td>
                        <button type="button"
                            class="ille-pg-btn ille-pg-btn--sm ille-pg-btn--danger ille-oauth-revoke-btn"
                            data-client-id="<?php echo esc_attr( $client['client_id'] ); ?>"
                            data-client-name="<?php echo esc_attr( $client['name'] ); ?>">
                            Revoke
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>

    <?php /* One-time secret notice — populated by JS after registration */ ?>
    <div id="ille-oauth-new-client-notice" class="ille-pg-notice ille-pg-notice--success" hidden>
        <strong>Client registered.</strong> Copy the secret now — it will not be shown again.<br>
        <div class="ille-pg-field" style="margin-top:.75rem">
            <label class="ille-pg-label">Client ID</label>
            <div class="ille-pg-copy-row">
                <code class="ille-pg-code" id="ille-oauth-new-client-id"></code>
                <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-copy-btn" data-copy="ille-oauth-new-client-id">Copy</button>
            </div>
        </div>
        <div class="ille-pg-field">
            <label class="ille-pg-label">Client Secret</label>
            <div class="ille-pg-copy-row">
                <code class="ille-pg-code" id="ille-oauth-new-client-secret"></code>
                <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-copy-btn" data-copy="ille-oauth-new-client-secret">Copy</button>
            </div>
        </div>
    </div>

    <hr class="ille-pg-divider">

    <?php /* Register new client form */ ?>
    <div>
        <h3 style="margin-bottom:.75rem;font-size:1rem">Register New Client</h3>
        <div class="ille-pg-field">
            <label class="ille-pg-label" for="ille-oauth-new-name">Client Name</label>
            <input type="text" id="ille-oauth-new-name" class="ille-pg-input" placeholder="e.g. ChatGPT Action" />
        </div>
        <div class="ille-pg-field">
            <label class="ille-pg-label" for="ille-oauth-new-uris">
                Redirect URIs
                <span class="ille-pg-label__hint">One per line. Must use HTTPS (or http://localhost for testing).</span>
            </label>
            <textarea id="ille-oauth-new-uris" class="ille-pg-input" rows="3"
                placeholder="https://chat.openai.com/aip/g-xxx/oauth/callback"></textarea>
        </div>
        <button type="button" id="ille-oauth-register-btn" class="ille-pg-btn ille-pg-btn--primary">Register Client</button>
        <span id="ille-oauth-register-msg" class="ille-pg-hint" style="margin-left:.5rem"></span>
    </div>

    <?php /* Discovery endpoint info */ ?>
    <hr class="ille-pg-divider">
    <div class="ille-pg-field">
        <label class="ille-pg-label">Discovery Document URL</label>
        <div class="ille-pg-copy-row">
            <code class="ille-pg-code" id="ille-oauth-discovery-url"><?php echo esc_url( get_bloginfo( 'url' ) . '/.well-known/oauth-authorization-server' ); ?></code>
            <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-copy-btn" data-copy="ille-oauth-discovery-url">Copy</button>
        </div>
        <p class="ille-pg-hint">Paste this URL into ChatGPT / Groq GPT Action settings as the "OAuth Discovery URL" or use the individual endpoints below.</p>
    </div>
    <div class="ille-pg-field">
        <label class="ille-pg-label">Authorization Endpoint</label>
        <div class="ille-pg-copy-row">
            <code class="ille-pg-code" id="ille-oauth-auth-url"><?php echo esc_url( rest_url( ILLE_PG_Settings::get_rest_namespace() . '/oauth/authorize' ) ); ?></code>
            <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-copy-btn" data-copy="ille-oauth-auth-url">Copy</button>
        </div>
    </div>
    <div class="ille-pg-field">
        <label class="ille-pg-label">Token Endpoint</label>
        <div class="ille-pg-copy-row">
            <code class="ille-pg-code" id="ille-oauth-token-url"><?php echo esc_url( rest_url( ILLE_PG_Settings::get_rest_namespace() . '/oauth/token' ) ); ?></code>
            <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-copy-btn" data-copy="ille-oauth-token-url">Copy</button>
        </div>
    </div>
</div>
