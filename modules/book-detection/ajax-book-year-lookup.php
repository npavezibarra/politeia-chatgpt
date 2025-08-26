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

        // Cargar clase externa si hace falta
        if ( ! class_exists('Politeia_Book_External_API') ) {
            if ( function_exists('politeia_chatgpt_safe_require') ) {
                politeia_chatgpt_safe_require('modules/book-detection/class-book-external-api.php');
            }
        }
        if ( ! class_exists('Politeia_Book_External_API') ) {
            throw new Exception('Politeia_Book_External_API not loaded');
        }

        $items_json = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
        $items      = json_decode($items_json, true);
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array($items) ) {
            throw new Exception('Invalid items payload');
        }

        $api   = new Politeia_Book_External_API();
        $years = [];

        foreach ($items as $it) {
            $title  = isset($it['title'])  ? (string) $it['title']  : '';
            $author = isset($it['author']) ? (string) $it['author'] : '';

            if ($title === '' || $author === '') { $years[] = null; continue; }

            // ---- normalización “amigable a motores” ----
            $qTitle  = politeia_year__simplify_title( $title );  // corta subtítulo después de ":" o "–"
            $qAuthor = $author;

            // cache por 24h (evitar hits repetidos)
            $cache_key = 'pol_year_' . hash('sha1', wp_json_encode([$qTitle,$qAuthor]));
            $cached    = get_transient($cache_key);
            if ( $cached !== false ) {
                $years[] = $cached ? (int) $cached : null;
                continue;
            }

            $best = $api->search_best_match($qTitle, $qAuthor, ['limit_per_provider' => 4]);

            $year = null;
            if (is_array($best)) {
                // Acepta cualquiera de estas claves
                $cands = [
                    $best['year']              ?? null,
                    $best['first_publish_year']?? null,
                    $best['firstPublishYear']  ?? null,
                    $best['publish_year'][0]   ?? null,
                    $best['publishedDate']     ?? null,
                    $best['date']              ?? null,
                ];
                foreach ($cands as $c) {
                    if ($c === null || $c === '') continue;
                    // extrae los 4 primeros dígitos como año
                    if (preg_match('/\d{4}/', (string)$c, $m)) { $year = (int)$m[0]; break; }
                }
            }

            // guarda en cache (usa false para distinguir "no encontrado")
            set_transient($cache_key, $year ?: false, DAY_IN_SECONDS);
            $years[] = $year ?: null;
        }

        wp_send_json_success([ 'years' => $years ]);
    } catch (Throwable $e) {
        error_log('[politeia_lookup_book_years] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        wp_send_json_error( WP_DEBUG ? $e->getMessage() : 'internal_error' );
    }
}
add_action('wp_ajax_politeia_lookup_book_years', 'politeia_lookup_book_years_ajax');
add_action('wp_ajax_nopriv_politeia_lookup_book_years', 'politeia_lookup_book_years_ajax');

/** ---- helpers locales ---- */

/**
 * Quita subtítulos y normaliza espacios. Ej:
 *  “Revolución: Autopsia de un fracaso” => “Revolución”
 */
function politeia_year__simplify_title( $t ) {
    $t = wp_strip_all_tags( (string)$t );
    // corta por ":", "—", "–" si están como subtítulo
    $t = preg_split('/[:\-–—]/u', $t, 2)[0];
    $t = trim( $t );
    return $t;
}
