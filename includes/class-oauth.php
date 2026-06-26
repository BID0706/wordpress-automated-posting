<?php
/**
 * OAuth 2.0 Authorization Server for the MCP endpoint.
 *
 * Supports: Authorization Code grant with PKCE (S256), Refresh Token rotation.
 * Only active when Settings → Auth & Roles → OAuth Mode = Built-in.
 *
 * Endpoints:
 *   GET/POST /wp-json/ille/v2/oauth/authorize   — consent screen
 *   POST     /wp-json/ille/v2/oauth/token        — token exchange & refresh
 *   POST     /wp-json/ille/v2/oauth/register     — dynamic client registration (opt-in)
 *   GET      /.well-known/oauth-authorization-server — discovery document
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ILLE_PG_OAuth {

    private const TRANSIENT_CODE_TTL    = 600;       // 10 minutes
    private const TRANSIENT_TOKEN_TTL   = 3600;      // 1 hour
    private const TRANSIENT_REFRESH_TTL = 2592000;   // 30 days

    public function __construct() {
        add_action( 'rest_api_init',     [ $this, 'register_rest_routes' ] );
        add_action( 'init',              [ $this, 'register_well_known_route' ] );
        add_action( 'template_redirect', [ $this, 'serve_well_known' ] );
        add_filter( 'rest_post_dispatch', [ $this, 'inject_www_authenticate_header' ], 10, 3 );
    }

    // =========================================================================
    // .well-known discovery document
    // =========================================================================

    public function register_well_known_route(): void {
        add_rewrite_rule(
            '^\.well-known/oauth-authorization-server/?$',
            'index.php?ille_pg_oauth_metadata=1',
            'top'
        );
        // RFC 9728: Protected Resource Metadata — ChatGPT's MCP client reads this first
        add_rewrite_rule(
            '^\.well-known/oauth-protected-resource/?$',
            'index.php?ille_pg_oauth_resource=1',
            'top'
        );
        add_filter( 'query_vars', function ( array $vars ): array {
            $vars[] = 'ille_pg_oauth_metadata';
            $vars[] = 'ille_pg_oauth_resource';
            return $vars;
        } );
    }

    public function serve_well_known(): void {
        // Authorization Server Metadata (RFC 8414)
        if ( get_query_var( 'ille_pg_oauth_metadata' ) ) {
            $ns = ILLE_PG_Settings::get_rest_namespace();
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode( [
                'issuer'                                => get_bloginfo( 'url' ),
                'authorization_endpoint'                => rest_url( $ns . '/oauth/authorize' ),
                'token_endpoint'                        => rest_url( $ns . '/oauth/token' ),
                'registration_endpoint'                 => rest_url( $ns . '/oauth/register' ),
                'scopes_supported'                      => [ 'mcp' ],
                'response_types_supported'              => [ 'code' ],
                'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
                'code_challenge_methods_supported'      => [ 'S256' ],
                'token_endpoint_auth_methods_supported' => [ 'client_secret_post', 'none' ],
            ] );
            exit;
        }

        // Protected Resource Metadata (RFC 9728) — required by ChatGPT MCP client
        if ( get_query_var( 'ille_pg_oauth_resource' ) ) {
            $ns = ILLE_PG_Settings::get_rest_namespace();
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode( [
                'resource'              => rest_url( $ns . '/mcp' ),
                'authorization_servers' => [ get_bloginfo( 'url' ) ],
                'scopes_supported'      => [ 'mcp' ],
                'bearer_methods_supported' => [ 'header' ],
            ] );
            exit;
        }
    }

    // =========================================================================
    // REST route registration
    // =========================================================================

    public function register_rest_routes(): void {
        $ns = ILLE_PG_Settings::get_rest_namespace();

        register_rest_route( $ns, '/oauth/authorize', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_authorize_get' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_authorize_post' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        register_rest_route( $ns, '/oauth/token', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_token' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/oauth/register', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_dynamic_register' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // =========================================================================
    // WWW-Authenticate header injection on 401 responses
    // =========================================================================

    public function inject_www_authenticate_header( $response, $server, $request ) {
        if ( $response instanceof WP_REST_Response && $response->get_status() === 401 ) {
            $resource_metadata_url = get_bloginfo( 'url' ) . '/.well-known/oauth-protected-resource';
            $response->header(
                'WWW-Authenticate',
                'Bearer realm="ille-pg", resource_metadata="' . $resource_metadata_url . '"'
            );
        }
        return $response;
    }

    // =========================================================================
    // Authorization endpoint — GET (display consent screen)
    // =========================================================================

    public function handle_authorize_get( WP_REST_Request $request ) {
        $params = $this->extract_authorize_params( $request->get_query_params() );
        if ( is_wp_error( $params ) ) {
            return $this->authorize_error_response( $params->get_error_message() );
        }

        // WordPress REST API does not honour session cookies without a WP nonce,
        // so is_user_logged_in() returns false even after a successful wp-login
        // redirect. Read the auth cookie directly to avoid the login loop.
        $user_id = is_user_logged_in()
            ? get_current_user_id()
            : wp_validate_auth_cookie( '', 'logged_in' );

        if ( ! $user_id ) {
            $current_url = rest_url( ILLE_PG_Settings::get_rest_namespace() . '/oauth/authorize' );
            $current_url = add_query_arg( $request->get_query_params(), $current_url );
            wp_redirect( wp_login_url( $current_url ) );
            exit;
        }

        wp_set_current_user( $user_id ); // ensure current user is set for nonce generation
        $user = get_userdata( $user_id );
        if ( ! $this->user_has_allowed_role( $user ) ) {
            return $this->authorize_error_response( 'Your account does not have permission to authorize this application.' );
        }

        $this->render_consent_screen( $params, $user );
        exit;
    }

    // =========================================================================
    // Authorization endpoint — POST (process consent)
    // =========================================================================

    public function handle_authorize_post( WP_REST_Request $request ) {
        // Same cookie-direct auth as the GET handler
        $user_id = is_user_logged_in()
            ? get_current_user_id()
            : wp_validate_auth_cookie( '', 'logged_in' );

        if ( ! $user_id ) {
            return $this->authorize_error_response( 'You must be logged in to authorize.' );
        }

        wp_set_current_user( $user_id );

        // Verify nonce (must happen after wp_set_current_user so WP knows who to check against)
        $nonce = $request->get_param( '_ille_oauth_nonce' ) ?: '';
        if ( ! wp_verify_nonce( $nonce, 'ille_pg_oauth_consent' ) ) {
            return $this->authorize_error_response( 'Security check failed. Please try again.' );
        }

        $user = get_userdata( $user_id );
        if ( ! $this->user_has_allowed_role( $user ) ) {
            return $this->authorize_error_response( 'Your account does not have permission.' );
        }

        // Re-validate params from POST body (never trust without re-checking)
        $params = $this->extract_authorize_params( $request->get_body_params() );
        if ( is_wp_error( $params ) ) {
            return $this->authorize_error_response( $params->get_error_message() );
        }

        // User denied
        if ( $request->get_param( 'action' ) === 'deny' ) {
            $redirect = add_query_arg( [
                'error' => 'access_denied',
                'state' => $params['state'],
            ], $params['redirect_uri'] );
            wp_redirect( $redirect );
            exit;
        }

        // User approved — issue authorization code
        $code     = bin2hex( random_bytes( 16 ) );
        $code_key = 'ille_pg_authcode_' . hash( 'sha256', $code );
        set_transient( $code_key, [
            'client_id'             => $params['client_id'],
            'user_id'               => $user->ID,
            'redirect_uri'          => $params['redirect_uri'],
            'scope'                 => $params['scope'],
            'code_challenge'        => $params['code_challenge'],
            'code_challenge_method' => $params['code_challenge_method'],
            'state'                 => $params['state'],
        ], self::TRANSIENT_CODE_TTL );

        $redirect = add_query_arg( [
            'code'  => $code,
            'state' => $params['state'],
        ], $params['redirect_uri'] );
        wp_redirect( $redirect );
        exit;
    }

    // =========================================================================
    // Token endpoint — authorization_code and refresh_token grant types
    // =========================================================================

    public function handle_token( WP_REST_Request $request ): WP_REST_Response {
        $grant_type = $request->get_param( 'grant_type' );

        if ( $grant_type === 'authorization_code' ) {
            return $this->grant_authorization_code( $request );
        }

        if ( $grant_type === 'refresh_token' ) {
            return $this->grant_refresh_token( $request );
        }

        return new WP_REST_Response(
            [ 'error' => 'unsupported_grant_type', 'error_description' => 'Supported: authorization_code, refresh_token.' ],
            400
        );
    }

    private function grant_authorization_code( WP_REST_Request $request ): WP_REST_Response {
        $client_id     = (string) $request->get_param( 'client_id' );
        $client_secret = (string) $request->get_param( 'client_secret' );
        $code          = (string) $request->get_param( 'code' );
        $redirect_uri  = (string) $request->get_param( 'redirect_uri' );
        $code_verifier = (string) $request->get_param( 'code_verifier' );

        // Validate client exists
        $client = ILLE_PG_Settings::get_oauth_client( $client_id );
        if ( ! $client ) {
            return $this->token_error( 'invalid_client', 'Unknown client_id.' );
        }

        // Retrieve and validate auth code before client secret check (so we can
        // determine whether this is a PKCE-only (public) client exchange)
        $code_key = 'ille_pg_authcode_' . hash( 'sha256', $code );
        $stored   = get_transient( $code_key );

        if ( ! $stored ) {
            return $this->token_error( 'invalid_grant', 'Authorization code expired or invalid.' );
        }

        // Always delete immediately — one use only
        delete_transient( $code_key );

        if ( ! hash_equals( $stored['client_id'], $client_id ) ) {
            return $this->token_error( 'invalid_grant', 'Client ID mismatch.' );
        }

        if ( ! hash_equals( $stored['redirect_uri'], $redirect_uri ) ) {
            return $this->token_error( 'invalid_grant', 'Redirect URI mismatch.' );
        }

        // PKCE verification (required when challenge was stored)
        $pkce_verified = false;
        if ( ! empty( $stored['code_challenge'] ) ) {
            if ( empty( $code_verifier ) ) {
                return $this->token_error( 'invalid_grant', 'code_verifier required.' );
            }
            $computed = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
            if ( ! hash_equals( $stored['code_challenge'], $computed ) ) {
                return $this->token_error( 'invalid_grant', 'PKCE verification failed.' );
            }
            $pkce_verified = true;
        }

        // Client authentication: require client_secret unless PKCE was used (public client)
        if ( ! $pkce_verified ) {
            if ( ! password_verify( $client_secret, $client['client_secret_hash'] ) ) {
                return $this->token_error( 'invalid_client', 'Invalid client credentials.' );
            }
        }

        return $this->issue_token_pair( (int) $stored['user_id'], $client_id, $stored['scope'] );
    }

    private function grant_refresh_token( WP_REST_Request $request ): WP_REST_Response {
        $client_id     = (string) $request->get_param( 'client_id' );
        $client_secret = (string) $request->get_param( 'client_secret' );
        $refresh_token = (string) $request->get_param( 'refresh_token' );

        $client = ILLE_PG_Settings::get_oauth_client( $client_id );
        if ( ! $client ) {
            return $this->token_error( 'invalid_client', 'Unknown client_id.' );
        }

        // Allow refresh without client_secret if client was originally authorized via PKCE
        if ( ! empty( $client_secret ) && ! password_verify( $client_secret, $client['client_secret_hash'] ) ) {
            return $this->token_error( 'invalid_client', 'Invalid client credentials.' );
        }

        $refresh_key = 'ille_pg_refresh_' . hash( 'sha256', $refresh_token );
        $stored      = get_transient( $refresh_key );

        if ( ! $stored ) {
            return $this->token_error( 'invalid_grant', 'Refresh token expired, invalid, or already used.' );
        }

        // Rotate — delete old refresh token immediately
        delete_transient( $refresh_key );

        if ( ! hash_equals( $stored['client_id'], $client_id ) ) {
            return $this->token_error( 'invalid_grant', 'Client ID mismatch.' );
        }

        return $this->issue_token_pair( (int) $stored['user_id'], $client_id, $stored['scope'] );
    }

    private function issue_token_pair( int $user_id, string $client_id, string $scope ): WP_REST_Response {
        $access_token  = bin2hex( random_bytes( 32 ) );
        $refresh_token = bin2hex( random_bytes( 32 ) );
        $payload       = [ 'user_id' => $user_id, 'client_id' => $client_id, 'scope' => $scope, 'issued_at' => time() ];

        set_transient( 'ille_pg_token_'   . hash( 'sha256', $access_token ),  $payload, self::TRANSIENT_TOKEN_TTL );
        set_transient( 'ille_pg_refresh_' . hash( 'sha256', $refresh_token ), $payload, self::TRANSIENT_REFRESH_TTL );

        return new WP_REST_Response( [
            'access_token'  => $access_token,
            'token_type'    => 'bearer',
            'expires_in'    => self::TRANSIENT_TOKEN_TTL,
            'refresh_token' => $refresh_token,
            'scope'         => $scope,
        ], 200 );
    }

    // =========================================================================
    // Dynamic Client Registration (RFC 7591) — disabled by default
    // =========================================================================

    public function handle_dynamic_register( WP_REST_Request $request ): WP_REST_Response {
        if ( ! ILLE_PG_Settings::get( 'ille_pg_oauth_open_registration', false ) ) {
            return new WP_REST_Response(
                [ 'error' => 'access_denied', 'error_description' => 'Dynamic registration is disabled on this server.' ],
                403
            );
        }

        $body          = $request->get_json_params() ?: [];
        $redirect_uris = array_filter( (array) ( $body['redirect_uris'] ?? [] ), 'is_string' );
        $client_name   = sanitize_text_field( $body['client_name'] ?? '' );

        if ( empty( $redirect_uris ) || empty( $client_name ) ) {
            return new WP_REST_Response(
                [ 'error' => 'invalid_client_metadata', 'error_description' => 'redirect_uris and client_name are required.' ],
                400
            );
        }

        foreach ( $redirect_uris as $uri ) {
            if ( ! $this->is_valid_redirect_uri( $uri ) ) {
                return new WP_REST_Response(
                    [ 'error' => 'invalid_redirect_uri', 'error_description' => "Invalid redirect URI: {$uri}" ],
                    400
                );
            }
        }

        $client_id     = 'ille_' . bin2hex( random_bytes( 8 ) );
        $client_secret = bin2hex( random_bytes( 32 ) );
        $clients       = ILLE_PG_Settings::get_oauth_clients();
        $clients[]     = [
            'client_id'          => $client_id,
            'client_secret_hash' => password_hash( $client_secret, PASSWORD_BCRYPT ),
            'name'               => $client_name,
            'redirect_uris'      => array_values( $redirect_uris ),
            'created_at'         => gmdate( 'c' ),
            'created_by'         => 0,
        ];
        ILLE_PG_Settings::save_oauth_clients( $clients );

        return new WP_REST_Response( [
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'client_name'   => $client_name,
            'redirect_uris' => array_values( $redirect_uris ),
        ], 201 );
    }

    // =========================================================================
    // Static helper: validate a Bearer token and return the WP_User
    // =========================================================================

    public static function resolve_bearer_token( string $raw_token ): WP_User|false {
        if ( empty( $raw_token ) ) {
            return false;
        }
        $data = get_transient( 'ille_pg_token_' . hash( 'sha256', $raw_token ) );
        if ( ! $data || empty( $data['user_id'] ) ) {
            return false;
        }
        $user = get_userdata( (int) $data['user_id'] );
        return ( $user instanceof WP_User ) ? $user : false;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function extract_authorize_params( array $params ): array|WP_Error {
        $client_id    = sanitize_text_field( $params['client_id']    ?? '' );
        $redirect_uri = esc_url_raw( $params['redirect_uri'] ?? '' );
        $response_type = sanitize_text_field( $params['response_type'] ?? '' );
        $scope        = sanitize_text_field( $params['scope'] ?? 'mcp' );
        $state        = sanitize_text_field( $params['state'] ?? '' );
        $code_challenge        = sanitize_text_field( $params['code_challenge'] ?? '' );
        $code_challenge_method = sanitize_text_field( $params['code_challenge_method'] ?? '' );

        if ( empty( $client_id ) ) {
            return new WP_Error( 'invalid_request', 'client_id is required.' );
        }

        $client = ILLE_PG_Settings::get_oauth_client( $client_id );
        if ( ! $client ) {
            return new WP_Error( 'invalid_client', 'Unknown client_id.' );
        }

        if ( empty( $redirect_uri ) ) {
            return new WP_Error( 'invalid_request', 'redirect_uri is required.' );
        }

        // Exact URI match against registered URIs
        $uri_match = false;
        foreach ( $client['redirect_uris'] as $registered ) {
            if ( hash_equals( $registered, $redirect_uri ) ) {
                $uri_match = true;
                break;
            }
        }
        if ( ! $uri_match ) {
            return new WP_Error( 'invalid_request', 'redirect_uri does not match any registered URI.' );
        }

        if ( $response_type !== 'code' ) {
            return new WP_Error( 'unsupported_response_type', 'Only response_type=code is supported.' );
        }

        // PKCE validation: if challenge provided, method must be S256
        if ( ! empty( $code_challenge ) && $code_challenge_method !== 'S256' ) {
            return new WP_Error( 'invalid_request', 'Only code_challenge_method=S256 is supported.' );
        }

        return [
            'client_id'             => $client_id,
            'client'                => $client,
            'redirect_uri'          => $redirect_uri,
            'scope'                 => $scope,
            'state'                 => $state,
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
        ];
    }

    private function user_has_allowed_role( WP_User $user ): bool {
        foreach ( ILLE_PG_Settings::get_allowed_roles() as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                return true;
            }
        }
        return false;
    }

    private function is_valid_redirect_uri( string $uri ): bool {
        $parsed = wp_parse_url( $uri );
        if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return false;
        }
        if ( $parsed['scheme'] === 'https' ) {
            return true;
        }
        // Allow http://localhost for local development
        return $parsed['scheme'] === 'http' && $parsed['host'] === 'localhost';
    }

    private function token_error( string $error, string $description = '' ): WP_REST_Response {
        $body = [ 'error' => $error ];
        if ( $description ) {
            $body['error_description'] = $description;
        }
        return new WP_REST_Response( $body, 400 );
    }

    private function authorize_error_response( string $message ): WP_REST_Response {
        return new WP_REST_Response( [ 'error' => $message ], 400 );
    }

    // =========================================================================
    // Consent screen HTML (self-contained, no theme dependency)
    // =========================================================================

    private function render_consent_screen( array $params, WP_User $user ): void {
        // WordPress REST API buffers all output and encodes it as JSON.
        // Flush every active buffer so our HTML goes directly to the browser.
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        if ( ! headers_sent() ) {
            header( 'Content-Type: text/html; charset=utf-8' );
            header( 'X-Frame-Options: SAMEORIGIN' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate' );
            status_header( 200 );
        }

        $client_name  = esc_html( $params['client']['name'] );
        $scope        = esc_html( $params['scope'] );
        $action_url   = esc_url( rest_url( ILLE_PG_Settings::get_rest_namespace() . '/oauth/authorize' ) );
        $nonce        = wp_create_nonce( 'ille_pg_oauth_consent' );
        $site_name    = esc_html( get_bloginfo( 'name' ) );
        $user_name    = esc_html( $user->display_name );

        // Hidden fields to pass through
        $hidden_fields = [
            'client_id'             => $params['client_id'],
            'redirect_uri'          => $params['redirect_uri'],
            'response_type'         => 'code',
            'scope'                 => $params['scope'],
            'state'                 => $params['state'],
            'code_challenge'        => $params['code_challenge'],
            'code_challenge_method' => $params['code_challenge_method'],
            '_ille_oauth_nonce'     => $nonce,
        ];

        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Authorize — <?php echo $site_name; ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f0f0f1; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
  .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.12); max-width: 440px; width: 100%; padding: 2rem; }
  .site { font-size: .85rem; color: #666; margin-bottom: 1rem; }
  h1 { font-size: 1.25rem; margin-bottom: .5rem; }
  .app { font-weight: 600; }
  .scope-box { background: #f6f7f7; border-radius: 6px; padding: .75rem 1rem; margin: 1.25rem 0; font-size: .9rem; }
  .scope-box strong { display: block; margin-bottom: .25rem; }
  .user-note { font-size: .85rem; color: #555; margin-bottom: 1.5rem; }
  .actions { display: flex; gap: .75rem; }
  .btn { flex: 1; padding: .6rem 1rem; border: none; border-radius: 5px; font-size: .95rem; cursor: pointer; font-weight: 500; }
  .btn-approve { background: #2271b1; color: #fff; }
  .btn-approve:hover { background: #135e96; }
  .btn-deny { background: #f0f0f1; color: #333; border: 1px solid #ccc; }
  .btn-deny:hover { background: #e0e0e0; }
</style>
</head>
<body>
<div class="card">
  <p class="site"><?php echo $site_name; ?></p>
  <h1><span class="app"><?php echo $client_name; ?></span> wants to access your account</h1>
  <p class="user-note">Signed in as <strong><?php echo $user_name; ?></strong></p>
  <div class="scope-box">
    <strong>Permissions requested:</strong>
    <?php echo $scope === 'mcp' ? 'Access the MCP post generation tools' : esc_html( $scope ); ?>
  </div>
  <form method="POST" action="<?php echo $action_url; ?>">
    <?php foreach ( $hidden_fields as $name => $value ) : ?>
      <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
    <?php endforeach; ?>
    <div class="actions">
      <button type="submit" name="action" value="approve" class="btn btn-approve">Allow</button>
      <button type="submit" name="action" value="deny"    class="btn btn-deny">Deny</button>
    </div>
  </form>
</div>
</body>
</html>
        <?php
    }
}
