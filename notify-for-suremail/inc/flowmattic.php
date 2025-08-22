<?php


if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SureMail ↔ FlowMattic bridge
 * Drop-in file: flowmattic.php (child site)
 */
defined('ABSPATH') || exit;

/**
 * Normalize headers into an associative array.
 */
if ( ! function_exists( 'suremail_flowmattic_normalize_headers' ) ) {
	function suremail_flowmattic_normalize_headers( $headers ) {
		if ( empty( $headers ) ) return [];

		// Already an array: try to coerce into key=>value map.
		if ( is_array( $headers ) ) {
			$out = [];
			foreach ( $headers as $k => $v ) {
				if ( is_numeric( $k ) ) {
					$line = trim( (string) $v );
					if ( $line === '' ) continue;
					$p = strpos( $line, ':' );
					if ( $p !== false ) {
						$key = trim( substr( $line, 0, $p ) );
						$val = trim( substr( $line, $p + 1 ) );
						$out[ $key ] = $val;
					} else {
						$out[] = $line;
					}
				} else {
					$out[ $k ] = is_array( $v ) ? implode( ',', $v ) : $v;
				}
			}
			return $out;
		}

		// String case: split lines “Key: Value”.
		$out = [];
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $headers ) as $line ) {
			$line = trim( $line );
			if ( $line === '' ) continue;
			$p = strpos( $line, ':' );
			if ( $p !== false ) {
				$key = trim( substr( $line, 0, $p ) );
				$val = trim( substr( $line, $p + 1 ) );
				$out[ $key ] = $val;
			}
		}
		return $out;
	}
}

/**
 * Common site/time meta.
 */
if ( ! function_exists( 'suremail_flowmattic_now_meta' ) ) {
	function suremail_flowmattic_now_meta() {
		return [
			'site_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'  => site_url(),
			'timestamp' => wp_date( 'Y-m-d H:i:s' ),
			'timezone'  => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : ( get_option( 'timezone_string' ) ?: 'UTC' ),
		];
	}
}

/**
 * Emit a FlowMattic-friendly action with a consistent payload.
 * Filter: `suremail_flowmattic_payload` to customize/augment the payload.
 */
if ( ! function_exists( 'suremail_flowmattic_emit' ) ) {
	function suremail_flowmattic_emit( $event, array $payload, $raw = null ) {
		$payload['event'] = $event;
		$payload = apply_filters( 'suremail_flowmattic_payload', $payload, $raw, $event );
		// FlowMattic “Custom Action” trigger: use one of these hook names.
		do_action( 'suremail_notify_' . $event, $payload );
	}
}

/**
 * SENT → suremail_notify_sent
 * $mail_data = ['to','subject','message','headers','attachments']
 */
add_action( 'wp_mail_succeeded', function( $mail_data ) {
	$headers = suremail_flowmattic_normalize_headers( $mail_data['headers'] ?? [] );
	$to      = $mail_data['to'] ?? [];
	if ( ! is_array( $to ) ) $to = array_map( 'trim', explode( ',', (string) $to ) );

	// If SureMails stamps a connection header, pick it up.
	$conn_id = '';
	if ( isset( $headers['X-SureMail-Connection'] ) ) {
		$conn_id = sanitize_text_field( $headers['X-SureMail-Connection'] );
	}

	$payload = [
		'to'           => array_values( array_filter( $to ) ),
		'subject'      => (string) ( $mail_data['subject'] ?? '' ),
		'message'      => (string) ( $mail_data['message'] ?? '' ),
		'headers'      => $headers,
		'attachments'  => $mail_data['attachments'] ?? [],
		'connection_id'=> $conn_id,
	] + suremail_flowmattic_now_meta();

	suremail_flowmattic_emit( 'sent', $payload, $mail_data );
}, 99, 1 );

/**
 * FAILED → suremail_notify_failed
 * $wp_error is WP_Error with ->get_error_data() including mail fields.
 */
add_action( 'wp_mail_failed', function( $wp_error ) {
	$data    = is_wp_error( $wp_error ) ? (array) $wp_error->get_error_data() : [];
	$headers = suremail_flowmattic_normalize_headers( $data['headers'] ?? [] );
	$to      = $data['to'] ?? [];
	if ( ! is_array( $to ) ) $to = array_map( 'trim', explode( ',', (string) $to ) );

	$conn_id = '';
	if ( isset( $headers['X-SureMail-Connection'] ) ) {
		$conn_id = sanitize_text_field( $headers['X-SureMail-Connection'] );
	}

	$payload = [
		'to'           => array_values( array_filter( $to ) ),
		'subject'      => (string) ( $data['subject'] ?? '' ),
		'message'      => (string) ( $data['message'] ?? '' ),
		'headers'      => $headers,
		'attachments'  => $data['attachments'] ?? [],
		'error_code'   => is_wp_error( $wp_error ) ? $wp_error->get_error_code() : '',
		'error_message'=> is_wp_error( $wp_error ) ? $wp_error->get_error_message() : '',
		'phpmailer_exception_code' => $data['phpmailer_exception_code'] ?? '',
		'connection_id'=> $conn_id,
	] + suremail_flowmattic_now_meta();

	suremail_flowmattic_emit( 'failed', $payload, $wp_error );
}, 99, 1 );

/**
 * BLOCKED → suremail_notify_blocked
 * Your custom event; assume $info = ['reason'=>..., 'mail'=>[to,subject,message,headers,attachments]]
 */
add_action( 'suremails_mail_blocked', function( $info ) {
	$info    = is_array( $info ) ? $info : [];
	$mail    = ( isset( $info['mail'] ) && is_array( $info['mail'] ) ) ? $info['mail'] : [];
	$headers = suremail_flowmattic_normalize_headers( $mail['headers'] ?? [] );
	$to      = $mail['to'] ?? [];
	if ( ! is_array( $to ) ) $to = array_map( 'trim', explode( ',', (string) $to ) );

	$conn_id = '';
	if ( isset( $headers['X-SureMail-Connection'] ) ) {
		$conn_id = sanitize_text_field( $headers['X-SureMail-Connection'] );
	}

	$payload = [
		'to'           => array_values( array_filter( $to ) ),
		'subject'      => (string) ( $mail['subject'] ?? '' ),
		'message'      => (string) ( $mail['message'] ?? '' ),
		'headers'      => $headers,
		'attachments'  => $mail['attachments'] ?? [],
		'reason'       => (string) ( $info['reason'] ?? '' ),
		'connection_id'=> $conn_id,
	] + suremail_flowmattic_now_meta();

	suremail_flowmattic_emit( 'blocked', $payload, $info );
}, 99, 1 );
