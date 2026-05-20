<?php
namespace EscapeManager\Services;

use EscapeManager\Repositories\Webhook_Queue_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatcher webhook outbound con retry exponential backoff.
 * Pesca dalla coda e POSTa al target. Su 5xx → riprova; su 4xx → mark failed.
 */
final class Webhook_Dispatcher_Service {

	public function __construct( private Webhook_Queue_Repository $queue = new Webhook_Queue_Repository() ) {}

	public function dispatch_due( int $batch = 50 ): array {
		$rows         = $this->queue->fetch_due( $batch );
		$sent         = 0;
		$rescheduled  = 0;
		$failed       = 0;
		$max_retries  = (int) em_setting( 'em_sottoscacco_max_retries', 5 );

		foreach ( $rows as $row ) {
			$target   = $row['target'];
			$endpoint = $this->endpoint_for( $target );
			$secret   = $this->secret_for( $target );

			if ( ! $endpoint || ! $secret ) {
				$this->queue->mark_failed( (int) $row['id'], 'Endpoint o secret mancanti per target ' . $target );
				$failed++;
				continue;
			}

			$response = wp_remote_post( $endpoint, array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/json',
				),
				'body'    => $row['payload'],
			) );

			if ( is_wp_error( $response ) ) {
				$attempts = (int) $row['attempts'] + 1;
				if ( $attempts >= $max_retries ) {
					$this->queue->mark_failed( (int) $row['id'], $response->get_error_message() );
					$failed++;
				} else {
					$delay_minutes = (int) pow( 2, $attempts ); // 2,4,8,16,32 min
					$next = gmdate( 'Y-m-d H:i:s', time() + $delay_minutes * 60 );
					$this->queue->reschedule( (int) $row['id'], $attempts, $next, $response->get_error_message() );
					$rescheduled++;
				}
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code >= 200 && $code < 300 ) {
				$this->queue->mark_sent( (int) $row['id'] );
				$sent++;
				continue;
			}

			// 4xx: client error, no retry
			if ( $code >= 400 && $code < 500 ) {
				$this->queue->mark_failed( (int) $row['id'], "HTTP {$code}: {$body}" );
				$failed++;
				continue;
			}

			// 5xx o altro: retry
			$attempts = (int) $row['attempts'] + 1;
			if ( $attempts >= $max_retries ) {
				$this->queue->mark_failed( (int) $row['id'], "HTTP {$code}: {$body}" );
				$failed++;
			} else {
				$delay_minutes = (int) pow( 2, $attempts );
				$next          = gmdate( 'Y-m-d H:i:s', time() + $delay_minutes * 60 );
				$this->queue->reschedule( (int) $row['id'], $attempts, $next, "HTTP {$code}: {$body}" );
				$rescheduled++;
			}
		}

		return compact( 'sent', 'rescheduled', 'failed' );
	}

	private function endpoint_for( string $target ): ?string {
		if ( $target === 'sottoscacco' ) {
			return em_setting( 'em_sottoscacco_webhook_url', '' ) ?: null;
		}
		return null;
	}

	private function secret_for( string $target ): ?string {
		if ( $target === 'sottoscacco' ) {
			return em_setting( 'em_sottoscacco_webhook_secret', '' ) ?: null;
		}
		return null;
	}
}
