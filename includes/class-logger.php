<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ILLE_PG_Logger {

    const EVENT_POST_CREATED     = 'post_created';
    const EVENT_SETTINGS_CHANGED = 'settings_changed';
    const EVENT_API_KEY_ACTION   = 'api_key_action';
    const EVENT_LOG_EXPORTED     = 'log_exported';
    const EVENT_LOG_TRUNCATED    = 'log_truncated';
    const EVENT_LOG_DELETED      = 'log_deleted';
    const EVENT_ENDPOINT_TESTED  = 'endpoint_tested';

    const TRIGGER_MANUAL   = 'manual';
    const TRIGGER_ENDPOINT = 'endpoint';
    const TRIGGER_SCHEDULE = 'schedule';

    // -------------------------------------------------------------------------
    // File paths
    // -------------------------------------------------------------------------

    private static function log_dir(): string {
        return wp_upload_dir()['basedir'] . '/ille-pg-logs';
    }

    private static function log_file(): string {
        return self::log_dir() . '/ille-pg-audit.log';
    }

    private static function ensure_dir(): bool {
        $dir = self::log_dir();
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            // Block direct web access
            file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
            file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
        }
        return is_writable( $dir );
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    public static function log(
        string $event,
        array  $data    = [],
        string $trigger = self::TRIGGER_MANUAL,
        int    $user_id = 0
    ): void {
        if ( ! self::ensure_dir() ) return;

        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        $user      = $user_id ? get_userdata( $user_id ) : null;
        $user_name = $user ? $user->display_name : 'System';

        $entry = wp_json_encode( [
            'ts'      => current_time( 'Y-m-d H:i:s' ),
            'event'   => $event,
            'trigger' => $trigger,
            'uid'     => $user_id,
            'uname'   => $user_name,
            'data'    => $data,
        ] );

        file_put_contents( self::log_file(), $entry . "\n", FILE_APPEND | LOCK_EX );
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public static function get_entries( int $limit = 200 ): array {
        $file = self::log_file();
        if ( ! file_exists( $file ) ) return [];

        $lines   = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        $entries = [];

        foreach ( $lines as $line ) {
            $decoded = json_decode( $line, true );
            if ( $decoded ) $entries[] = $decoded;
        }

        // Newest first
        $entries = array_reverse( $entries );

        return array_slice( $entries, 0, $limit );
    }

    public static function get_stats(): array {
        $file = self::log_file();
        if ( ! file_exists( $file ) ) {
            return [ 'count' => 0, 'size' => 0, 'oldest' => null, 'newest' => null ];
        }

        $lines   = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        $count   = count( $lines );
        $size    = filesize( $file );
        $oldest  = null;
        $newest  = null;

        if ( $count > 0 ) {
            $first = json_decode( $lines[0], true );
            $last  = json_decode( $lines[ $count - 1 ], true );
            $oldest = $first['ts'] ?? null;
            $newest = $last['ts']  ?? null;
        }

        return compact( 'count', 'size', 'oldest', 'newest' );
    }

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------

    public static function get_csv(): string {
        $entries = self::get_entries( 10000 );
        $entries = array_reverse( $entries ); // oldest first for export

        $rows   = [];
        $rows[] = [ 'Timestamp', 'Event', 'Trigger', 'User ID', 'User', 'Details' ];

        foreach ( $entries as $e ) {
            $rows[] = [
                $e['ts']      ?? '',
                $e['event']   ?? '',
                $e['trigger'] ?? '',
                $e['uid']     ?? '',
                $e['uname']   ?? '',
                wp_json_encode( $e['data'] ?? [] ),
            ];
        }

        $out = fopen( 'php://temp', 'r+' );
        foreach ( $rows as $row ) {
            fputcsv( $out, $row );
        }
        rewind( $out );
        $csv = stream_get_contents( $out );
        fclose( $out );

        return $csv;
    }

    // -------------------------------------------------------------------------
    // Manage
    // -------------------------------------------------------------------------

    public static function truncate(): void {
        $file = self::log_file();
        if ( file_exists( $file ) ) {
            file_put_contents( $file, '', LOCK_EX );
        }
    }

    public static function delete(): void {
        $file = self::log_file();
        if ( file_exists( $file ) ) {
            unlink( $file );
        }
    }

    // -------------------------------------------------------------------------
    // Settings diff helper
    // -------------------------------------------------------------------------

    public static function log_settings_change( string $key, $prev, $new ): void {
        // Don't log if nothing changed or if it's a sensitive key
        $sensitive = [
            ILLE_PG_Settings::KEY_GEMINI_KEY,
            ILLE_PG_Settings::KEY_OPENAI_KEY,
            ILLE_PG_Settings::KEY_XAI_KEY,
        ];

        $display_prev = in_array( $key, $sensitive, true ) ? ( $prev ? '••••••••' : '' ) : $prev;
        $display_new  = in_array( $key, $sensitive, true ) ? ( $new  ? '••••••••' : '' ) : $new;

        if ( $prev === $new ) return;

        self::log( self::EVENT_SETTINGS_CHANGED, [
            'key'  => $key,
            'prev' => $display_prev,
            'new'  => $display_new,
        ] );
    }
}
