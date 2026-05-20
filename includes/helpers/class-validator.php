<?php
/**
 * Validatori centralizzati.
 *
 * @package EscapeManager
 */

namespace EscapeManager\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Validator {

	public static function phone( string $phone ): ?string {
		$clean = preg_replace( '/[\s\-\(\)\.]/', '', $phone );
		if ( ! preg_match( '/^\+?\d{6,20}$/', $clean ) ) {
			return null;
		}
		return $clean;
	}

	public static function email( string $email ): ?string {
		$email = sanitize_email( $email );
		return is_email( $email ) ? $email : null;
	}

	public static function datetime_iso( string $value ): ?string {
		try {
			$dt = new \DateTimeImmutable( $value );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public static function to_utc( string $value, string $timezone = 'Europe/Rome' ): ?string {
		try {
			$tz = new \DateTimeZone( $timezone );
			$dt = new \DateTimeImmutable( $value, $tz );
			$dt = $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public static function to_local( string $utc_value, string $timezone = 'Europe/Rome' ): ?string {
		try {
			$dt = new \DateTimeImmutable( $utc_value, new \DateTimeZone( 'UTC' ) );
			$dt = $dt->setTimezone( new \DateTimeZone( $timezone ) );
			return $dt->format( 'c' );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public static function uuid_v4(): string {
		$bytes = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	public static function slugify( string $text ): string {
		return sanitize_title( $text );
	}

	public static function positive_int( mixed $value ): ?int {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$int = (int) $value;
		return $int >= 0 ? $int : null;
	}

	public static function required_string( mixed $value, int $max_len = 255 ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$trimmed = trim( $value );
		if ( $trimmed === '' ) {
			return null;
		}
		return mb_substr( $trimmed, 0, $max_len );
	}
}
