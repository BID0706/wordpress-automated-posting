<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reversible encryption for secrets stored at rest (API keys, provider keys).
 *
 * Uses libsodium's authenticated secretbox (XSalsa20-Poly1305), which ships in
 * PHP core since 7.2. The key is derived from the site's WordPress salts, which
 * live in wp-config.php — not the database — so a database/backup leak on its
 * own cannot decrypt stored values.
 *
 * Ciphertext format: "ilenc1:" . base64( nonce || box ). The version prefix lets
 * callers tell encrypted values apart from legacy plaintext and migrate lazily.
 *
 * Note: rotating the site's salts invalidates all previously encrypted values.
 * decrypt() fails soft (returns '') rather than throwing so a bad/rotated value
 * never fatals a request; affected keys simply need to be re-entered.
 */
class ILLE_PG_Crypto {

    const PREFIX = 'ilenc1:';

    public static function is_available(): bool {
        return function_exists( 'sodium_crypto_secretbox' );
    }

    /**
     * True if the value is one this class produced (already encrypted).
     */
    public static function is_encrypted( string $value ): bool {
        return strncmp( $value, self::PREFIX, strlen( self::PREFIX ) ) === 0;
    }

    private static function key(): string {
        // 32-byte key derived from a WP salt (stored in wp-config.php, not the DB).
        return sodium_crypto_generichash(
            'ille_pg_secret_v1|' . wp_salt( 'secure_auth' ),
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }

    /**
     * Encrypt a plaintext string. Returns the prefixed ciphertext, or the
     * original plaintext unchanged if libsodium is unavailable or input is empty.
     */
    public static function encrypt( string $plaintext ): string {
        if ( $plaintext === '' || ! self::is_available() ) {
            return $plaintext;
        }
        // Never double-encrypt.
        if ( self::is_encrypted( $plaintext ) ) {
            return $plaintext;
        }

        $nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
        $out    = self::PREFIX . base64_encode( $nonce . $cipher );

        // Best-effort wipe. Only the native libsodium extension can do this;
        // the bundled sodium_compat polyfill throws on sodium_memzero(), so
        // guard on the real extension being loaded.
        if ( extension_loaded( 'sodium' ) ) {
            sodium_memzero( $plaintext );
        }
        return $out;
    }

    /**
     * Decrypt a value produced by encrypt(). Legacy (non-prefixed) values are
     * returned as-is so plaintext data keeps working until re-saved. Returns ''
     * if a prefixed value cannot be decrypted (tampered, or salts rotated).
     */
    public static function decrypt( string $value ): string {
        if ( $value === '' || ! self::is_encrypted( $value ) ) {
            return $value; // legacy plaintext, or nothing to do
        }
        if ( ! self::is_available() ) {
            return '';
        }

        $raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
        if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
            return '';
        }

        $nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

        $plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
        return $plain === false ? '' : $plain;
    }

    /**
     * Deterministic keyed lookup index for a secret (e.g. an API key), so an
     * encrypted-at-rest value can still be resolved by an exact-match query.
     * Not reversible; used only for equality lookups.
     */
    public static function lookup_hash( string $value ): string {
        return hash_hmac( 'sha256', $value, wp_salt( 'secure_auth' ) );
    }
}
