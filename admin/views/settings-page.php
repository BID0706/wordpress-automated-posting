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
                $is_admin      = current_user_can( 'manage_options' );
                $current_uid   = get_current_user_id();

                // Admins see all allowed users; others see only themselves
                if ( $is_admin ) {
                    $all_allowed = ILLE_PG_Settings::get_users_with_allowed_roles();
                    // Always include the current admin; include others only if they have an active key
                    $allowed_users = array_filter( $all_allowed, function( $u ) use ( $current_uid ) {
                        return $u->ID === $current_uid || ! empty( ILLE_PG_Settings::get_user_api_key( $u->ID ) );
                    } );
                } else {
                    $allowed_users = array_filter( [ get_userdata( $current_uid ) ] );
                }

                if ( $is_admin ) : ?>
                    <p class="ille-pg-hint">Manage API keys for all users with allowed roles. Posts created via each key are attributed to that user. Your own key is highlighted.</p>
                <?php else : ?>
                    <p class="ille-pg-hint">Your personal API key for external access to the endpoint. Posts created with this key will be attributed to you.</p>
                <?php endif; ?>

                <?php if ( empty( $allowed_users ) ) : ?>
                    <p class="ille-pg-hint">No users found with the allowed roles.</p>
                <?php else : ?>
                    <div class="ille-pg-user-keys">
                        <?php foreach ( $allowed_users as $u ) :
                            $u_key      = ILLE_PG_Settings::get_user_api_key( $u->ID );
                            $u_last     = get_user_meta( $u->ID, ILLE_PG_Settings::USER_META_API_KEY_LAST, true );
                            $u_roles    = array_map( fn( $r ) => ucfirst( $r ), array_intersect( $u->roles, $saved_roles ) );
                            $key_exists = ! empty( $u_key );
                            $is_me      = ( $u->ID === $current_uid );
                            // Non-admins can only manage their own key
                            $can_manage = $is_admin || $is_me;
                        ?>
                            <div class="ille-pg-user-key-row <?php echo $is_me ? 'ille-pg-user-key-row--me' : ''; ?>">
                                <div class="ille-pg-user-key-row__info">
                                    <span class="ille-pg-user-key-row__avatar <?php echo $is_me ? 'ille-pg-user-key-row__avatar--me' : ''; ?>">
                                        <?php echo esc_html( strtoupper( substr( $u->display_name, 0, 1 ) ) ); ?>
                                    </span>
                                    <div>
                                        <strong>
                                            <?php echo esc_html( $u->display_name ); ?>
                                            <?php if ( $is_me ) : ?>
                                                <span class="ille-pg-badge ille-pg-badge--ai">You</span>
                                            <?php endif; ?>
                                        </strong>
                                        <span class="ille-pg-user-key-row__meta">
                                            <?php echo esc_html( implode( ', ', $u_roles ) ); ?>
                                            <?php if ( $u_last ) : ?>
                                                · Last used: <?php echo esc_html( date( 'M j, Y g:i A', strtotime( $u_last ) ) ); ?>
                                            <?php elseif ( $key_exists ) : ?>
                                                · Never used
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="ille-pg-user-key-row__actions">
                                    <?php if ( $key_exists ) : ?>
                                        <input type="text"
                                            id="ille-user-key-<?php echo esc_attr( $u->ID ); ?>"
                                            class="ille-pg-input ille-pg-input--mono ille-pg-user-key-input"
                                            value="<?php echo esc_attr( $u_key ); ?>"
                                            readonly />
                                        <button type="button"
                                            class="ille-pg-btn ille-pg-btn--sm ille-pg-copy-btn"
                                            data-copy-input="ille-user-key-<?php echo esc_attr( $u->ID ); ?>">Copy</button>
                                    <?php else : ?>
                                        <span class="ille-pg-hint">No key yet</span>
                                    <?php endif; ?>
                                    <?php if ( $can_manage ) : ?>
                                        <button type="button"
                                            class="ille-pg-btn ille-pg-btn--sm ille-pg-btn--danger ille-pg-regen-user-key"
                                            data-user-id="<?php echo esc_attr( $u->ID ); ?>"
                                            data-user-name="<?php echo esc_attr( $u->display_name ); ?>">
                                            <?php echo $key_exists ? 'Regenerate' : 'Generate'; ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
