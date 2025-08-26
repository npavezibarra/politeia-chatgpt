<?php
// modules/buttons/class-buttons-confirm-controller.php
if ( ! defined('ABSPATH') ) exit;

/**
 * AJAX: Confirmar libros (uno o varios).
 * - Asegura el libro en wp_politeia_books (best-match o inserción)
 * - Asegura el vínculo en wp_politeia_user_books
 * - Si todo OK -> elimina el/los pendientes desde wp_politeia_book_confirm
 *
 * Requiere que el plugin "Politeia Reading" haya creado sus tablas.
 */

//
// Carga defensiva de dependencias
//
if ( ! class_exists('Politeia_Book_DB_Handler') ) {
	// Intento 1: vía helper del plugin principal, si existe
	if ( function_exists('politeia_chatgpt_safe_require') ) {
		politeia_chatgpt_safe_require('modules/book-detection/class-book-db-handler.php');
	}
	// Intento 2: ruta relativa por si se invoca directamente
	if ( ! class_exists('Politeia_Book_DB_Handler') ) {
		$maybe = dirname(__DIR__) . '/book-detection/class-book-db-handler.php';
		if ( file_exists($maybe) ) require_once $maybe;
	}
}

//
// Helpers locales para normalizar y hashear igual que la cola
//
if ( ! function_exists( 'politeia__normalize_text' ) ) {
	function politeia__normalize_text( $s ) {
		$s = (string) $s;
		$s = wp_strip_all_tags( $s );
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$s = preg_replace( '/\s+/u', ' ', $s );
		$s = trim( $s );
		return $s;
	}
}
if ( ! function_exists( 'politeia__title_author_hash' ) ) {
	function politeia__title_author_hash( $title, $author ) {
		$t = strtolower( trim( politeia__normalize_text( $title ) ) );
		$a = strtolower( trim( politeia__normalize_text( $author ) ) );
		return hash( 'sha256', $t . '|' . $a );
	}
}

/**
 * Nucleo: procesa una lista de items [{title,author,year?}] para el usuario actual.
 * - Devuelve conteos y errores.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array{confirmed:int, errors:int, details:array}
 */
function politeia_buttons_confirm_process_items( array $items ) {
	if ( ! is_user_logged_in() ) {
		return [ 'confirmed' => 0, 'errors' => count($items), 'details' => [ 'error' => 'not_logged_in' ] ];
	}

	global $wpdb;
	$user_id     = get_current_user_id();
	$tbl_confirm = $wpdb->prefix . 'politeia_book_confirm';
	$tbl_books   = $wpdb->prefix . 'politeia_books';

	$db  = new Politeia_Book_DB_Handler();
	$res = $db->is_ready();
	if ( is_wp_error( $res ) ) {
		return [
			'confirmed' => 0,
			'errors'    => count($items),
			'details'   => [ 'error' => $res->get_error_message() ],
		];
	}

	$confirmed = 0;
	$errors    = 0;
	$details   = [];

	foreach ( $items as $idx => $it ) {
		$title  = isset($it['title'])  ? trim((string)$it['title'])  : '';
		$author = isset($it['author']) ? trim((string)$it['author']) : '';
		$year   = isset($it['year'])   ? (int)$it['year']            : null;

		if ( $title === '' || $author === '' ) {
			$errors++;
			$details[] = [ 'index' => $idx, 'ok' => false, 'reason' => 'invalid_item' ];
			continue;
		}

		$hash  = politeia__title_author_hash( $title, $author );
		$extra = [];
		if ( $year && $year > 0 ) $extra['year'] = $year;

		// 1) Asegurar libro y vinculo
		$ens = $db->ensure_book_and_link_user( $user_id, $title, $author, $extra );

		if ( ! $ens['ok'] || ! $ens['book_id'] ) {
			$errors++;
			$details[] = [
				'index'  => $idx,
				'ok'     => false,
				'reason' => 'ensure_failed',
				'meta'   => [ 'method' => $ens['method'] ?? 'unknown' ]
			];
			continue; // NO borrar de confirm si falla
		}

		// 2) Si el libro existía y viene year, podemos completar year si está vacío
		if ( isset($extra['year']) && $extra['year'] > 0 && ! $ens['created'] ) {
			// Actualiza sólo si el año está vacío o 0
			$curr_year = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT year FROM {$tbl_books} WHERE id=%d LIMIT 1", $ens['book_id'] )
			);
			if ( ! $curr_year ) {
				$wpdb->update(
					$tbl_books,
					[ 'year' => (int) $extra['year'] ],
					[ 'id'   => (int) $ens['book_id'] ],
					[ '%d' ],
					[ '%d' ]
				);
			}
		}

		// 3) Eliminar los pendientes del usuario con ese hash
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tbl_confirm}
				  WHERE user_id=%d AND status='pending' AND title_author_hash=%s",
				$user_id, $hash
			)
		);

		$confirmed++;
		$details[] = [
			'index'     => $idx,
			'ok'        => true,
			'book_id'   => (int) $ens['book_id'],
			'created'   => (bool) $ens['created'],
			'link_ok'   => (bool) $ens['linked'],
			'link_new'  => (bool) $ens['link_created'],
			'method'    => (string) $ens['method'],
		];
	}

	return [
		'confirmed' => $confirmed,
		'errors'    => $errors,
		'details'   => $details,
	];
}

