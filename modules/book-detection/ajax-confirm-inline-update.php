<?php
// modules/book-detection/ajax-confirm-inline-update.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Edita inline un campo de wp_politeia_book_confirm y guarda automáticamente.
 * Espera POST:
 *  - nonce
 *  - id        (int)    id de la fila en book_confirm (debe pertenecer al usuario actual y estar 'pending')
 *  - field     (string) 'title'|'author'|'year'|'isbn'
 *  - value     (string|int)
 * Devuelve:
 *  success:true, data:{ row:{...}, merged_into:int|null, message:string }
 */
function politeia_confirm_inline_update_ajax() {
	try {
		check_ajax_referer('politeia-chatgpt-nonce', 'nonce');

		if ( empty($_POST['id']) || empty($_POST['field']) ) {
			throw new Exception('Missing parameters.');
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'politeia_book_confirm';

		$id    = (int) $_POST['id'];
		$field = sanitize_key( $_POST['field'] );

		// Cargar fila y validar propiedad/estado
		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$tbl} WHERE id=%d LIMIT 1", $id),
			ARRAY_A
		);
		if ( ! $row ) throw new Exception('Row not found.');
		$user_id = (int) get_current_user_id();
		if ( (int) $row['user_id'] !== $user_id ) throw new Exception('Forbidden.');
		if ( $row['status'] !== 'pending' ) throw new Exception('Only pending rows can be edited.');

		// Sanitizar valor
		$val_raw = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';
		switch ($field) {
			case 'title':
			case 'author':
				$value = sanitize_text_field( (string) $val_raw );
				break;
			case 'year':
				$value = (int) preg_replace('/[^0-9]/', '', (string) $val_raw );
				if ($value <= 0) $value = null;
				break;
			case 'isbn':
				$value = preg_replace('/[^0-9Xx\-]/', '', (string) $val_raw );
				$value = $value !== '' ? $value : null;
				break;
			default:
				throw new Exception('Invalid field.');
		}

		$update = [];
		$fmt    = [];

		// Si cambia title/author -> recalcular normalized + hash y volver a hacer best-match
		$title  = $row['title'];
		$author = $row['author'];
		if ($field === 'title')  $title  = $value;
		if ($field === 'author') $author = $value;

		if ($field === 'title' || $field === 'author') {
			// Normalización/hashes
			if ( ! function_exists('politeia__normalize_text') || ! function_exists('politeia__title_author_hash') ) {
				// fallback muy seguro (por si no están incluidas las helpers)
				$norm = static function($s){
					$s = wp_strip_all_tags( (string)$s );
					$s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
					$s = preg_replace('/\s+/u', ' ', $s);
					return trim($s);
				};
				$norm_title  = $norm($title);
				$norm_author = $norm($author);
				$hash        = hash('sha256', strtolower($norm_title).'|'.strtolower($norm_author));
			} else {
				$norm_title  = politeia__normalize_text($title);
				$norm_author = politeia__normalize_text($author);
				$hash        = politeia__title_author_hash($title, $author);
			}

			$update['title']             = $title;
			$update['author']            = $author;
			$update['normalized_title']  = $norm_title;
			$update['normalized_author'] = $norm_author;
			$update['title_author_hash'] = $hash;
			$fmt = array_merge($fmt, ['%s','%s','%s','%s','%s']);

			// Anti-duplicados: ¿ya hay otra fila pending del mismo user+hash?
			$dup_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$tbl}
					 WHERE user_id=%d AND status='pending' AND title_author_hash=%s AND id<>%d
					 LIMIT 1",
					$user_id, $hash, $id
				)
			);
			$merged_into = null;

			if ( $dup_id ) {
				// Conservamos la fila con id más bajo y borramos la otra
				$keep = min($id, $dup_id);
				$drop = max($id, $dup_id);

				if ( $drop === $id ) {
					// Actualizar la que se mantiene y borrar la actual
					$wpdb->update($tbl, $update, ['id' => $keep], $fmt, ['%d']);
					$wpdb->delete($tbl, ['id' => $id], ['%d']);
					$merged_into = $keep;

					$final = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tbl} WHERE id=%d", $keep), ARRAY_A);
					wp_send_json_success([
						'row'         => politeia__slim_confirm_row($final),
						'merged_into' => $merged_into,
						'message'     => 'Merged into existing pending item.',
					]);
				} else {
					// Borrar el otro y seguir actualizando éste
					$wpdb->delete($tbl, ['id' => $dup_id], ['%d']);
				}
			}

			// Best-match interno (no insertamos nada aquí)
			if ( class_exists('Politeia_Book_DB_Handler') ) {
				$dbh   = new Politeia_Book_DB_Handler();
				$match = $dbh->find_best_match_internal($title, $author);
				$update['match_method']    = $match['method'] ?? 'none';
				$update['matched_book_id'] = isset($match['match']['id']) ? (int) $match['match']['id'] : null;
				$fmt[] = '%s';
				$fmt[] = '%d';
			}

			// Enriquecer con fuente externa (año/ISBN) de forma ligera
			if ( class_exists('Politeia_Book_External_API') ) {
				$ext  = new Politeia_Book_External_API();
				$best = $ext->search_best_match($title, $author, ['limit_per_provider' => 3]);
				if ($best) {
					if (!empty($best['isbn']))  { $update['external_isbn']   = (string) $best['isbn'];   $fmt[] = '%s'; }
					if (!empty($best['source'])){ $update['external_source'] = (string) $best['source']; $fmt[] = '%s'; }
					if (isset($best['score']))  { $update['external_score']  = (float)  $best['score'];  $fmt[] = '%f'; }
					if (!empty($best['year']))  { $update['year']            = (int)    $best['year'];   $fmt[] = '%d'; }
				}
			}
		} else {
			// Campos simples (year, isbn)
			if ($field === 'year') { $update['year'] = $value; $fmt[] = '%d'; }
			if ($field === 'isbn') { $update['external_isbn'] = $value; $fmt[] = '%s'; }
		}

		$update['updated_at'] = current_time('mysql', 1);
		$fmt[] = '%s';

		$wpdb->update($tbl, $update, ['id' => $id], $fmt, ['%d']);

		$final = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tbl} WHERE id=%d", $id), ARRAY_A);
		wp_send_json_success([
			'row'         => politeia__slim_confirm_row($final),
			'merged_into' => null,
			'message'     => 'Saved',
		]);

	} catch (Throwable $e) {
		error_log('[politeia_confirm_inline_update] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
		wp_send_json_error( WP_DEBUG ? $e->getMessage() : 'internal_error' );
	}
}
add_action('wp_ajax_politeia_confirm_inline_update', 'politeia_confirm_inline_update_ajax');
// normalmente no exponemos a visitantes
// add_action('wp_ajax_nopriv_politeia_confirm_inline_update', 'politeia_confirm_inline_update_ajax');

/** Slimmer row for UI */
if ( ! function_exists('politeia__slim_confirm_row') ) {
	function politeia__slim_confirm_row($r) {
		if (!$r) return null;
		return [
			'id'               => (int) $r['id'],
			'title'            => (string) $r['title'],
			'author'           => (string) $r['author'],
			'year'             => isset($r['year']) && $r['year'] !== '' ? (int)$r['year'] : null,
			'title_author_hash'=> (string) ($r['title_author_hash'] ?? ''),
			'match_method'     => (string) ($r['match_method'] ?? 'none'),
			'matched_book_id'  => isset($r['matched_book_id']) ? (int)$r['matched_book_id'] : null,
			'external_isbn'    => isset($r['external_isbn']) ? (string)$r['external_isbn'] : null,
			'external_source'  => isset($r['external_source']) ? (string)$r['external_source'] : null,
			'external_score'   => isset($r['external_score']) ? (float)$r['external_score'] : null,
		];
	}
}
