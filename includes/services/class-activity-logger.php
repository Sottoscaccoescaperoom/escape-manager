<?php
namespace EscapeManager\Services;

use EscapeManager\Repositories\Activity_Log_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activity_Logger {

	public function __construct( private Activity_Log_Repository $repo = new Activity_Log_Repository() ) {}

	public function log( string $action, ?string $entity_type = null, ?int $entity_id = null, mixed $old = null, mixed $new = null ): void {
		$this->repo->record( array(
			'user_id'     => get_current_user_id() ?: null,
			'action'      => $action,
			'entity_type' => $entity_type,
			'entity_id'   => $entity_id,
			'old_value'   => $old,
			'new_value'   => $new,
		) );
	}
}