/**
 * Carga todos los pendientes del usuario desde wp_politeia_book_confirm (fallback para Confirm All
 * si el front no envía items).
 * @return array<int,array{title:string,author:string,year:?int}>
 */
function politeia_buttons_confirm_fetch_all_pending_for_user() {
	global $wpdb;
	$user_id = get_current_user_id();
	$tbl     = $wpdb->prefix . 'politeia_book_confirm';

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT title, author
			   FROM {$tbl}
			  WHERE user_id=%d AND status='pending'
			  ORDER BY id ASC
			  LIMIT 500",
			$user_id
		),
		ARRAY_A
	);

	$list = [];
	foreach ( (array) $rows as $r ) {
		$list[] = [
			'title'  => (string) $r['title'],
			'author' => (string) $r['author'],
			'year'   => null, // el año puede venir del front; si no, se guarda como null
		];
	}
	return $list;
}

/**
 * AJAX: Confirm individual (pero acepta array de 1).
 * POST:
 *  - nonce
 *  - items: JSON [{title, author, year?}]
 */
function politeia_buttons_confirm_ajax() {
	try {
		check_ajax_referer('politeia-chatgpt-nonce', 'nonce');
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'not_logged_in' );
		}

		$items_json = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
		$items      = json_decode( $items_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array($items) || ! count($items) ) {
			wp_send_json_error( 'invalid_items' );
		}

		$out = politeia_buttons_confirm_process_items( $items );
		wp_send_json_success( $out );

	} catch (Throwable $e) {
		error_log('[politeia_buttons_confirm] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
		wp_send_json_error( WP_DEBUG ? $e->getMessage() : 'internal_error' );
	}
}
add_action('wp_ajax_politeia_buttons_confirm', 'politeia_buttons_confirm_ajax');
// Nota: si NO quieres permitir sin login, no registres nopriv para confirmar.
// add_action('wp_ajax_nopriv_politeia_buttons_confirm', 'politeia_buttons_confirm_ajax');

/**
 * AJAX: Confirm All.
 * POST:
 *  - nonce
 *  - items (opcional): JSON [{title,author,year?}, ...]
 *    Si no viene, el servidor cargará todos los pendientes del usuario.
 */
function politeia_buttons_confirm_all_ajax() {
	try {
		check_ajax_referer('politeia-chatgpt-nonce', 'nonce');
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'not_logged_in' );
		}

		$items = [];
		if ( isset($_POST['items']) ) {
			$items_json = wp_unslash($_POST['items']);
			$items = json_decode( $items_json, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array($items) ) {
				$items = [];
			}
		}

		// Fallback: si no se enviaron items, traemos todos los pendientes del usuario
		if ( empty($items) ) {
			$items = politeia_buttons_confirm_fetch_all_pending_for_user();
		}

		if ( empty($items) ) {
			wp_send_json_success( [ 'confirmed' => 0, 'errors' => 0, 'details' => [] ] );
		}

		$out = politeia_buttons_confirm_process_items( $items );
		wp_send_json_success( $out );

	} catch (Throwable $e) {
		error_log('[politeia_buttons_confirm_all] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
		wp_send_json_error( WP_DEBUG ? $e->getMessage() : 'internal_error' );
	}
}
add_action('wp_ajax_politeia_buttons_confirm_all', 'politeia_buttons_confirm_all_ajax');
// No nopriv aquí a propósito.
