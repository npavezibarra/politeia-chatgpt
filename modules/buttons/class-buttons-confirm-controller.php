<?php
/**
 * Politeia ChatGPT – Confirm Buttons Controller (self-contained)
 * Location: modules/buttons/class-buttons-confirm-controller.php
 *
 * - Confirma selección (Confirm) y Confirm All
 * - Upsert directo en wp_politeia_books (por title_author_hash)
 * - Enlace en wp_politeia_user_books (UNIQUE user_id,book_id)
 * - Marca wp_politeia_book_confirm.status = 'confirmed'
 * - Devuelve siempre JSON; cualquier fallo queda en debug.log
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('Politeia_Buttons_Confirm_Controller') ) :

class Politeia_Buttons_Confirm_Controller {

    /* ----------------- tablas ----------------- */
    protected static function t_confirm()    { global $wpdb; return $wpdb->prefix . 'politeia_book_confirm'; }
    protected static function t_books()      { global $wpdb; return $wpdb->prefix . 'politeia_books'; }
    protected static function t_user_books() { global $wpdb; return $wpdb->prefix . 'politeia_user_books'; }

    /* ----------------- utilidades ----------------- */
    protected static function norm($s){ return trim( (string)$s ); }
    protected static function hash_title_author($title,$author){
        return hash('sha256', strtolower(self::norm($title)).'|'.strtolower(self::norm($author)));
    }
    protected static function db_guard(){
        global $wpdb;
        if ( ! empty($wpdb->last_error) ) {
            $e = $wpdb->last_error;
            $wpdb->last_error = '';
            throw new Exception('DB error: '.$e);
        }
    }

    /* ----------------- upsert libro ----------------- */
    protected static function ensure_book($title, $author, $year = null){
        global $wpdb;
        $table = self::t_books();

        $title  = sanitize_text_field(self::norm($title));
        $author = sanitize_text_field(self::norm($author));
        $hash   = self::hash_title_author($title,$author);
        $year   = is_null($year) ? null : (int)$year;

        // 1) ¿ya existe?
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE title_author_hash=%s LIMIT 1", $hash
        ) );
        if ( $id ) return (int)$id;

        // 2) insertar (puede chocar por UNIQUE si hay carrera)
        $data = [
            'title'             => $title,
            'author'            => $author,
            'title_author_hash' => $hash,
            'created_at'        => current_time('mysql', 1),
            'updated_at'        => current_time('mysql', 1),
        ];
        $fmt  = [ '%s','%s','%s','%s','%s' ];
        if ( ! is_null($year) && $year > 0 ){
            $data['year'] = $year;
            $fmt[] = '%d';
        }

        $wpdb->insert($table, $data, $fmt);
        // si choca por UNIQUE, intenta recuperar
        if ( ! empty($wpdb->last_error) ){
            $dup = stripos($wpdb->last_error, 'Duplicate entry') !== false;
            if ( ! $dup ) self::db_guard(); // error real
            $wpdb->last_error = '';
        }

        $id = $wpdb->insert_id ?: $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE title_author_hash=%s LIMIT 1", $hash
        ) );
        if ( ! $id ) throw new Exception('Could not ensure book.');
        return (int)$id;
    }

    /* ----------------- vincular usuario-libro ----------------- */
    protected static function ensure_user_book($user_id, $book_id){
        global $wpdb;
        $table = self::t_user_books();

        // intento de inserción (idempotente por UNIQUE)
        $wpdb->insert($table, [
            'user_id'    => (int)$user_id,
            'book_id'    => (int)$book_id,
            'created_at' => current_time('mysql', 1),
            'updated_at' => current_time('mysql', 1),
        ], ['%d','%d','%s','%s']);

        // ignorar duplicado; propagar otros errores
        if ( ! empty($wpdb->last_error) ){
            $dup = stripos($wpdb->last_error, 'Duplicate entry') !== false;
            if ( ! $dup ) self::db_guard();
            $wpdb->last_error = '';
        }
        return true;
    }

    /* ----------------- marcar confirmada la fila de cola ----------------- */
    protected static function mark_confirmed($row_id, $book_id){
        if ( ! $row_id ) return;
        global $wpdb;
        $table = self::t_confirm();
        $wpdb->update($table, [
            'status'          => 'confirmed',
            'matched_book_id' => (int)$book_id,
            'match_method'    => 'confirmed',
            'updated_at'      => current_time('mysql', 1),
        ], [ 'id' => (int)$row_id ], [ '%s','%d','%s','%s' ], [ '%d' ]);
        self::db_guard();
    }

    /* ----------------- confirmar 1 item ----------------- */
    protected static function confirm_item($user_id, $title, $author, $year = null){
        global $wpdb;
        $confirm = self::t_confirm();

        $title  = self::norm($title);
        $author = self::norm($author);
        if ( $title === '' || $author === '' ) {
            return [ 'ok'=>false, 'reason'=>'empty_title_author' ];
        }

        $hash = self::hash_title_author($title,$author);

        // fila pending más reciente (si existe)
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$confirm}
             WHERE user_id=%d AND status='pending' AND title_author_hash=%s
             ORDER BY id DESC LIMIT 1",
            $user_id, $hash
        ), ARRAY_A );
        $row_id = $row ? (int)$row['id'] : null;

        $book_id = self::ensure_book($title, $author, $year);
        self::ensure_user_book($user_id, $book_id);
        self::mark_confirmed($row_id, $book_id);

        return [ 'ok'=>true, 'book_id'=>$book_id ];
    }

    /* ===================== AJAX ===================== */

    public static function ajax_confirm_items(){
        try{
            check_ajax_referer('politeia-chatgpt-nonce', 'nonce');
            if ( ! is_user_logged_in() ) wp_send_json_error('login_required');

            global $wpdb;
            $wpdb->suppress_errors(false); // mostrar errores SQL

            $items = json_decode( isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]', true );
            if ( json_last_error() !== JSON_ERROR_NONE || ! is_array($items) ){
                throw new Exception('Invalid items payload');
            }

            $user_id = get_current_user_id();
            $ok = 0; $fail = 0; $per = [];

            foreach ($items as $it){
                $title  = $it['title']  ?? '';
                $author = $it['author'] ?? '';
                $year   = isset($it['year']) ? (int)$it['year'] : null;

                try {
                    $res = self::confirm_item($user_id, $title, $author, $year);
                    if ( ! empty($res['ok']) ){ $ok++; $per[] = true; } else { $fail++; $per[] = false; }
                } catch (Throwable $e){
                    error_log('[politeia_confirm_items] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
                    $fail++; $per[] = false;
                }
            }

            wp_send_json_success([ 'confirmed'=>$ok, 'failed'=>$fail, 'per_item'=>$per ]);

        } catch (Throwable $e){
            error_log('[politeia_confirm_items:FATAL] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            wp_send_json_error( WP_DEBUG ? $e->getMessage() : 'internal_error' );
        }
    }

    public static function ajax_confirm_all(){
        try{
            check_ajax_referer('politeia-chatgpt-nonce', 'nonce');
            if ( ! is_user_logged_in() ) wp_send_json_error('login_required');

            global $wpdb;
            $wpdb->suppress_errors(false);

            $user_id   = get_current_user_id();
            $items_raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
            $items     = json_decode($items_raw, true);
            $use_items = ( json_last_error() === JSON_ERROR_NONE && is_array($items) && !empty($items) );

            $ok = 0; $fail = 0; $per = [];

            if ( $use_items ){
                foreach ($items as $it){
                    $title  = $it['title']  ?? '';
                    $author = $it['author'] ?? '';
                    $year   = isset($it['year']) ? (int)$it['year'] : null;

                    try {
                        $res = self::confirm_item($user_id, $title, $author, $year);
                        if ( ! empty($res['ok']) ){ $ok++; $per[] = true; } else { $fail++; $per[] = false; }
                    } catch (Throwable $e){
                        error_log('[politeia_confirm_all:item] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
                        $fail++; $per[] = false;
                    }
                }
            } else {
                // Confirmar todo lo pendiente del usuario (con límite de seguridad)
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, title, author FROM ".self::t_confirm()."
                     WHERE user_id=%d AND status='pending'
                     ORDER BY id ASC LIMIT 200",
                    $user_id
                ), ARRAY_A );

                foreach ( (array)$rows as $r ){
                    try {
                        $res = self::confirm_item($user_id, $r['title'], $r['author'], null);
                        if ( ! empty($res['ok']) ) $ok++; else $fail++;
                    } catch (Throwable $e){
                        error_log('[politeia_confirm_all:bulk] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
                        $fail++;
                    }
                }
            }

            wp_send_json_success([ 'confirmed'=>$ok, 'failed'=>$fail, 'per_item'=>$per ]);

        } catch (Throwable $e){
            error_log('[politeia_confirm_all:FATAL] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            wp_send_json_error( WP_DEBUG ? $e->getMessage() : 'internal_error' );
        }
    }
}

/* hooks AJAX (logueados) */
add_action('wp_ajax_politeia_buttons_confirm',     ['Politeia_Buttons_Confirm_Controller','ajax_confirm_items']);
add_action('wp_ajax_politeia_buttons_confirm_all', ['Politeia_Buttons_Confirm_Controller','ajax_confirm_all']);

// Si quieres permitir invitados (no recomendado), descomenta:
// add_action('wp_ajax_nopriv_politeia_buttons_confirm',     ['Politeia_Buttons_Confirm_Controller','ajax_confirm_items']);
// add_action('wp_ajax_nopriv_politeia_buttons_confirm_all', ['Politeia_Buttons_Confirm_Controller','ajax_confirm_all']);

endif;
