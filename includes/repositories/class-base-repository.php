<?php
/**
 * Base repository: helper $wpdb comuni.
 *
 * @package EscapeManager
 */

namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Base_Repository {

	protected \wpdb $wpdb;
	protected string $table;

	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'em_' . $this->table_name();
	}

	abstract protected function table_name(): string;

	public function find( int $id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function delete( int $id ): bool {
		return (bool) $this->wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
	}

	public function soft_delete( int $id ): bool {
		return (bool) $this->wpdb->update(
			$this->table,
			array( 'deleted_at' => em_now_utc() ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	protected function timestamps_for_insert(): array {
		return array( 'created_at' => em_now_utc(), 'updated_at' => em_now_utc() );
	}

	protected function timestamps_for_update(): array {
		return array( 'updated_at' => em_now_utc() );
	}
}
