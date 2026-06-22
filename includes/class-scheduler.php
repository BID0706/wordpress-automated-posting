<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ILLE_PG_Scheduler {

    const HOOK_PREFIX = 'ille_pg_schedule_';

    public function __construct() {
        // Register dynamic cron hook handlers
        for ( $i = 0; $i < ILLE_PG_Settings::MAX_SCHEDULES; $i++ ) {
            add_action( self::HOOK_PREFIX . $i, [ $this, 'run_schedule' ], 10, 1 );
        }
    }

    public function run_schedule( int $index ) {
        $schedules = ILLE_PG_Settings::get_schedules();
        if ( ! isset( $schedules[ $index ] ) ) return;

        $schedule = $schedules[ $index ];
        if ( empty( $schedule['enabled'] ) ) return;

        // Check if today is one of the configured days
        $today = strtolower( date( 'D' ) ); // mon, tue, wed...
        $days  = array_map( 'strtolower', (array) ( $schedule['days'] ?? [] ) );
        if ( ! empty( $days ) && ! in_array( $today, $days, true ) ) {
            self::reschedule( $index, $schedule );
            return;
        }

        ILLE_PG_Post_Creator::create( [
            'topic'          => $schedule['topic']       ?? '',
            'post_status'    => $schedule['post_status'] ?? 'publish',
            'featured_image' => true,
            'trigger'        => ILLE_PG_Logger::TRIGGER_SCHEDULE,
        ] );

        self::reschedule( $index, $schedule );
    }

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    public static function register_cron_schedules() {
        $schedules = ILLE_PG_Settings::get_schedules();
        foreach ( $schedules as $i => $schedule ) {
            if ( ! empty( $schedule['enabled'] ) ) {
                self::schedule_event( $i, $schedule );
            }
        }
    }

    public static function clear_all_cron_events() {
        for ( $i = 0; $i < ILLE_PG_Settings::MAX_SCHEDULES; $i++ ) {
            $hook = self::HOOK_PREFIX . $i;
            $ts   = wp_next_scheduled( $hook, [ $i ] );
            if ( $ts ) {
                wp_unschedule_event( $ts, $hook, [ $i ] );
            }
        }
    }

    public static function sync_schedules() {
        self::clear_all_cron_events();
        self::register_cron_schedules();
    }

    private static function schedule_event( int $index, array $schedule ) {
        $hook = self::HOOK_PREFIX . $index;

        // Avoid duplicate scheduling
        if ( wp_next_scheduled( $hook, [ $index ] ) ) return;

        $timestamp = self::next_run_timestamp( $schedule );
        if ( $timestamp ) {
            wp_schedule_single_event( $timestamp, $hook, [ $index ] );
        }
    }

    private static function reschedule( int $index, array $schedule ) {
        $hook      = self::HOOK_PREFIX . $index;
        $timestamp = self::next_run_timestamp( $schedule, true );
        if ( $timestamp ) {
            wp_schedule_single_event( $timestamp, $hook, [ $index ] );
        }
    }

    /**
     * Calculate the next UNIX timestamp for a schedule.
     * @param bool $skip_today Skip today when rescheduling after a run.
     */
    private static function next_run_timestamp( array $schedule, bool $skip_today = false ): int {
        $time = $schedule['time'] ?? '08:00';
        $days = array_map( 'strtolower', (array) ( $schedule['days'] ?? [] ) );

        if ( empty( $days ) ) {
            // No days configured — run daily at the given time
            $next = strtotime( 'today ' . $time );
            if ( $next <= time() || $skip_today ) {
                $next = strtotime( 'tomorrow ' . $time );
            }
            return $next;
        }

        // Find the next matching day
        $day_map = [ 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 0 ];
        $today_n = (int) date( 'w' ); // 0 = Sunday

        for ( $offset = ( $skip_today ? 1 : 0 ); $offset <= 7; $offset++ ) {
            $check_n   = ( $today_n + $offset ) % 7;
            $check_day = array_search( $check_n, $day_map, true );
            if ( $check_day && in_array( $check_day, $days, true ) ) {
                $ts = strtotime( "+{$offset} days " . $time );
                if ( $ts > time() ) return $ts;
            }
        }

        return 0;
    }

    // -------------------------------------------------------------------------
    // Status helper for settings page
    // -------------------------------------------------------------------------

    public static function get_next_runs(): array {
        $result = [];
        for ( $i = 0; $i < ILLE_PG_Settings::MAX_SCHEDULES; $i++ ) {
            $hook = self::HOOK_PREFIX . $i;
            $ts   = wp_next_scheduled( $hook, [ $i ] );
            $result[ $i ] = $ts ? date( 'D, M j Y @ g:i A', $ts ) : null;
        }
        return $result;
    }
}
