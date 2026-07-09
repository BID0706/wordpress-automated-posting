<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Lightweight brute-force throttling for authentication failures.
 *
 * Counts failed attempts per client IP in a transient. Once MAX_FAILURES is
 * reached within WINDOW seconds, is_locked() returns true and callers should
 * respond 429 until the window elapses. A successful auth clears the counter.
 */
class ILLE_PG_Throttle {

    const MAX_FAILURES = 10;   // attempts allowed within the window
    const WINDOW       = 900;  // 15 minutes (also the lockout duration)

    private static function transient_key( string $id ): string {
        return 'ille_pg_rl_' . md5( $id );
    }

    public static function client_ip(): string {
        $ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        $ip = preg_replace( '/[^0-9A-Fa-f:.]/', '', $ip );
        return $ip !== '' ? $ip : 'unknown';
    }

    public static function is_locked( string $id ): bool {
        return (int) get_transient( self::transient_key( $id ) ) >= self::MAX_FAILURES;
    }

    public static function record_failure( string $id ): void {
        $key   = self::transient_key( $id );
        $count = (int) get_transient( $key );
        set_transient( $key, $count + 1, self::WINDOW );
    }

    public static function clear( string $id ): void {
        delete_transient( self::transient_key( $id ) );
    }
}
