<?php
/**
 * Entità Booking + stati canonici.
 *
 * @package EscapeManager
 */

namespace EscapeManager\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Booking {

	public const STATUS_TEMPORARY_LOCK       = 'temporary_lock';
	public const STATUS_BOOKING_IN_PROGRESS  = 'booking_in_progress';
	public const STATUS_CONFIRMED            = 'confirmed';
	public const STATUS_NOT_PAID             = 'not_paid';
	public const STATUS_AWAITING_PAYMENT     = 'awaiting_payment';
	public const STATUS_CANCELLED            = 'cancelled';
	public const STATUS_UNSUCCESSFUL_BOOKING = 'unsuccessful_booking';
	public const STATUS_COMPLETED            = 'completed';
	public const STATUS_NO_SHOW              = 'no_show';

	public const PAYMENT_UNPAID          = 'unpaid';
	public const PAYMENT_PARTIALLY_PAID  = 'partially_paid';
	public const PAYMENT_PAID            = 'paid';
	public const PAYMENT_REFUNDED        = 'refunded';
	public const PAYMENT_AWAITING        = 'awaiting_payment';

	public const ACTIVE_STATUSES = array(
		self::STATUS_BOOKING_IN_PROGRESS,
		self::STATUS_CONFIRMED,
		self::STATUS_NOT_PAID,
		self::STATUS_AWAITING_PAYMENT,
		self::STATUS_COMPLETED,
	);

	public const ALL_STATUSES = array(
		self::STATUS_TEMPORARY_LOCK,
		self::STATUS_BOOKING_IN_PROGRESS,
		self::STATUS_CONFIRMED,
		self::STATUS_NOT_PAID,
		self::STATUS_AWAITING_PAYMENT,
		self::STATUS_CANCELLED,
		self::STATUS_UNSUCCESSFUL_BOOKING,
		self::STATUS_COMPLETED,
		self::STATUS_NO_SHOW,
	);

	public static function is_active( string $status ): bool {
		return in_array( $status, self::ACTIVE_STATUSES, true );
	}

	public static function generate_code( string $prefix = 'EM' ): string {
		$prefix   = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $prefix ) ) ?: 'EM';
		$rand     = strtoupper( substr( bin2hex( random_bytes( 3 ) ), 0, 6 ) );
		$ts_part  = strtoupper( base_convert( (string) time(), 10, 36 ) );
		return "{$prefix}-{$ts_part}-{$rand}";
	}
}
