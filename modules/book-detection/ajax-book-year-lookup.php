<?php
// modules/book-detection/ajax-book-year-lookup.php
if ( ! defined('ABSPATH') ) exit;

/**
 * POST:
 *  - nonce
 *  - items: JSON [{title, author}]
 * OUT:
 *  success:true, data:{years:[1998,null,...]}
 */
function politeia_lookup_book_years_ajax() {
    try {
        check_ajax_referer('politeia-chatgpt-nonce', 'nonce');

        // Aseguramos clases requeridas (por si el main no las cargó)
        if ( ! class_exists('Politeia_Book_External_API') ) {
            if ( function_exists('politeia_chatgpt_safe_require') ) {
                politeia_chatgpt_safe_require('modules/book-detection/class-book-external-api.php');
            }
        }
        if ( ! class_exists('Politeia_Book_External_API') ) {
            throw new Exception('Politeia_Book_External_API not loaded');
        }

        $items_json = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
        $items = json_decode($items_json, true);
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array($items) ) {
            throw new Exception('Invalid items payload');
        }

        $api   = new Politeia_Book_External_API();
        $years = [];

        foreach ($items as $it) {
            $title  = isset($it['title'])  ? sanitize_text_field($it['title'])  : '';
            $author = isset($it['author']) ? sanitize_text_field($it['author']) : '';
            if ($title === '' || $author === '') { $years[] = null; continue; }

            $best = $api->search_best_match($title, $author, ['limit_per_provider' => 3]);
            if ($best && ! empty($best['year'])) {
                $y = (int) preg_replace('/[^0-9]/', '', (string) $best['year']);
                $years[] = $y > 0 ? $y : null;
            } else {
                $years[] = null;
            }
        }

        wp_send_json_success([ 'years' => $years ]);
    } catch (Throwable $e) {
        error_log('[politeia_lookup_book_years] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        wp_send_json_error( WP_DEBUG ? $e->getMessage() : 'internal_error' );
    }
}
add_action('wp_ajax_politeia_lookup_book_years', 'politeia_lookup_book_years_ajax');
// Si no quieres visitantes, comenta la siguiente línea:
add_action('wp_ajax_nopriv_politeia_lookup_book_years', 'politeia_lookup_book_years_ajax');
