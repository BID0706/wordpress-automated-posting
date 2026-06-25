<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="ille-pg-wrap">
    <div class="ille-pg-header">
        <div class="ille-pg-header__logo">
            <span class="ille-pg-header__icon">✦</span>
            <div>
                <h1>Post Generator</h1>
                <p>Generate SEO-optimized posts instantly</p>
            </div>
        </div>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ille-pg-settings' ) ); ?>" class="ille-pg-btn ille-pg-btn--ghost">
            Settings →
        </a>
    </div>

    <div class="ille-pg-main">

        <!-- Generate Form -->
        <div class="ille-pg-card" id="ille-pg-generate-card">
            <div class="ille-pg-card__header">
                <h2>New Post</h2>
                <span class="ille-pg-badge ille-pg-badge--ai">AI Powered</span>
            </div>

            <form id="ille-pg-generate-form" autocomplete="off">

                <!-- Blog Title -->
                <div class="ille-pg-field">
                    <label class="ille-pg-label" for="ille-topic">
                        Blog Title
                        <span class="ille-pg-label__hint">Optional — AI will create one if left blank</span>
                    </label>
                    <input
                        type="text"
                        id="ille-topic"
                        name="topic"
                        class="ille-pg-input"
                        placeholder="e.g. How to Save Money in Nigeria in 2025"
                    />
                </div>

                <!-- Focus Keyword -->
                <div class="ille-pg-field">
                    <label class="ille-pg-label" for="ille-keyword">
                        Focus Keyword
                        <span class="ille-pg-label__hint">Optional — AI will suggest one if blank</span>
                    </label>
                    <input
                        type="text"
                        id="ille-keyword"
                        name="focus_keyword"
                        class="ille-pg-input"
                        placeholder="e.g. save money"
                        maxlength="60"
                        autocomplete="off"
                    />
                    <span id="ille-kw-hint" class="ille-pg-hint">1–2 words for best SEO results</span>
                    <div id="ille-keyword-warning" class="ille-pg-alert ille-pg-alert--warning" hidden></div>
                </div>

                <div class="ille-pg-fields-row">

                    <!-- Featured Image -->
                    <div class="ille-pg-field">
                        <label class="ille-pg-label">Featured Image</label>
                        <div class="ille-pg-radio-group">
                            <label class="ille-pg-radio">
                                <input type="radio" name="featured_image" value="1" checked />
                                <span class="ille-pg-radio__pip"></span>
                                Generate
                            </label>
                            <label class="ille-pg-radio">
                                <input type="radio" name="featured_image" value="0" />
                                <span class="ille-pg-radio__pip"></span>
                                Skip
                            </label>
                        </div>
                    </div>

                    <!-- Post Status -->
                    <div class="ille-pg-field">
                        <label class="ille-pg-label">Post Status</label>
                        <div class="ille-pg-radio-group">
                            <label class="ille-pg-radio">
                                <input type="radio" name="post_status" value="publish" checked />
                                <span class="ille-pg-radio__pip"></span>
                                Publish
                            </label>
                            <label class="ille-pg-radio">
                                <input type="radio" name="post_status" value="draft" />
                                <span class="ille-pg-radio__pip"></span>
                                Draft
                            </label>
                        </div>
                    </div>

                </div>

                <!-- Schedule -->
                <div class="ille-pg-field" id="ille-schedule-field">
                    <label class="ille-pg-label" for="ille-scheduled-date">
                        Schedule Publish
                        <span class="ille-pg-label__hint">Leave blank to publish immediately</span>
                    </label>
                    <input
                        type="datetime-local"
                        id="ille-scheduled-date"
                        name="scheduled_date"
                        class="ille-pg-input"
                    />
                </div>

                <div class="ille-pg-form-footer">
                    <button type="submit" id="ille-pg-submit" class="ille-pg-btn ille-pg-btn--primary">
                        <span class="ille-pg-btn__icon">✦</span>
                        <span class="ille-pg-btn__label">Generate Post</span>
                        <span class="ille-pg-btn__spinner" hidden></span>
                    </button>
                </div>

            </form>
        </div>

        <!-- Result Panel (hidden until post is created) -->
        <div class="ille-pg-card ille-pg-result" id="ille-pg-result" hidden>
            <div class="ille-pg-result__icon" id="ille-pg-result-icon">✓</div>
            <div class="ille-pg-result__body">
                <h3 id="ille-pg-result-title">Post generated</h3>
                <p id="ille-pg-result-meta"></p>
                <div class="ille-pg-result__actions" id="ille-pg-result-actions"></div>
            </div>
        </div>

        <!-- Error Panel -->
        <div class="ille-pg-alert ille-pg-alert--error" id="ille-pg-error" hidden>
            <strong>Error:</strong> <span id="ille-pg-error-msg"></span>
        </div>

    </div><!-- .ille-pg-main -->
</div>
