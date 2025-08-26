<?php
// modules/book-detection/ajax-confirm-inline-update.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Inline update for a pending confirm row (title|author).
 * POST: nonce, id, field ('title'|'author'), value
 * OUT: { success:true, data:{ id, title, author, hash } }
 */
function politeia_confirm_update_field_ajax() {
	try {
		check_ajax_referer('politeia-chatgpt-nonce', 'nonce');

		$id    = isset($_POST['id'])    ? (int) $_POST['id'] : 0;
		$field = isset($_POST['field']) ? sanitize_key($_POST['field']) : '';
		$value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';

		if ( ! $id || ! in_array($field, ['title','author'], true) ) {
			wp_send_json_error('invalid_request');
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'politeia_book_confirm';
		$user_id = get_current_user_id();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tbl} WHERE id=%d AND user_id=%d AND status='pending' LIMIT 1",
				$id, $user_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			wp_send_json_error('not_found');
		}

		$value = trim( wp_strip_all_tags( (string) $value ) );
		if ( $value === '' ) {
			wp_send_json_error('empty_value');
		}

		// Helpers (fallback)
		if ( ! function_exists('politeia__normalize_text') ) {
			function politeia__normalize_text( $s ) {
				$s = (string) $s;
				$s = wp_strip_all_tags( $s );
				$s = html_entity_decode( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				$s = preg_replace( '/\s+/u', ' ', $s );
				$s = trim( $s );
				return $s;
			}
		}
		if ( ! function_exists('politeia__title_author_hash') ) {
			function politeia__title_author_hash( $title, $author ) {
				$t = strtolower( trim( politeia__normalize_text( $title ) ) );
				$a = strtolower( trim( politeia__normalize_text( $author ) ) );
				return hash( 'sha256', $t . '|' . $a );
			}
		}

		// Compose new values and recompute hash/normalized
		$title  = ($field === 'title')  ? $value : $row['title'];
		$author = ($field === 'author') ? $value : $row['author'];

		$norm_title  = politeia__normalize_text( $title );
		$norm_author = politeia__normalize_text( $author );
		$hash        = politeia__title_author_hash( $title, $author );

		// Avoid duplicate pending for same user+hash (merge by deleting the OTHER duplicate)
		$dup_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tbl}
				  WHERE user_id=%d AND status='pending' AND title_author_hash=%s AND id<>%d
				  LIMIT 1",
				$user_id, $hash, $id
			)
		);
		if ( $dup_id ) {
			$wpdb->delete( $tbl, [ 'id' => $dup_id, 'user_id' => $user_id ], [ '%d', '%d' ] );
		}

		$wpdb->update(
			$tbl,
			[
				'title'             => $title,
				'author'            => $author,
				'normalized_title'  => $norm_title,
				'normalized_author' => $norm_author,
				'title_author_hash' => $hash,
				'updated_at'        => current_time( 'mysql', 1 ),
			],
			[ 'id' => $id, 'user_id' => $user_id ],
			[ '%s','%s','%s','%s','%s','%s' ],
			[ '%d','%d' ]
		);

		wp_send_json_success([
			'id'     => $id,
			'title'  => $title,
			'author' => $author,
			'hash'   => $hash,
		]);

	} catch (Throwable $e) {
		error_log('[politeia_confirm_update_field] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
		wp_send_json_error( WP_DEBUG ? $e->getMessage() : 'internal_error' );
	}
}
add_action('wp_ajax_politeia_confirm_update_field',        'politeia_confirm_update_field_ajax');
add_action('wp_ajax_nopriv_politeia_confirm_update_field', 'politeia_confirm_update_field_ajax'); // si no quieres visitantes, coméntala
