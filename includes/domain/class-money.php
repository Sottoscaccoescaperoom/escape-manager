<?php
/**
 * Value object Money: tutti gli importi sono in centesimi.
 *
 * @package EscapeManager
 */

namespace EscapeManager\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Money {

	public function __construct( public readonly int $cents, public readonly string $currency = 'EUR' ) {}

	public static function from_cents( int $cents, string $currency = 'EUR' ): self {
		return new self( $cents, $currency );
	}

	public static function from_units( float $units, string $currency = 'EUR' ): self {
		return new self( (int) round( $units * 100 ), $currency );
	}

	public function add( Money $other ): self {
		return new self( $this->cents + $other->cents, $this->currency );
	}

	public function subtract( Money $other ): self {
		return new self( $this->cents - $other->cents, $this->currency );
	}

	public function multiply( int $factor ): self {
		return new self( $this->cents * $factor, $this->currency );
	}

	public function to_units(): float {
		return $this->cents / 100;
	}

	public function format(): string {
		return number_format( $this->to_units(), 2, ',', '.' ) . ' ' . $this->currency;
	}
}
