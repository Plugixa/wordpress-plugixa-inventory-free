<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The inventory's one way to send an email.
 *
 * THE FIRST EMAIL IN THE FAMILY, AND WHY IT LIVES IN THE HOST. `wp_mail` appeared zero times in any
 * Plugixa plugin until now. The scheduler needs it for booking confirmations, and the host's own
 * roadmap needs it for Web-to-Lead — so the generic act of sending is core infrastructure, not a
 * scheduler concern. What the scheduler owns is the CONTENT (subject, body, the .ics); what this owns
 * is the mechanics every sender repeats: the HTML content-type, the From/Reply-To headers, and the
 * one place a site's outgoing mail can be observed or filtered.
 *
 * Static like `Settings` and `Capabilities`, for the same reason: there is exactly one way to do
 * this and nothing to inject. Tests capture it through WordPress's own `pre_wp_mail`/`wp_mail`
 * plumbing rather than a mock of this class.
 *
 * DELIBERATELY THIN. It does not queue, retry or template. Retrying is the SMTP layer's job (an
 * MTA or a plugin like WP Mail SMTP), templating is the caller's, and a queue is a database table
 * this does not want to own. If a site needs reliable delivery it installs an SMTP plugin, which
 * `wp_mail` already defers to — wrapping that here would only get in its way.
 */
final class Mailer {

	/**
	 * Send one email.
	 *
	 * @param string|string[]      $to      One or more recipient addresses.
	 * @param string               $subject The subject line (plain text).
	 * @param string               $body    The message body. HTML unless $options['html'] is false.
	 * @param array<string, mixed> $options From/Reply-To/Cc/Bcc/attachments/html overrides. Keys:
	 *                                      - html        bool   Send as text/html. Default true.
	 *                                      - from        string From address. Default WordPress's.
	 *                                      - from_name   string From display name.
	 *                                      - reply_to    string Reply-To address.
	 *                                      - cc          string[] Cc addresses.
	 *                                      - bcc         string[] Bcc addresses.
	 *                                      - attachments string[] Absolute file paths to attach.
	 *                                      - headers     string[] Extra header lines, e.g.
	 *                                        `List-Unsubscribe`. Validated; see safe_header_line().
	 *
	 * @return bool Whether WordPress accepted the message for delivery. Acceptance is not receipt —
	 *              `wp_mail` returning true means it handed off cleanly, not that it arrived.
	 */
	public static function send( string|array $to, string $subject, string $body, array $options = array() ): bool {
		$headers = self::headers( $options );

		$attachments = array();

		foreach ( (array) ( $options['attachments'] ?? array() ) as $path ) {
			// Only real, readable files. A missing attachment must not abort a confirmation the
			// invitee is waiting on — the email is still worth sending without the .ics.
			if ( is_string( $path ) && '' !== $path && is_readable( $path ) ) {
				$attachments[] = $path;
			}
		}

		/**
		 * Fires immediately before the inventory sends an email, so a site can log or divert it.
		 *
		 * @param string|string[]      $to      Recipients.
		 * @param string               $subject Subject.
		 * @param array<string, mixed> $options The send options.
		 */
		do_action( 'plugixa_inventory_before_send_mail', $to, $subject, $options );

		return wp_mail( $to, $subject, $body, $headers, $attachments );
	}

	/**
	 * Build the header lines for one send.
	 *
	 * @param array<string, mixed> $options Send options.
	 *
	 * @return string[]
	 */
	private static function headers( array $options ): array {
		$headers = array();

		$html = $options['html'] ?? true;
		if ( $html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}

		$from      = isset( $options['from'] ) ? sanitize_email( (string) $options['from'] ) : '';
		$from_name = isset( $options['from_name'] ) ? sanitize_text_field( (string) $options['from_name'] ) : '';

		if ( '' !== $from ) {
			$headers[] = '' !== $from_name
				? sprintf( 'From: %s <%s>', self::header_safe( $from_name ), $from )
				: sprintf( 'From: %s', $from );
		}

		$reply_to = isset( $options['reply_to'] ) ? sanitize_email( (string) $options['reply_to'] ) : '';
		if ( '' !== $reply_to ) {
			$headers[] = sprintf( 'Reply-To: %s', $reply_to );
		}

		foreach ( array( 'cc' => 'Cc', 'bcc' => 'Bcc' ) as $key => $label ) {
			foreach ( (array) ( $options[ $key ] ?? array() ) as $address ) {
				$address = sanitize_email( (string) $address );
				if ( '' !== $address ) {
					$headers[] = sprintf( '%s: %s', $label, $address );
				}
			}
		}

		foreach ( (array) ( $options['headers'] ?? array() ) as $header ) {
			$header = self::safe_header_line( (string) $header );

			if ( '' !== $header ) {
				$headers[] = $header;
			}
		}

		return $headers;
	}

	/**
	 * Validate a caller-supplied header line.
	 *
	 * ADDED FOR `List-Unsubscribe`, and guarded because an arbitrary header line is the classic mail
	 * injection: a `\r\n` in the middle of one ends the header and starts another, which is how a
	 * message acquires a second `Bcc` nobody asked for. Newlines are stripped rather than the line
	 * being refused, so a stray carriage return in a legitimate value does not silently drop the
	 * header — and the shape is checked, because "not a header at all" is a bug worth surfacing as an
	 * absent header rather than as a corrupted message.
	 *
	 * `From`, `Reply-To`, `Cc`, `Bcc` and `Content-Type` are refused here: they have their own options
	 * above, and allowing a second copy would leave which one wins up to the mail transport.
	 *
	 * @param string $header The proposed line.
	 *
	 * @return string The line, or '' if it is not usable.
	 */
	private static function safe_header_line( string $header ): string {
		$header = trim( str_replace( array( "\r", "\n", "\0" ), '', $header ) );

		if ( 1 !== preg_match( '/^([A-Za-z][A-Za-z0-9-]*):\s*(.+)$/', $header, $parts ) ) {
			return '';
		}

		$reserved = array( 'from', 'reply-to', 'cc', 'bcc', 'content-type', 'to', 'subject' );

		return in_array( strtolower( $parts[1] ), $reserved, true ) ? '' : $header;
	}

	/**
	 * Strip anything that could break out of a header line — a newline in a display name is a header
	 * injection, and `sanitize_text_field` already collapses them, but a name with a comma or angle
	 * bracket would still confuse the address parser, so those go too.
	 *
	 * @param string $value A display name.
	 */
	private static function header_safe( string $value ): string {
		return trim( str_replace( array( '<', '>', ',', '"', "\r", "\n" ), ' ', $value ) );
	}
}
