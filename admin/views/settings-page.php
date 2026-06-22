<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$api_key      = ILLE_PG_Settings::get_api_key();
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

    <!-- Tab Nav -->
    <div class="ille-pg-tabs" role="tablist">
        <button class="ille-pg-tab active" data-tab="endpoint" role="tab">Endpoint</button>
        <button class="ille-pg-tab" data-tab="auth"     role="tab">Auth & Roles</button>
        <button class="ille-pg-tab" data-tab="ai"       role="tab">AI Models</button>
        <button class="ille-pg-tab" data-tab="prompts"  role="tab">Prompts</button>
        <button class="ille-pg-tab" data-tab="schedule" role="tab">Schedules</button>
    </div>

    <form id="ille-pg-settings-form">

        <!-- ================================================================
             TAB: ENDPOINT
        ================================================================ -->
        <div class="ille-pg-tab-panel active" data-panel="endpoint">
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
                        <p># On-demand with topic:</p>
                        <code>curl -H "X-API-Key: YOUR_KEY" "<?php echo esc_html( $endpoint_url ); ?>?topic=How+to+save+money"</code>
                        <p># Draft only:</p>
                        <code>curl -H "X-API-Key: YOUR_KEY" "<?php echo esc_html( $endpoint_url ); ?>?publish=false"</code>
                        <p># Cron (every Monday 8 AM):</p>
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
                <div class="ille-pg-card__header"><h2>API Key</h2></div>

                <div class="ille-pg-field">
                    <label class="ille-pg-label">Endpoint Secret Key</label>
                    <p class="ille-pg-hint">Pass as <code>X-API-Key</code> header or <code>?api_key=</code> parameter. Not required for logged-in users with an allowed role.</p>
                    <div class="ille-pg-copy-row">
                        <input type="text" id="ille-api-key-display" class="ille-pg-input ille-pg-input--mono"
                               value="<?php echo esc_attr( $api_key ); ?>" readonly />
                        <button type="button" class="ille-pg-btn ille-pg-btn--sm ille-pg-copy-btn" data-copy-input="ille-api-key-display">Copy</button>
                        <button type="button" id="ille-regenerate-key" class="ille-pg-btn ille-pg-btn--sm ille-pg-btn--danger">Regenerate</button>
                    </div>
                    <p class="ille-pg-alert ille-pg-alert--warning" id="ille-regen-warning" hidden>
                        Regenerating will invalidate the current key. Any cron jobs or external callers must be updated.
                    </p>
                </div>
            </div>

            <div class="ille-pg-card">
                <div class="ille-pg-card__header"><h2>Allowed Roles</h2></div>
                <p class="ille-pg-hint">Users with these roles can use the admin UI and call the endpoint without an API key.</p>

                <div class="ille-pg-checks">
                    <?php foreach ( $all_roles as $role_slug => $role_name ) :
                        $checked = in_array( $role_slug, $saved_roles, true ) ? 'checked' : '';
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
        </div>

        <!-- ================================================================
             TAB: AI MODELS
        ================================================================ -->
        <div class="ille-pg-tab-panel" data-panel="ai">
            <div class="ille-pg-card">
                <div class="ille-pg-card__header"><h2>Language Model</h2></div>

                <div class="ille-pg-models">
                    <?php foreach ( $models as $model_id => $model ) :
                        $key_val = ILLE_PG_Settings::get( $model['key_opt'], '' );
                    ?>
                        <div class="ille-pg-model-card <?php echo $active_model === $model_id ? 'active' : ''; ?>">
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
