<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activity_Log_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'activity_logs';
	}

	public function record( array $data ): int {
		$row = array(
			'user_id'     => isset( $data['user_id'] ) ? (int) $data['user_id'] : null,
			'action'      => (string) $data['action'],
			'entity_type' => $data['entity_type'] ?? null,
			'entity_id'   => isset( $data['entity_id'] ) ? (int) $data['entity_id'] : null,
			'old_value'   => isset( $data['old_value'] ) ? wp_json_encode( $data['old_value'] ) : null,
			'new_value'   => isset( $data['new_value'] ) ? wp_json_encode( $data['new_value'] ) : null,
			'ip_address'  => $data['ip_address'] ?? ( $_SERVER['REMOTE_ADDR'] ?? null ),
			'user_agent'  => $data['user_agent'] ?? ( $_SERVER['HTTP_USER_AGENT'] ?? null ),
			'created_at'  => em_now_utc(),
		);
		$this->wpdb->insert( $this->table, $row );
		return (int) $this->wpdb->insert_id;
	}

	public function paginate( array $filters = array(), int $page = 1, int $per_page = 50 ): array {
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['entity_type'] ) ) {
			$where[]  = 'entity_type = %s';
			$params[] = (string) $filters['entity_type'];
		}
		if ( ! empty( $filters['entity_id'] ) ) {
			$where[]  = 'entity_id = %d';
			$params[] = (int) $filters['entity_id'];
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( $page - 1 ) * $per_page );
		$sql       = "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$rows_p    = array_merge( $params, array( $per_page, $offset ) );
		$rows      = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $rows_p ), ARRAY_A ) ?: array();
		$total     = $params
			? (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}", $params ) )
			: (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}" );
		return array( 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page );
	}
}
