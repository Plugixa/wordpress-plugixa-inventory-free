<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypting the handful of secrets the inventory has to store and later replay.
 *
 * WHAT THIS PROTECTS AGAINST, AND WHAT IT DOES NOT. Read this before trusting it with anything.
 *
 * An IMAP password cannot be hashed, because the inventory has to present the original to the mail server
 * on every fetch. So it is encrypted, and the key is derived from the site's own salts in
 * `wp-config.php`. That defends against exactly one thing — **a leaked database**: an SQL injection,
 * a stolen backup, a shared-host neighbour reading tables. In those, the ciphertext is useless
 * without a file the attacker does not have.
 *
 * It does NOT defend against anyone who can read `wp-config.php`, because they can derive the key.
 * That is not a flaw to be fixed by trying harder; it is the ceiling for any application that must
 * decrypt without a human present. WordPress has no key store, and inventing one that lives on the
 * same disk would move the problem rather than solve it. The honest summary is: this makes a database
 * leak survivable and a filesystem compromise still fatal, which is the same bargain WordPress itself
 * makes with its auth cookies.
 *
 * THE SALTS CAN CHANGE. Rotating salts is a normal, recommended response to a compromise, and it
 * makes every existing ciphertext undecryptable. {@see decrypt()} therefore returns null rather than
 * throwing, and every caller must treat null as "ask the user for this again" instead of as an error.
 * An account that stops fetching after a salt rotation is a support ticket; a fatal on every admin
 * page is an outage.
 */
final class Secrets {

	/**
	 * Prefixes the ciphertext so the scheme can be changed later without guessing at old rows.
	 */
	private const VERSION = 'v1';

	private const CIPHER = 'aes-256-gcm';

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $plaintext The secret.
	 *
	 * @return string An opaque, storable string. Empty in, empty out.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! self::available() ) {
			return '';
		}

		$iv  = random_bytes( 12 );
		$tag = '';

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $ciphertext ) {
			return '';
		}

		// IV and tag travel with the ciphertext. Neither is secret; both are required to decrypt, and
		// the tag is what makes tampering detectable rather than merely producing garbage.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- making raw AES-GCM bytes storable in a text column, not obfuscating anything.
		return self::VERSION . ':' . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Recover a stored secret.
	 *
	 * @param string $stored What {@see encrypt()} produced.
	 *
	 * @return string|null Null when it cannot be read — a rotated salt, a truncated column, a row
	 *                     written by a future version. Callers must ask the user again, not fail.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( '' === $stored || ! self::available() ) {
			return null;
		}

		$parts = explode( ':', $stored, 2 );

		if ( 2 !== count( $parts ) || self::VERSION !== $parts[0] ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the inverse of the line above; strict mode, and a false return is handled.
		$raw = base64_decode( $parts[1], true );

		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Whether secrets can be stored at all on this install.
	 *
	 * A host without OpenSSL cannot keep an IMAP password safely, and the right response is to refuse
	 * the feature rather than to store the password in the clear and say nothing.
	 */
	public static function available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( self::CIPHER, (array) openssl_get_cipher_methods(), true );
	}

	/**
	 * The 32-byte key, derived from the site's salts.
	 *
	 * `wp_salt('secure_auth')` rather than a constant of our own: it is already unique per install,
	 * already outside the database, and already the thing a site owner rotates when they think they
	 * have been breached — so the inventory's secrets inherit that lifecycle instead of needing their own.
	 */
	private static function key(): string {
		return hash( 'sha256', 'plugixa-inventory|' . wp_salt( 'secure_auth' ), true );
	}
}
