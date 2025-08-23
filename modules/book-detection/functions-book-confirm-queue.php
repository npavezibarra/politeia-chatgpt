<?php
// modules/book-detection/functions-book-confirm-queue.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Encola items en wp_politeia_book_confirm con status=pending.
 * @param array $books Array de ['title'=>..., 'author'=>..., (opcional) 'isbn','source','score','method','matched_book_id']
 * @param array $meta  ['user_id'=>int,'input_type'=>'text|audio|image','source_note'=>string,'raw_response'=>string|array]
 * @return int Número de filas insertadas
 */
if ( ! function_exists('politeia_chatgpt_queue_confirm_items') ) {
    function politeia_chatgpt_queue_confirm_items( array $books, array $meta = [] ) {
        global $wpdb;

        // Asegura que la tabla exista (idempotente)
        if ( class_exists('Politeia_Book_Confirm_Schema') ) {
            Politeia_Book_Confirm_Schema::ensure();
        }

        $table = $wpdb->prefix . 'politeia_book_confirm';

        // Meta por defecto
        $user_id     = isset($meta['user_id'])     ? (int)$meta['user_id'] : get_current_user_id();
        $input_type  = isset($meta['input_type'])  ? sanitize_text_field($meta['input_type']) : 'text';
        $source_note = isset($meta['source_note']) ? sanitize_text_field($meta['source_note']) : '';
        $raw_payload = isset($meta['raw_response'])
            ? ( is_string($meta['raw_response']) ? $meta['raw_response'] : wp_json_encode($meta['raw_response']) )
            : null;

        // Normalizador (si está disponible)
        $normalizer = null;
        if ( class_exists('Politeia_Book_DB_Handler') ) {
            $normalizer = new Politeia_Book_DB_Handler();
        }

        $count = 0;
        foreach ( (array)$books as $b ) {
            $title  = isset($b['title'])  ? trim((string)$b['title'])  : '';
            $author = isset($b['author']) ? trim((string)$b['author']) : '';
            if ($title === '' || $author === '') continue;

            $normalized_title  = $normalizer ? $normalizer->normalize($title)  : sanitize_text_field($title);
            $normalized_author = $normalizer ? $normalizer->normalize($author) : sanitize_text_field($author);
            $title_author_hash = $normalizer ? $normalizer->title_author_hash($title, $author)
                                             : hash('sha256', strtolower($title).'|'.strtolower($author));

            $wpdb->insert(
                $table,
                [
                    'user_id'           => $user_id,
                    'input_type'        => $input_type,
                    'source_note'       => $source_note,
                    'title'             => sanitize_text_field($title),
                    'author'            => sanitize_text_field($author),
                    'normalized_title'  => $normalized_title,
                    'normalized_author' => $normalized_author,
                    'title_author_hash' => $title_author_hash,
                    'external_isbn'     => isset($b['isbn']) ? sanitize_text_field((string)$b['isbn']) : null,
                    'external_source'   => isset($b['source']) ? sanitize_text_field((string)$b['source']) : null,
                    'external_score'    => isset($b['score'])  ? (float)$b['score'] : null,
                    'match_method'      => isset($b['method']) ? sanitize_text_field((string)$b['method']) : 'none',
                    'matched_book_id'   => isset($b['matched_book_id']) ? (int)$b['matched_book_id'] : null,
                    'status'            => 'pending',
                    'raw_response'      => $raw_payload,
                ],
                [ '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%f','%s','%d','%s','%s' ]
            );

            if ( $wpdb->insert_id ) $count++;
        }

        return $count;
    }
}
