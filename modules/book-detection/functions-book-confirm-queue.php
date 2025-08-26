<?php
// modules/book-detection/functions-book-confirm-queue.php
/**
 * Politeia ChatGPT – Book confirmation queue helpers
 *
 * Prepara / encola candidatos en wp_politeia_book_confirm:
 * - Calcula hash determinista (sha256) de (title,author) tras normalizar.
 * - Si el usuario YA tiene el libro (books + user_books) => NO encola; devuelve in_shelf=true.
 * - Si NO lo tiene, lo encola con status='pending' evitando duplicados por (user_id,hash,status)
 *   (blindaje doble: índice único + chequeo en código).
 *
 * Firma compatible con dos formas de llamada:
 *   A) politeia_chatgpt_queue_confirm_items($user_id, $candidates, $input_type, $source_note)
 *   B) politeia_chatgpt_queue_confirm_items($candidates, ['user_id'=>..,'input_type'=>..,'source_note'=>..,'raw_response'=>..])
 *
 * @return array {
 *   @type int   $queued   Filas insertadas en cola.
 *   @type int   $skipped  Saltadas (vacías, ya en librería, o ya pending).
 *   @type array $items    Para la UI (sin duplicados pending):
 *                         [ [ 'title','author','year'=>int|null,'in_shelf'=>bool ], ... ]
 * }
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('politeia_chatgpt_queue_confirm_items') ) :

function politeia_chatgpt_queue_confirm_items( $arg1, $arg2 = null, $arg3 = null, $arg4 = '' ) {
	global $wpdb;

	// Asegura que la tabla exista / tenga índices (idempotente)
	if ( class_exists('Politeia_Book_Confirm_Schema') ) {
		Politeia_Book_Confirm_Schema::ensure();
	}

	// --------- Parseo de parámetros: soporta firma A y B ----------
	$user_id     = 0;
	$candidates  = [];
	$input_type  = 'text';
	$source_note = '';
	$raw_payload = null;

	if ( is_array($arg1) && ( is_array($arg2) || $arg2 === null ) ) {
		// Firma B: ($candidates, $meta)
		$candidates  = (array) $arg1;
		$meta        = is_array($arg2) ? $arg2 : [];
		$user_id     = isset($meta['user_id'])      ? (int) $meta['user_id']      : get_current_user_id();
		$input_type  = isset($meta['input_type'])   ? sanitize_text_field($meta['input_type']) : 'text';
		$source_note = isset($meta['source_note'])  ? sanitize_text_field($meta['source_note']) : '';
		if ( array_key_exists('raw_response', $meta) ) {
			$raw_payload = is_string($meta['raw_response']) ? $meta['raw_response'] : wp_json_encode($meta['raw_response']);
		}
	} else {
		// Firma A: ($user_id, $candidates, $input_type, $source_note)
		$user_id     = (int) $arg1;
		$candidates  = (array) $arg2;
		$input_type  = $arg3 ? sanitize_text_field($arg3) : 'text';
		$source_note = $arg4 ? sanitize_text_field($arg4) : '';
	}

	$user_id = $user_id ?: (int) get_current_user_id();

	$tbl_books   = $wpdb->prefix . 'politeia_books';
	$tbl_users   = $wpdb->prefix . 'politeia_user_books';
	$tbl_confirm = $wpdb->prefix . 'politeia_book_confirm';

	$queued  = 0;
	$skipped = 0;
	$items   = [];

	// --------- Deduplicación dentro del mismo lote ----------
	$seen_hashes = [];

	foreach ( (array) $candidates as $b ) {
		$title  = isset($b['title'])  ? trim((string) $b['title'])  : '';
		$author = isset($b['author']) ? trim((string) $b['author']) : '';
		if ( $title === '' || $author === '' ) { $skipped++; continue; }

		$norm_title  = politeia__normalize_text( $title );
		$norm_author = politeia__normalize_text( $author );
		$hash        = politeia__title_author_hash( $title, $author );

		// Lote: si ya vimos el mismo hash en esta misma respuesta, saltar
		if ( isset($seen_hashes[$hash]) ) { $skipped++; continue; }
		$seen_hashes[$hash] = true;

		// 1) Ya en librería del usuario (books + user_books) por hash
		$owned = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.id, b.year
				   FROM {$tbl_books} b
				   JOIN {$tbl_users} ub ON ub.book_id=b.id AND ub.user_id=%d
				  WHERE b.title_author_hash=%s
				  LIMIT 1",
				$user_id,
				$hash
			),
			ARRAY_A
		);

		if ( $owned ) {
			$items[] = [
				'title'    => $title,
				'author'   => $author,
				'year'     => ( isset($owned['year']) && $owned['year'] !== '' && $owned['year'] !== null ) ? (int) $owned['year'] : null,
				'in_shelf' => true,
			];
			$skipped++;
			continue;
		}

		// 2) Ya pending en la cola para este usuario + hash
		$pending_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tbl_confirm}
				  WHERE user_id=%d AND status='pending' AND title_author_hash=%s
				  LIMIT 1",
				$user_id,
				$hash
			)
		);

		if ( $pending_id ) {
			// No insertamos NI devolvemos en items (así no se vuelve a mostrar).
			$skipped++;
			continue;
		}

		// 3) Insertar en cola (status='pending'), evitando nulos innecesarios
		$data = [
			'user_id'           => $user_id,
			'input_type'        => $input_type,
			'source_note'       => $source_note,
			'title'             => $title,
			'author'            => $author,
			'normalized_title'  => $norm_title,
			'normalized_author' => $norm_author,
			'title_author_hash' => $hash,
			'status'            => 'pending',
		];
		$fmt  = [ '%d','%s','%s','%s','%s','%s','%s','%s','%s' ];

		// Extras opcionales del candidato
		if ( isset($b['isbn']) )      { $data['external_isbn']   = sanitize_text_field((string)$b['isbn']);   $fmt[]='%s'; }
		if ( isset($b['source']) )    { $data['external_source'] = sanitize_text_field((string)$b['source']); $fmt[]='%s'; }
		if ( isset($b['score']) )     { $data['external_score']  = (string)(float)$b['score'];                $fmt[]='%s'; }
		if ( isset($b['method']) )    { $data['match_method']    = sanitize_text_field((string)$b['method']); $fmt[]='%s'; }
		if ( isset($b['matched_book_id']) ) { $data['matched_book_id'] = (int) $b['matched_book_id'];        $fmt[]='%d'; }
		if ( $raw_payload !== null )  { $data['raw_response']    = $raw_payload;                               $fmt[]='%s'; }

		$ok = $wpdb->insert( $tbl_confirm, $data, $fmt );

		if ( ! $ok ) {
			// Si es duplicado por índice único, lo tratamos como “skipped”
			if ( stripos($wpdb->last_error, 'Duplicate entry') !== false ) {
				$skipped++;
				continue;
			}
			// Otro error: también lo saltamos, pero puedes loguearlo si quieres.
			$skipped++;
			continue;
		}

		$queued++;
		$items[] = [
			'title'    => $title,
			'author'   => $author,
			'year'     => null,   // se puede completar luego con lookup externo
			'in_shelf' => false,
		];
	}

	return [
		'queued'  => $queued,
		'skipped' => $skipped,
		'items'   => $items,
	];
}

endif; // function_exists

/* =========================== Helpers =========================== */
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
