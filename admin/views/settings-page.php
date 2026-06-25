<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$endpoint_url = ILLE_PG_Settings::get_endpoint_url();
$schedules    = ILLE_PG_Settings::get_schedules();
$next_runs    = ILLE_PG_Scheduler::get_next_runs();
$models       = ILLE_PG_Settings::get_available_models();
$active_model = ILLE_PG_Settings::get( ILLE_PG_Settings::KEY_ACTIVE_MODEL, 'gemini-2.0-flash' );
$all_roles    = wp_roles()->get_names();
$saved_roles  = ILLE_PG_Settings::get_allowed_roles();
$saved_params = ILLE_PG_Settings::get_allowed_params();

// Pad schedules to MAX
while ( count( $schedules ) < ILLE_PG_Settings::MAX_SCHEDULES ) {
    $schedules[] = [
        'enabled'     => false,
        'label'       => '',
        'days'        => [],
        'time'        => '08:00',
        'topic'       => '',
        'post_status' => 'publish',
    ];
}
?>

<div class="ille-pg-wrap">
    <div class="ille-pg-header">
        <div class="ille-pg-header__logo">
            <span class="ille-pg-header__icon">✦</span>
            <div>
                <h1>Settings</h1>
                <p>Configure your Post Generator</p>
            </div>
        </div>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ille-pg' ) ); ?>" class="ille-pg-btn ille-pg-btn--ghost">
            ← Generate Post
        </a>
    </div>

    <!-- Tab Nav -->
    <div class="ille-pg-tabs" role="tablist">
        <button class="ille-pg-tab active" data-tab="endpoint" role="tab">Endpoint</button>
        <button class="ille-pg-tab" data-tab="auth"     role="tab">Auth & Roles</button>
        <button class="ille-pg-tab" data-tab="ai"       role="tab">AI Models</button>
        <button class="ille-pg-tab" data-tab="prompts"  role="tab">Prompts</button>
        <button class="ille-pg-tab" data-tab="schedule" role="tab">Schedules</button>
        <?php if ( current_user_can( 'manage_options' ) ) : ?>
        <button class="ille-pg-tab" data-tab="log"      role="tab">Activity Log</button>
        <?php endif; ?>
    </div>

    <form id="ille-pg-settings-form">

        <!-- ================================================================
             TAB: ENDPOINT
        ================================================================ -->
        <div class="ille-pg-tab-panel active" data-panel="endpoint">

            <!-- Active Endpoint Status -->
            <div class="ille-pg-card ille-pg-endpoint-status">
                <div class="ille-pg-card__header">
                    <h2>Active Endpoint</h2>
                    <span class="ille-pg-endpoint-indicator" id="ille-endpoint-status-dot"></span>
                </div>
                <div class="ille-pg-copy-row">
                    <code class="ille-pg-code" id="ille-active-endpoint-url"><?php echo esc_html( $endpoint_url ); ?></code>
                    <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-copy-btn" data-copy="ille-active-endpoint-url">Copy</button>
                    <button type="button" id="ille-test-endpoint" class="ille-pg-btn ille-pg-btn--sm">Test</button>
                </div>
                <p class="ille-pg-hint" id="ille-endpoint-test-result"></p>
            </div>

            <div class="ille-pg-card">
                <div class="ille-pg-card__header"><h2>Endpoint Configuration</h2></div>

                <div class="ille-pg-field">
                    <label class="ille-pg-label">Default Endpoint URL</label>
                    <div class="ille-pg-copy-row">
                        <code class="ille-pg-code" id="ille-endpoint-display"><?php echo esc_html( $endpoint_url ); ?></code>
                        <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-copy-btn" data-copy="ille-endpoint-display">Copy</button>
                    </div>
                </div>

                <div class="ille-pg-field">
                    <label class="ille-pg-label" for="ille-custom-endpoint">
                        Custom Endpoint Slug
                        <span class="ille-pg-label__hint">Override the default route slug (leave blank for default)</span>
                    </label>
                    <div class="ille-pg-copy-row">
                        <input
                            type="text"
                            id="ille-custom-endpoint"
                            class="ille-pg-input"
                            name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_CUSTOM_ENDPOINT ); ?>]"
                            value="<?php echo esc_attr( ILLE_PG_Settings::get( ILLE_PG_Settings::KEY_CUSTOM_ENDPOINT, '' ) ); ?>"
                            placeholder="e.g. my-custom-generate"
                        />
                        <?php if ( ILLE_PG_Settings::get( ILLE_PG_Settings::KEY_CUSTOM_ENDPOINT, '' ) ) : ?>
                            <button type="button" id="ille-reset-endpoint" class="ille-pg-btn ille-pg-btn--sm ille-pg-btn--danger">Reset to default</button>
                        <?php endif; ?>
                    </div>
                    <p class="ille-pg-hint">Result: <code><?php echo esc_html( rest_url( ILLE_PG_Settings::get_rest_namespace() . '/' ) ); ?><span id="ille-slug-preview"><?php echo esc_html( ILLE_PG_Settings::get_rest_route() ); ?></span></code></p>
                    <p class="ille-pg-hint">Default: <code>generate-post</code></p>
                </div>

                <div class="ille-pg-field">
                    <label class="ille-pg-label">Allowed Endpoint Parameters</label>
                    <div class="ille-pg-checks">
                        <?php
                        $param_options = [
                            'topic'          => 'topic — Blog title / topic hint',
                            'publish'        => 'publish — Override publish status',
                            'focus_keyword'  => 'focus_keyword — SEO focus keyword',
                            'featured_image' => 'featured_image — Toggle image generation',
                        ];
                        foreach ( $param_options as $val => $lbl ) :
                            $checked = in_array( $val, $saved_params, true ) ? 'checked' : '';
                        ?>
                            <label class="ille-pg-check">
                                <input type="checkbox"
                                    name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_ALLOWED_PARAMS ); ?>][]"
                                    value="<?php echo esc_attr( $val ); ?>"
                                    <?php echo $checked; ?> />
                                <span class="ille-pg-check__box"></span>
                                <code><?php echo esc_html( $lbl ); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ille-pg-field">
                    <label class="ille-pg-label">Usage Examples</label>
                    <div class="ille-pg-code-block">
                        <p># Header auth — on-demand with topic:</p>
                        <code>curl -H "X-API-Key: YOUR_KEY" "<?php echo esc_html( $endpoint_url ); ?>?topic=How+to+save+money"</code>
                        <p># URL param auth — useful for no-code tools &amp; browser testing:</p>
                        <code><?php echo esc_html( $endpoint_url ); ?>?x-api-key=YOUR_KEY&amp;topic=How+to+save+money</code>
                        <p># Generate a draft:</p>
                        <code>curl -H "X-API-Key: YOUR_KEY" "<?php echo esc_html( $endpoint_url ); ?>?publish=false"</code>
                        <p># With focus keyword:</p>
                        <code>curl -H "X-API-Key: YOUR_KEY" "<?php echo esc_html( $endpoint_url ); ?>?topic=Budgeting+tips&amp;focus_keyword=save+money"</code>
                        <p># Cron job — every Monday at 8 AM:</p>
                        <code>0 8 * * 1 curl -s -H "X-API-Key: YOUR_KEY" "<?php echo esc_html( $endpoint_url ); ?>"</code>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB: AUTH & ROLES
        ================================================================ -->
        <div class="ille-pg-tab-panel" data-panel="auth">

            <div class="ille-pg-card">
                <div class="ille-pg-card__header"><h2>Allowed Roles</h2></div>
                <p class="ille-pg-hint">Users with these roles can access the admin UI and the REST endpoint. Each user gets their own API key for external access.</p>

                <div class="ille-pg-checks">
                    <?php foreach ( $all_roles as $role_slug => $role_name ) :
                        $checked  = in_array( $role_slug, $saved_roles, true ) ? 'checked' : '';
                        $disabled = ( $role_slug === 'administrator' ) ? 'disabled checked' : $checked;
                    ?>
                        <label class="ille-pg-check">
                            <input type="checkbox"
                                name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_ALLOWED_ROLES ); ?>][]"
                                value="<?php echo esc_attr( $role_slug ); ?>"
                                <?php echo $disabled; ?> />
                            <span class="ille-pg-check__box"></span>
                            <?php echo esc_html( $role_name ); ?>
                            <?php if ( $role_slug === 'administrator' ) echo '<span class="ille-pg-badge">Always allowed</span>'; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ille-pg-card">
                <div class="ille-pg-card__header">
                    <h2>User API Keys</h2>
                    <span class="ille-pg-badge">Per-user · Audit trail</span>
                </div>

                <?php
                $is_admin    = current_user_can( 'manage_options' );
                $current_uid = get_current_user_id();
                $me          = get_userdata( $current_uid );
                $me_key      = ILLE_PG_Settings::get_user_api_key( $current_uid );
                $me_last_raw = get_user_meta( $current_uid, ILLE_PG_Settings::USER_META_API_KEY_LAST, true );
                $me_roles    = array_map( fn( $r ) => ucfirst( $r ), array_intersect( $me->roles, $saved_roles ) );
                $me_key_ex   = ! empty( $me_key );
                ?>

                <?php if ( $is_admin ) : ?>
                    <p class="ille-pg-hint">Manage API keys for all users with allowed roles. Posts created via each key are attributed to that user. Your own key is always shown first.</p>
                <?php else : ?>
                    <p class="ille-pg-hint">Your personal API key for external access to the endpoint. Posts created with this key will be attributed to you.</p>
                <?php endif; ?>

                <div class="ille-pg-user-keys">

                    <?php /* ---- Current user — always pinned at top, server-rendered ---- */ ?>
                    <div class="ille-pg-user-key-row ille-pg-user-key-row--me">
                        <div class="ille-pg-user-key-row__info">
                            <span class="ille-pg-user-key-row__avatar ille-pg-user-key-row__avatar--me">
                                <?php echo esc_html( strtoupper( substr( $me->display_name, 0, 1 ) ) ); ?>
                            </span>
                            <div>
                                <strong>
                                    <?php echo esc_html( $me->display_name ); ?>
                                    <span class="ille-pg-badge ille-pg-badge--ai">You</span>
                                </strong>
                                <span class="ille-pg-user-key-row__meta">
                                    <?php echo esc_html( implode( ', ', $me_roles ) ); ?>
                                    <?php if ( $me_last_raw ) : ?>
                                        · Last used: <?php echo esc_html( date( 'M j, Y g:i A', strtotime( $me_last_raw ) ) ); ?>
                                    <?php elseif ( $me_key_ex ) : ?>
                                        · Never used
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div class="ille-pg-user-key-row__actions">
                            <?php if ( $me_key_ex ) : ?>
                                <input type="text"
                                    id="ille-user-key-<?php echo esc_attr( $current_uid ); ?>"
                                    class="ille-pg-input ille-pg-input--mono ille-pg-user-key-input"
                                    value="<?php echo esc_attr( $me_key ); ?>"
                                    readonly />
                                <button type="button"
                                    class="ille-pg-icon-btn ille-pg-copy-key-icon"
                                    data-copy-input="ille-user-key-<?php echo esc_attr( $current_uid ); ?>"
                                    title="Copy key">
                                    <span class="dashicons dashicons-clipboard"></span>
                                </button>
                            <?php else : ?>
                                <span class="ille-pg-hint" id="ille-no-key-<?php echo esc_attr( $current_uid ); ?>">No key yet</span>
                            <?php endif; ?>
                            <div class="ille-pg-ellipsis-wrap" data-user-id="<?php echo esc_attr( $current_uid ); ?>">
                                <button type="button" class="ille-pg-icon-btn ille-pg-ellipsis-trigger" title="More options">
                                    <span class="dashicons dashicons-ellipsis"></span>
                                </button>
                                <div class="ille-pg-ellipsis-menu" hidden>
                                    <?php if ( $me_key_ex ) : ?>
                                    <button type="button"
                                        class="ille-pg-ellipsis-item ille-pg-copy-btn"
                                        data-copy-input="ille-user-key-<?php echo esc_attr( $current_uid ); ?>">
                                        <span class="dashicons dashicons-clipboard"></span> Copy
                                    </button>
                                    <?php endif; ?>
                                    <button type="button"
                                        class="ille-pg-ellipsis-item ille-pg-regen-user-key"
                                        data-user-id="<?php echo esc_attr( $current_uid ); ?>"
                                        data-user-name="<?php echo esc_attr( $me->display_name ); ?>"
                                        data-key-exists="<?php echo $me_key_ex ? '1' : '0'; ?>">
                                        <span class="dashicons dashicons-update"></span>
                                        <?php echo $me_key_ex ? 'Regenerate' : 'Generate'; ?>
                                    </button>
                                    <?php if ( $me_key_ex ) : ?>
                                    <button type="button"
                                        class="ille-pg-ellipsis-item ille-pg-ellipsis-item--danger ille-pg-revoke-user-key"
                                        data-user-id="<?php echo esc_attr( $current_uid ); ?>"
                                        data-user-name="<?php echo esc_attr( $me->display_name ); ?>">
                                        <span class="dashicons dashicons-trash"></span> Revoke
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ( $is_admin ) : ?>
                    <?php /* ---- Other users — AJAX paginated list (admin only) ---- */ ?>
                    <div class="ille-pg-key-list-controls">
                        <input type="search"
                            id="ille-key-search"
                            class="ille-pg-input ille-pg-key-search"
                            placeholder="Search users…"
                            autocomplete="off" />
                    </div>

                    <div id="ille-key-list-rows">
                        <div class="ille-pg-key-list-loading">
                            <span class="dashicons dashicons-update spin"></span> Loading…
                        </div>
                    </div>

                    <div class="ille-pg-key-pagination" id="ille-key-pagination" style="display:none">
                        <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-btn--ghost" id="ille-key-prev">
                            <span class="dashicons dashicons-arrow-left-alt2"></span>
                        </button>
                        <span id="ille-key-page-info"></span>
                        <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-btn--ghost" id="ille-key-next">
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </button>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB: AI MODELS
        ================================================================ -->
        <div class="ille-pg-tab-panel" data-panel="ai">
            <div class="ille-pg-card">
                <div class="ille-pg-card__header"><h2>Language Model</h2></div>

                <div class="ille-pg-model-guide">
                    <p>Select your <strong>preferred model</strong> and add its API key below. At generation time, the selected model is used — if its key is missing, the plugin automatically falls back to the next model that has a key configured.</p>
                    <div class="ille-pg-model-guide__tips">
                        <div class="ille-pg-model-guide__tip">
                            <span class="ille-pg-model-guide__icon">⚡</span>
                            <div>
                                <strong>Gemini 2.0 Flash</strong> — Best starting point. Free tier with 1,500 requests/day. Fast and good quality for blog posts.
                            </div>
                        </div>
                        <div class="ille-pg-model-guide__tip">
                            <span class="ille-pg-model-guide__icon">✍️</span>
                            <div>
                                <strong>GPT-4o Mini</strong> — Paid but very affordable. Excellent writing quality and instruction following.
                            </div>
                        </div>
                        <div class="ille-pg-model-guide__tip">
                            <span class="ille-pg-model-guide__icon">🆓</span>
                            <div>
                                <strong>Grok 3 Mini</strong> — Free credits included. Good alternative if Gemini quota is exhausted.
                            </div>
                        </div>
                    </div>
                    <p class="ille-pg-hint">💡 Tip: Add keys for multiple models so the plugin can fall back automatically if your preferred model's quota is reached or its key expires.</p>
                </div>

                <div class="ille-pg-models">
                    <?php foreach ( $models as $model_id => $model ) :
                        $key_val = ILLE_PG_Settings::get( $model['key_opt'], '' );
                    ?>
                        <div class="ille-pg-model-card <?php echo $active_model === $model_id ? 'active' : ''; ?>" data-model-id="<?php echo esc_attr( $model_id ); ?>">
                            <label class="ille-pg-model-card__header">
                                <input type="radio"
                                    name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_ACTIVE_MODEL ); ?>]"
                                    value="<?php echo esc_attr( $model_id ); ?>"
                                    <?php checked( $active_model, $model_id ); ?> />
                                <span class="ille-pg-model-card__name"><?php echo esc_html( $model['label'] ); ?></span>
                                <?php if ( $model['free'] ) : ?>
                                    <span class="ille-pg-badge ille-pg-badge--green">Free</span>
                                <?php else : ?>
                                    <span class="ille-pg-badge ille-pg-badge--orange">Paid</span>
                                <?php endif; ?>
                                <?php if ( $key_val ) : ?>
                                    <span class="ille-pg-key-badge ille-pg-key-badge--set">Key set ✓</span>
                                <?php else : ?>
                                    <span class="ille-pg-key-badge ille-pg-key-badge--missing">No key</span>
                                <?php endif; ?>
                            </label>
                            <p class="ille-pg-hint"><?php echo wp_kses( $model['note'], [ 'a' => [ 'href' => [], 'target' => [] ] ] ); ?></p>
                            <input type="password"
                                class="ille-pg-input"
                                name="settings[<?php echo esc_attr( $model['key_opt'] ); ?>]"
                                value="<?php echo esc_attr( $key_val ); ?>"
                                placeholder="API Key"
                                autocomplete="off" />
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php
                $resolved = ILLE_PG_Settings::resolve_active_model();
                if ( ! is_wp_error( $resolved ) ) :
                    $is_preferred = $resolved['id'] === $active_model;
                ?>
                <p class="ille-pg-hint" id="ille-active-model-indicator" style="margin-top:12px">
                    <?php if ( $is_preferred ) : ?>
                        <strong>Active model:</strong> <?php echo esc_html( $resolved['model']['label'] ); ?> (your preferred choice)
                    <?php else : ?>
                        <strong>Active model:</strong> <?php echo esc_html( $resolved['model']['label'] ); ?>
                        <span style="color:var(--ille-warning)"> — preferred model has no key; using first available.</span>
                    <?php endif; ?>
                </p>
                <?php else : ?>
                <p class="ille-pg-hint ille-pg-active-model--none" id="ille-active-model-indicator" style="color:var(--ille-danger);margin-top:12px">
                    ⚠ No API key configured. Post generation will fail until a key is added.
                </p>
                <?php endif; ?>
            </div>

            <?php
            $image_models      = ILLE_PG_Settings::get_available_image_models();
            $active_img_model  = ILLE_PG_Settings::get_image_model();
            $pollinations_key  = ILLE_PG_Settings::get( ILLE_PG_Settings::KEY_POLLINATIONS_KEY, '' );
            ?>
            <div class="ille-pg-card" style="margin-top:16px">
                <div class="ille-pg-card__header"><h2>Image Generation</h2></div>

                <p class="ille-pg-hint">
                    Images are generated <strong>asynchronously</strong> — the post is published immediately with the default placeholder image, and the AI-generated image replaces it in the background once it is ready.
                </p>

                <div class="ille-pg-field-row" style="margin-top:12px">
                    <label class="ille-pg-label" for="ille-image-model">Preferred Image Model</label>
                    <select class="ille-pg-input" id="ille-image-model"
                            name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_IMAGE_MODEL ); ?>]">
                        <?php foreach ( $image_models as $model_id => $model_label ) : ?>
                            <option value="<?php echo esc_attr( $model_id ); ?>"
                                <?php selected( $active_img_model, $model_id ); ?>>
                                <?php echo esc_html( $model_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="ille-pg-hint">
                        <strong>Auto</strong> uses the same provider as your text model (e.g. Gemini text → Gemini Imagen). All other options use the API key already configured above, except Pollinations.ai which has its own optional key below.
                    </p>
                </div>

                <div class="ille-pg-field-row" style="margin-top:16px">
                    <label class="ille-pg-label" for="ille-pollinations-key">
                        Pollinations.ai API Key
                        <span class="ille-pg-badge ille-pg-badge--green" style="margin-left:6px">Optional</span>
                    </label>
                    <input type="password"
                        class="ille-pg-input"
                        id="ille-pollinations-key"
                        name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_POLLINATIONS_KEY ); ?>]"
                        value="<?php echo esc_attr( $pollinations_key ); ?>"
                        placeholder="Leave blank to use free tier"
                        autocomplete="off" />
                    <p class="ille-pg-hint">
                        Free tier works without a key. An API key unlocks higher rate limits and priority generation.
                        <a href="https://pollinations.ai" target="_blank">Get key →</a>
                    </p>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB: PROMPTS
        ================================================================ -->
        <div class="ille-pg-tab-panel" data-panel="prompts">
            <div class="ille-pg-card">
                <div class="ille-pg-card__header"><h2>Post Generation Prompt</h2></div>
                <p class="ille-pg-hint">Use <code>{topic}</code> as a placeholder for the blog topic.</p>
                <textarea
                    name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_POST_PROMPT ); ?>]"
                    class="ille-pg-textarea"
                    rows="8"
                ><?php echo esc_textarea( ILLE_PG_Settings::get_post_prompt() ); ?></textarea>
                <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-reset-prompt"
                        data-target="<?php echo esc_attr( ILLE_PG_Settings::KEY_POST_PROMPT ); ?>"
                        data-default="<?php echo esc_attr( ILLE_PG_Settings::default_post_prompt() ); ?>">
                    Reset to default
                </button>
            </div>

            <div class="ille-pg-card">
                <div class="ille-pg-card__header"><h2>Image Generation Prompt</h2></div>
                <p class="ille-pg-hint">Use <code>{title}</code> as a placeholder for the post title.</p>
                <textarea
                    name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_IMAGE_PROMPT ); ?>]"
                    class="ille-pg-textarea"
                    rows="5"
                ><?php echo esc_textarea( ILLE_PG_Settings::get_image_prompt() ); ?></textarea>
                <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-reset-prompt"
                        data-target="<?php echo esc_attr( ILLE_PG_Settings::KEY_IMAGE_PROMPT ); ?>"
                        data-default="<?php echo esc_attr( ILLE_PG_Settings::default_image_prompt() ); ?>">
                    Reset to default
                </button>
            </div>

            <div class="ille-pg-card">
                <div class="ille-pg-card__header"><h2>Default Placeholder Image</h2></div>
                <p class="ille-pg-hint">Used as the featured image when AI image generation fails. If not set, the most recent media library image is used instead.</p>

                <?php
                $default_img_id  = ILLE_PG_Settings::get_default_image_id();
                $default_img_src = $default_img_id ? wp_get_attachment_image_src( $default_img_id, 'medium' ) : null;
                ?>

                <div class="ille-pg-default-image-wrap">
                    <input type="hidden"
                        id="ille-default-image-id"
                        name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_DEFAULT_IMAGE ); ?>]"
                        value="<?php echo esc_attr( $default_img_id ); ?>" />

                    <?php if ( $default_img_src ) : ?>
                        <div class="ille-pg-default-image-preview" id="ille-default-image-preview">
                            <img src="<?php echo esc_url( $default_img_src[0] ); ?>" alt="Default placeholder" />
                        </div>
                    <?php else : ?>
                        <div class="ille-pg-default-image-preview ille-pg-default-image-preview--empty" id="ille-default-image-preview">
                            <span>No image selected</span>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex;gap:8px;margin-top:10px">
                        <button type="button" id="ille-default-image-select" class="ille-pg-btn ille-pg-btn--sm">
                            <?php echo $default_img_id ? 'Change Image' : 'Select Image'; ?>
                        </button>
                        <?php if ( $default_img_id ) : ?>
                            <button type="button" id="ille-default-image-remove" class="ille-pg-btn ille-pg-btn--sm ille-pg-btn--ghost">Remove</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ille-pg-card">
                <div class="ille-pg-card__header"><h2>Content Uniqueness</h2></div>
                <p class="ille-pg-hint">
                    The AI is given a list of recently published post titles and focus keyphrases so it avoids repeating covered topics.
                    If a focus keyword already exists, the AI writes a fresh angle or continuation rather than a duplicate.
                    These measures apply to all generation paths — manual, scheduled, and REST endpoint.
                </p>

                <div class="ille-pg-field-row" style="margin-top:12px">
                    <label class="ille-pg-label" for="ille-covered-topics-count">Covered Topics Count</label>
                    <input
                        type="number"
                        id="ille-covered-topics-count"
                        class="ille-pg-input"
                        style="max-width:100px"
                        name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_COVERED_TOPICS_COUNT ); ?>]"
                        value="<?php echo esc_attr( ILLE_PG_Settings::get_covered_topics_count() ); ?>"
                        min="10"
                        max="200"
                    />
                    <p class="ille-pg-hint">Number of recent post titles and keyphrases to inject into the AI prompt as already-covered context. Range: 10–200. Default: 50.</p>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB: SCHEDULES
        ================================================================ -->
        <div class="ille-pg-tab-panel" data-panel="schedule">
            <div class="ille-pg-card">
                <div class="ille-pg-card__header">
                    <h2>Post Schedules</h2>
                    <span class="ille-pg-badge">Up to <?php echo esc_html( ILLE_PG_Settings::MAX_SCHEDULES ); ?> schedules</span>
                </div>
                <p class="ille-pg-hint">Each enabled schedule will automatically generate and publish (or draft) a post at the configured time.</p>

                <div class="ille-pg-schedules">
                    <?php foreach ( $schedules as $i => $s ) :
                        $next = $next_runs[ $i ] ?? null;
                    ?>
                        <div class="ille-pg-schedule <?php echo $s['enabled'] ? 'enabled' : ''; ?>" data-index="<?php echo esc_attr( $i ); ?>">

                            <div class="ille-pg-schedule__header">
                                <label class="ille-pg-toggle">
                                    <input type="checkbox"
                                        name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_SCHEDULES ); ?>][<?php echo $i; ?>][enabled]"
                                        value="1"
                                        <?php checked( $s['enabled'] ); ?>
                                        class="ille-pg-schedule-toggle" />
                                    <span class="ille-pg-toggle__track"></span>
                                </label>
                                <input type="text"
                                    name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_SCHEDULES ); ?>][<?php echo $i; ?>][label]"
                                    class="ille-pg-input ille-pg-schedule__label-input"
                                    value="<?php echo esc_attr( $s['label'] ); ?>"
                                    placeholder="Schedule <?php echo $i + 1; ?> label" />
                                <?php if ( $next ) : ?>
                                    <span class="ille-pg-badge ille-pg-badge--green ille-pg-schedule__next">
                                        Next: <?php echo esc_html( $next ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="ille-pg-schedule__body">
                                <div class="ille-pg-field">
                                    <label class="ille-pg-label">Days</label>
                                    <div class="ille-pg-days">
                                        <?php
                                        $days_opts = [ 'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun' ];
                                        foreach ( $days_opts as $day_val => $day_lbl ) :
                                            $checked = in_array( $day_val, $s['days'], true ) ? 'checked' : '';
                                        ?>
                                            <label class="ille-pg-day">
                                                <input type="checkbox"
                                                    name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_SCHEDULES ); ?>][<?php echo $i; ?>][days][]"
                                                    value="<?php echo esc_attr( $day_val ); ?>"
                                                    <?php echo $checked; ?> />
                                                <span><?php echo esc_html( $day_lbl ); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="ille-pg-fields-row">
                                    <div class="ille-pg-field">
                                        <label class="ille-pg-label">Time</label>
                                        <input type="time"
                                            name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_SCHEDULES ); ?>][<?php echo $i; ?>][time]"
                                            class="ille-pg-input"
                                            value="<?php echo esc_attr( $s['time'] ); ?>" />
                                    </div>

                                    <div class="ille-pg-field">
                                        <label class="ille-pg-label">Status</label>
                                        <select name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_SCHEDULES ); ?>][<?php echo $i; ?>][post_status]" class="ille-pg-select">
                                            <option value="publish" <?php selected( $s['post_status'], 'publish' ); ?>>Publish</option>
                                            <option value="draft"   <?php selected( $s['post_status'], 'draft' ); ?>>Draft</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="ille-pg-field">
                                    <label class="ille-pg-label">
                                        Topic
                                        <span class="ille-pg-label__hint">Optional — AI picks one if blank</span>
                                    </label>
                                    <input type="text"
                                        name="settings[<?php echo esc_attr( ILLE_PG_Settings::KEY_SCHEDULES ); ?>][<?php echo $i; ?>][topic]"
                                        class="ille-pg-input"
                                        value="<?php echo esc_attr( $s['topic'] ); ?>"
                                        placeholder="Leave blank for AI-chosen topic" />
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB: ACTIVITY LOG (admin only)
        ================================================================ -->
        <?php if ( current_user_can( 'manage_options' ) ) :
            $log_stats   = ILLE_PG_Logger::get_stats();
            $log_entries = ILLE_PG_Logger::get_entries( 200 );

            $event_labels = [
                ILLE_PG_Logger::EVENT_POST_CREATED     => [ 'Post Created',      'green'  ],
                ILLE_PG_Logger::EVENT_SETTINGS_CHANGED => [ 'Settings Changed',  'orange' ],
                ILLE_PG_Logger::EVENT_API_KEY_ACTION   => [ 'API Key Action',    'blue'   ],
                ILLE_PG_Logger::EVENT_LOG_EXPORTED     => [ 'Log Exported',      'muted'  ],
                ILLE_PG_Logger::EVENT_LOG_TRUNCATED    => [ 'Log Truncated',     'muted'  ],
                ILLE_PG_Logger::EVENT_LOG_DELETED      => [ 'Log Deleted',       'danger' ],
                ILLE_PG_Logger::EVENT_ENDPOINT_TESTED  => [ 'Endpoint Tested',   'muted'  ],
                ILLE_PG_Logger::EVENT_ENDPOINT_ERROR   => [ 'Endpoint Error',    'danger' ],
            ];

            $trigger_labels = [
                ILLE_PG_Logger::TRIGGER_MANUAL   => 'Manual',
                ILLE_PG_Logger::TRIGGER_ENDPOINT => 'Endpoint',
                ILLE_PG_Logger::TRIGGER_SCHEDULE => 'Schedule',
            ];
        ?>
        <div class="ille-pg-tab-panel" data-panel="log">
            <div class="ille-pg-card">
                <div class="ille-pg-card__header">
                    <h2>Activity Log</h2>
                    <div class="ille-pg-log-header-actions">
                        <button type="button" id="ille-log-refresh"  class="ille-pg-icon-btn" title="Refresh log"><span class="dashicons dashicons-update"></span></button>
                        <button type="button" id="ille-log-export"   class="ille-pg-icon-btn" title="Export CSV"><span class="dashicons dashicons-download"></span></button>
                        <button type="button" id="ille-log-truncate" class="ille-pg-icon-btn ille-pg-icon-btn--warning" title="Truncate log"><span class="dashicons dashicons-trash"></span></button>
                        <button type="button" id="ille-log-delete"   class="ille-pg-icon-btn ille-pg-icon-btn--danger"  title="Delete log file"><span class="dashicons dashicons-dismiss"></span></button>
                    </div>
                </div>

                <!-- Stats bar -->
                <div class="ille-pg-log-stats">
                    <div class="ille-pg-log-stat">
                        <span class="ille-pg-log-stat__value"><?php echo esc_html( number_format( $log_stats['count'] ) ); ?></span>
                        <span class="ille-pg-log-stat__label">Total entries</span>
                    </div>
                    <div class="ille-pg-log-stat">
                        <span class="ille-pg-log-stat__value"><?php echo esc_html( size_format( $log_stats['size'] ) ); ?></span>
                        <span class="ille-pg-log-stat__label">File size</span>
                    </div>
                    <div class="ille-pg-log-stat">
                        <span class="ille-pg-log-stat__value"><?php echo $log_stats['oldest'] ? esc_html( date( 'M j, Y', strtotime( $log_stats['oldest'] ) ) ) : '—'; ?></span>
                        <span class="ille-pg-log-stat__label">Oldest entry</span>
                    </div>
                    <div class="ille-pg-log-stat">
                        <span class="ille-pg-log-stat__value"><?php echo $log_stats['newest'] ? esc_html( date( 'M j, Y', strtotime( $log_stats['newest'] ) ) ) : '—'; ?></span>
                        <span class="ille-pg-log-stat__label">Latest entry</span>
                    </div>
                </div>

                <p class="ille-pg-hint">Showing last <?php echo count( $log_entries ); ?> of <?php echo esc_html( $log_stats['count'] ); ?> entries. Export CSV for full history.</p>

                <?php if ( empty( $log_entries ) ) : ?>
                    <p class="ille-pg-hint" style="text-align:center;padding:32px 0">No activity recorded yet.</p>
                <?php else : ?>
                    <div class="ille-pg-log-table-wrap">
                        <table class="ille-pg-log-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Event</th>
                                    <th>Trigger</th>
                                    <th>User</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $log_entries as $entry ) :
                                    $ev      = $entry['event'] ?? '';
                                    $label   = $event_labels[ $ev ][0] ?? ucwords( str_replace( '_', ' ', $ev ) );
                                    $colour  = $event_labels[ $ev ][1] ?? 'muted';
                                    $trigger = $trigger_labels[ $entry['trigger'] ?? '' ] ?? ( $entry['trigger'] ?? '' );
                                    $data    = $entry['data'] ?? [];

                                    // Format details based on event type
                                    $details = '';
                                    if ( $ev === ILLE_PG_Logger::EVENT_POST_CREATED ) {
                                        $details = sprintf( '<a href="%s" target="_blank">%s</a> · %s',
                                            esc_url( $data['post_url'] ?? '#' ),
                                            esc_html( $data['title'] ?? '' ),
                                            esc_html( ucfirst( $data['status'] ?? '' ) )
                                        );
                                    } elseif ( $ev === ILLE_PG_Logger::EVENT_SETTINGS_CHANGED ) {
                                        $details = sprintf( '<code>%s</code> <span class="ille-pg-log-prev">%s</span> → <span class="ille-pg-log-new">%s</span>',
                                            esc_html( $data['key']  ?? '' ),
                                            esc_html( $data['prev'] ?? '' ),
                                            esc_html( $data['new']  ?? '' )
                                        );
                                    } elseif ( $ev === ILLE_PG_Logger::EVENT_API_KEY_ACTION ) {
                                        $details = sprintf( '%s key for <strong>%s</strong>',
                                            esc_html( ucfirst( $data['action'] ?? '' ) ),
                                            esc_html( $data['target_username'] ?? '' )
                                        );
                                    } elseif ( $ev === ILLE_PG_Logger::EVENT_ENDPOINT_ERROR ) {
                                        $reason_map = [
                                            'missing_api_key'     => 'Missing API key',
                                            'invalid_api_key'     => 'Invalid API key',
                                            'role_not_permitted'  => 'Role not permitted',
                                            'post_creation_failed'=> 'Post creation failed',
                                        ];
                                        $reason  = $reason_map[ $data['reason'] ?? '' ] ?? ucwords( str_replace( '_', ' ', $data['reason'] ?? '' ) );
                                        $extra   = '';
                                        if ( ! empty( $data['key_preview'] ) ) $extra = ' · key: <code>' . esc_html( $data['key_preview'] ) . '</code>';
                                        if ( ! empty( $data['username'] )    ) $extra = ' · user: <strong>' . esc_html( $data['username'] ) . '</strong>';
                                        if ( ! empty( $data['error'] )       ) $extra = ' · ' . esc_html( $data['error'] );
                                        if ( ! empty( $data['ip'] )          ) $extra .= ' · IP: <code>' . esc_html( $data['ip'] ) . '</code>';
                                        $details = $reason . $extra;
                                    } else {
                                        $details = esc_html( wp_json_encode( $data ) );
                                    }
                                ?>
                                    <tr>
                                        <td class="ille-pg-log-ts"><?php echo esc_html( $entry['ts'] ?? '' ); ?></td>
                                        <td><span class="ille-pg-log-badge ille-pg-log-badge--<?php echo esc_attr( $colour ); ?>"><?php echo esc_html( $label ); ?></span></td>
                                        <td class="ille-pg-log-trigger"><?php echo esc_html( $trigger ); ?></td>
                                        <td class="ille-pg-log-user"><?php echo esc_html( $entry['uname'] ?? '' ); ?></td>
                                        <td class="ille-pg-log-details"><?php echo wp_kses( $details, [ 'a' => [ 'href' => [], 'target' => [] ], 'code' => [], 'strong' => [], 'span' => [ 'class' => [] ] ] ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <p class="ille-pg-alert" id="ille-log-action-status" hidden></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Save Button -->
        <div class="ille-pg-settings-footer">
            <button type="submit" id="ille-pg-save" class="ille-pg-btn ille-pg-btn--primary">
                <span class="ille-pg-btn__label">Save Settings</span>
                <span class="ille-pg-btn__spinner" hidden></span>
            </button>
            <span class="ille-pg-save-status" id="ille-save-status"></span>
        </div>

    </form>
</div>
