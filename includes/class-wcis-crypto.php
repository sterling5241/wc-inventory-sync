<?php
defined( 'ABSPATH' ) || exit;

/**
 * API key generation and HMAC request signing/verification.
 */
class WCIS_Crypto {

	public static function generate_key() {
		return bin2hex( random_bytes( 16 ) ); // 32 hex chars.
	}

	public static function generate_secret() {
		return bin2hex( random_bytes( 32 ) ); // 64 hex chars.
	}

	public static function sign( $timestamp, $raw_body, $secret ) {
		return hash_hmac( 'sha256', $timestamp . "\n" . $raw_body, $secret );
	}

	public static function verify( $timestamp, $raw_body, $secret, $signature ) {
		if ( empty( $timestamp ) || empty( $signature ) || empty( $secret ) ) {
			return false;
		}
		// Replay protection: reject requests whose timestamp is more than 5 minutes off.
		if ( abs( time() - (int) $timestamp ) > 5 * MINUTE_IN_SECONDS ) {
			return false;
		}
		$expected = self::sign( $timestamp, $raw_body, $secret );
		return hash_equals( $expected, (string) $signature );
	}
}
