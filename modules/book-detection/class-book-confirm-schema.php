<?php
/**
 * Class: Politeia_Book_Confirm_Schema
 * Purpose:
 *   - Create/upgrade the confirmation queue table (wp_politeia_book_confirm).
 *   - Provide helpers to mark items as "In Shelf" (book already in user's library),
 *     matching by Title + Author (no ISBN), prioritizing title_author_hash with fuzzy fallback.
 * Language: English, translatable via 'politeia-chatgpt'.
 *
 * Notes:
 *   - This module only manages the confirmation queue used by Politeia ChatGPT.
 *   - Canonical books tables (wp_politeia_books / wp_politeia_user_books) are owned by Politeia Reading.
 */

if ( ! defined('ABSPATH') ) exit;

class Politeia_Book_Confirm_Schema {

    /** @var string */
    protected static $td = 'politeia-chatgpt';

    /**
     * Return full confirmation table name with prefix.
     * @return string
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'politeia_book_confirm';
    }

    /**
     * Canonical books table (owned by Politeia Reading).
     * @return string
     */
    public static function books_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'politeia_books';
    }

    /**
     * Pivot user-books table (owned by Politeia Reading).
     * @return string
     */
    public static function user_books_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'politeia_user_books';
    }

    /**
     * Check if confirmation table exists.
     * @return bool
     */
    public static function exists() {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return ( $found === $table );
    }

    /**
     * Ensure confirmation table is created/updated (idempotent).
     * Adds a UNIQUE KEY to prevent duplicates for (user_id, status, title_author_hash).
     * Safe to call multiple times; uses dbDelta.
     * @return void
     */
    public static function ensure() {
        global $wpdb;

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            input_type VARCHAR(20) NOT NULL,
            source_note VARCHAR(190) DEFAULT '',
            title VARCHAR(255) NOT NULL,
            author VARCHAR(255) NOT NULL,
            normalized_title VARCHAR(255) DEFAULT NULL,
            normalized_author VARCHAR(255) DEFAULT NULL,
            title_author_hash CHAR(64) DEFAULT NULL,
            external_isbn VARCHAR(32) DEFAULT NULL,
            external_source VARCHAR(50) DEFAULT NULL,
            external_score FLOAT DEFAULT NULL,
            match_method VARCHAR(30) DEFAULT NULL,
            matched_book_id BIGINT UNSIGNED DEFAULT NULL,
            status ENUM('pending','confirmed','discarded') NOT NULL DEFAULT 'pending',
            raw_response LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_status_hash (user_id, status, title_author_hash),
            KEY idx_user_status (user_id, status),
            KEY idx_hash (title_author_hash),
            KEY idx_matched (matched_book_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        self::maybe_add_unique_index();
    }

    /**
     * Add/ensure the unique index if it doesn't exist yet (back-compat name).
     * @return void
     */
    public static function maybe_add_unique_index() {
        global $wpdb;
        $table = self::table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $have_new = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'uq_user_status_hash'" );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $have_old = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_user_hash_pending'" );

        if ( ! $have_new && ! $have_old ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uq_user_status_hash (user_id, status, title_author_hash)" );
        }
    }

    // ---------------------------------------------------------------------
    // Normalization / Hash helpers (Title + Author, no ISBN)
    // ---------------------------------------------------------------------

    /** Quick table existence check */
    protected static function table_exists( $table_name ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        return ( $found === $table_name );
    }

    /** Normalize strings for fuzzy matching */
    protected static function normalize_key( $s ) {
        $s = wp_strip_all_tags( (string) $s );
        $s = remove_accents( $s );
        $s = strtolower( $s );

        // remove common stopwords (ES/EN) to reduce noise
        $stop = [' el ',' la ',' los ',' las ',' un ',' una ',' unos ',' unas ',' de ',' del ',' y ',' e ',' a ',' en ',' the ',' of ',' and ',' to ',' for '];
        $s = ' ' . preg_replace( '/\s+/', ' ', $s ) . ' ';
        foreach ( $stop as $st ) { $s = str_replace( $st, ' ', $s ); }

        // keep only letters/numbers/spaces
        $s = preg_replace( '/[^a-z0-9\s]/', ' ', $s );

        // sort tokens to erase ordering differences (e.g., "Julio César" vs "César Julio")
        $tokens = array_filter( explode( ' ', trim( preg_replace( '/\s+/', ' ', $s ) ) ) );
        sort( $tokens, SORT_STRING );

        return implode( ' ', $tokens );
    }

    /** Build normalized composite key from Title + Author */
    protected static function norm_title_author( $title, $author ) {
        return self::normalize_key( trim( (string) $title . ' ' . (string) $author ) );
    }

    /** Relative Levenshtein (0 = identical, 1 = completely different) */
    protected static function rel_levenshtein( $a, $b ) {
        $max = max( 1, max( strlen( $a ), strlen( $b ) ) );
        return levenshtein( $a, $b ) / $max;
    }

    /** Deterministic hash for Title+Author (useful for dedup keys) */
    public static function compute_title_author_hash( $title, $author ) {
        return hash( 'sha256', self::norm_title_author( $title, $author ) );
    }

    /**
     * Backfill normalized fields (in-memory) for queue rows.
     * Optionally persists back to DB when $persist = true.
     *
     * @param array $rows
     * @param bool  $persist
     * @return void
     */
    public static function backfill_normalized_fields( array &$rows, $persist = false ) {
        global $wpdb;
        $table = self::table_name();

        foreach ( $rows as &$r ) {
            $title  = $r['title']  ?? '';
            $author = $r['author'] ?? '';

            if ( empty( $r['normalized_title'] ) ) {
                $r['normalized_title'] = self::normalize_key( $title );
            }
            if ( empty( $r['normalized_author'] ) ) {
                $r['normalized_author'] = self::normalize_key( $author );
            }
            if ( empty( $r['title_author_hash'] ) ) {
                $r['title_author_hash'] = self::compute_title_author_hash( $title, $author );
            }

            if ( $persist && ! empty( $r['id'] ) ) {
                $wpdb->update(
                    $table,
                    array(
                        'normalized_title'  => $r['normalized_title'],
                        'normalized_author' => $r['normalized_author'],
                        'title_author_hash' => $r['title_author_hash'],
                    ),
                    array( 'id' => (int) $r['id'] ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );
            }
        }
        unset($r);
    }

    // ---------------------------------------------------------------------
    // "In Shelf" marking
    // ---------------------------------------------------------------------

    /**
     * Mark rows in-place with "already in shelf" flags for a given $user_id.
     * Priority:
     *   1) Exact match by title_author_hash (fast & precise) if both sides provide it.
     *   2) Fallback to fuzzy match by normalized title+author (Levenshtein).
     *
     * Mutates each row adding:
     *   - already_in_shelf (0/1)
     *   - shelf_slug (string|null)
     *   - matched_book_id (int|null)
     *
     * @param array $rows    Each row should have 'title', 'author', and ideally 'title_author_hash'
     * @param int   $user_id
     * @param float $threshold Similarity threshold for fuzzy step (0.20–0.30 recommended)
     * @return array $rows (returned for convenience)
     */
    public static function batch_mark_in_shelf( array &$rows, $user_id, $threshold = 0.25 ) {
        global $wpdb;
        $books_tbl = self::books_table_name();
        $ub_tbl    = self::user_books_table_name();

        if ( ! self::table_exists( $books_tbl ) || ! self::table_exists( $ub_tbl ) ) {
            foreach ( $rows as &$r ) {
                $r['already_in_shelf'] = 0;
                $r['shelf_slug']       = null;
                $r['matched_book_id']  = null;
            }
            unset( $r );
            return $rows;
        }

        // Fetch user's library with hash (if present in schema)
        $sql = $wpdb->prepare("
            SELECT b.id, b.slug, b.title, b.author, b.title_author_hash
            FROM {$books_tbl} b
            INNER JOIN {$ub_tbl} ub
                ON ub.book_id = b.id AND ub.user_id = %d
        ", (int) $user_id );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $user_books = $wpdb->get_results( $sql, ARRAY_A );

        // Build maps: by-hash and a pre-normalized array for fuzzy
        $by_hash   = [];
        $lib_fuzzy = [];
        foreach ( $user_books as $b ) {
            $hash = ! empty( $b['title_author_hash'] ) ? strtolower( (string) $b['title_author_hash'] ) : null;
            if ( $hash && strlen( $hash ) >= 40 ) {
                $by_hash[ $hash ] = [
                    'id'   => (int) $b['id'],
                    'slug' => (string) $b['slug'],
                ];
            }
            $lib_fuzzy[] = [
                'id'   => (int) $b['id'],
                'slug' => (string) $b['slug'],
                'key'  => self::norm_title_author( $b['title'] ?? '', $b['author'] ?? '' ),
            ];
        }

        // Ensure we have normalized fields / hash on rows (in-memory)
        self::backfill_normalized_fields( $rows, false );

        foreach ( $rows as &$r ) {
            // 1) Try exact by hash if row has it
            $row_hash = ! empty( $r['title_author_hash'] ) ? strtolower( (string) $r['title_author_hash'] ) : null;
            if ( $row_hash && isset( $by_hash[ $row_hash ] ) ) {
                $hit = $by_hash[ $row_hash ];
                $r['already_in_shelf'] = 1;
                $r['shelf_slug']       = $hit['slug'];
                $r['matched_book_id']  = $hit['id'];
                continue;
            }

            // 2) Fuzzy fallback with normalized pair
            $key = self::norm_title_author( $r['normalized_title'] ?? '', $r['normalized_author'] ?? '' );

            $best = null;
            $bestScore = 1.0;
            foreach ( $lib_fuzzy as $b ) {
                $rel = self::rel_levenshtein( $key, $b['key'] );
                if ( $rel < $bestScore ) {
                    $bestScore = $rel;
                    $best = $b;
                }
            }

            if ( $best && $bestScore <= $threshold ) {
                $r['already_in_shelf'] = 1;
                $r['shelf_slug']       = $best['slug'];
                $r['matched_book_id']  = $best['id'];
            } else {
                $r['already_in_shelf'] = 0;
                $r['shelf_slug']       = null;
                $r['matched_book_id']  = null;
            }
        }
        unset( $r );

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Convenience: fetch queue rows for user including "In Shelf" flags
    // ---------------------------------------------------------------------

    /**
     * Fetch confirmation rows for a user (filtered by status), already marked with In-Shelf flags.
     *
     * @param int        $user_id
     * @param array      $statuses   e.g. ['pending'] or ['pending','discarded']
     * @param int        $limit
     * @param int        $offset
     * @param float|null $threshold  null to use default 0.25
     * @return array
     */
    public static function get_confirm_rows_for_user( $user_id, array $statuses = ['pending'], $limit = 100, $offset = 0, $threshold = null ) {
        global $wpdb;
        $table = self::table_name();

        // Sanitize statuses for SQL IN()
        $valid_statuses = array_intersect( $statuses, ['pending','confirmed','discarded'] );
        if ( empty( $valid_statuses ) ) {
            $valid_statuses = ['pending'];
        }

        // Build placeholders for IN()
        $placeholders = implode( ',', array_fill( 0, count( $valid_statuses ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare("
            SELECT id, user_id, input_type, source_note, title, author,
                   normalized_title, normalized_author, title_author_hash,
                   external_isbn, external_source, external_score,
                   match_method, matched_book_id,
                   status, raw_response, created_at, updated_at
            FROM {$table}
            WHERE user_id = %d
              AND status IN ($placeholders)
            ORDER BY created_at DESC, id DESC
            LIMIT %d OFFSET %d
        ", array_merge( [ (int) $user_id ], $valid_statuses, [ (int) $limit, (int) $offset ] ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        // Make sure normalized fields exist (in-memory) then mark "In Shelf"
        self::backfill_normalized_fields( $rows, false );

        self::batch_mark_in_shelf(
            $rows,
            $user_id,
            $threshold === null ? 0.25 : (float) $threshold
        );

        return $rows;
    }

}
