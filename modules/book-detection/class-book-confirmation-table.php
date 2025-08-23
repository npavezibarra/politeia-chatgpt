<?php
/**
 * Class: Politeia_Book_DB_Handler
 * Purpose: Database utilities for book matching, deduplication, insertion, and user linking.
 * Language: English (all user-facing strings are translatable via the 'politeia-chatgpt' text domain)
 *
 * NOTE:
 * - This class does NOT create tables. It assumes the following tables already exist (created by Politeia Reading):
 *     - {$wpdb->prefix}politeia_books            (canonical catalog)
 *     - {$wpdb->prefix}politeia_user_books       (user-to-book links)
 * - It degrades gracefully depending on which columns exist (e.g., title_author_hash, normalized_*).
 */

if ( ! defined('ABSPATH') ) exit;

class Politeia_Book_DB_Handler {

    /** @var string */
    protected $tbl_books;

    /** @var string */
    protected $tbl_user_books;

    /** @var bool */
    protected $has_hash_col = false;

    /** @var bool */
    protected $has_norm_title = false;

    /** @var bool */
    protected $has_norm_author = false;

    /** @var string Text domain for translations */
    protected $td = 'politeia-chatgpt';

    public function __construct() {
        global $wpdb;
        $this->tbl_books      = $wpdb->prefix . 'politeia_books';
        $this->tbl_user_books = $wpdb->prefix . 'politeia_user_books';
        $this->introspect_schema();
    }

    /**
     * Check that required tables exist and cache which helpful columns are available.
     */
    protected function introspect_schema() {
        global $wpdb;

        // Check tables exist
        $books_exists = $this->table_exists( $this->tbl_books );
        $user_books_exists = $this->table_exists( $this->tbl_user_books );

        if ( ! $books_exists || ! $user_books_exists ) {
            // We do NOT throw here to avoid fatal errors in front-end usage; callers can use is_ready()
            return;
        }

        // Optional columns that improve matching/dedup
        $this->has_hash_col   = $this->column_exists( $this->tbl_books, 'title_author_hash' );
        $this->has_norm_title = $this->column_exists( $this->tbl_books, 'normalized_title' );
        $this->has_norm_author= $this->column_exists( $this->tbl_books, 'normalized_author' );
    }

    /**
     * Whether the dependency tables seem to be present.
     * @return true|\WP_Error
     */
    public function is_ready() {
        $missing = [];
        if ( ! $this->table_exists( $this->tbl_books ) ) {
            $missing[] = $this->tbl_books;
        }
        if ( ! $this->table_exists( $this->tbl_user_books ) ) {
            $missing[] = $this->tbl_user_books;
        }

        if ( ! empty( $missing ) ) {
            return new \WP_Error(
                'politeia_missing_tables',
                sprintf(
                    /* translators: %s: comma-separated list of missing tables */
                    __( 'Required tables are missing: %s. Please activate or repair the "Politeia Reading" plugin.', $this->td ),
                    implode(', ', array_map('sanitize_text_field', $missing))
                )
            );
        }

        return true;
    }

    /**
     * Normalize a free-text string for hashing and relaxed matching.
     * - lowercases
     * - strips tags
     * - removes accents
     * - collapses whitespace
     * - keeps only letters, numbers, spaces, and a few separators
     *
     * @param string $text
     * @return string
     */
    public function normalize( $text ) {
        $t = (string) $text;
        $t = wp_strip_all_tags( $t );
        $t = trim( $t );
        $t = remove_accents( $t );
        $t = mb_strtolower( $t, 'UTF-8' );
        // Replace non-alphanumeric (except basic separators) with space
        $t = preg_replace('/[^a-z0-9\s\-\_\'\":]+/u', ' ', $t);
        // Collapse whitespace
        $t = preg_replace('/\s+/u', ' ', $t);
        return trim($t);
    }

    /**
     * Build a unique, deterministic hash for (title + author) to shield against duplicates.
     * @param string $title
     * @param string $author
     * @return string sha256 hex
     */
    public function title_author_hash( $title, $author ) {
        $norm = $this->normalize( $title ) . '|' . $this->normalize( $author );
        return hash('sha256', $norm);
    }

    /**
     * Try to find a canonical book row by exact hash (if column exists).
     * @param string $hash
     * @return array|null associative row
     */
    public function find_by_hash( $hash ) {
        if ( ! $this->has_hash_col ) return null;
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->tbl_books} WHERE title_author_hash = %s LIMIT 1",
            $hash
        );
        $row = $wpdb->get_row( $sql, ARRAY_A );
        return $row ?: null;
    }

    /**
     * Best-effort internal match:
     * 1) If hash column exists, try exact hash
     * 2) Else or fallback, try relaxed LIKE search against normalized_* or raw columns
     *    and pick a best candidate via similarity scoring.
     *
     * @param string $title
     * @param string $author
     * @return array{match: array|null, method: string} match=book row or null; method=hash|normalized_like|raw_like|none
     */
    public function find_best_match_internal( $title, $author ) {
        global $wpdb;

        // 1) Hash exact match
        if ( $this->has_hash_col ) {
            $hash = $this->title_author_hash( $title, $author );
            $row = $this->find_by_hash( $hash );
            if ( $row ) {
                return [ 'match' => $row, 'method' => 'hash' ];
            }
        }

        // Prepare normalized inputs
        $nt = $this->normalize( $title );
        $na = $this->normalize( $author );

        // 2) Normalized LIKE search (if columns exist)
        if ( $this->has_norm_title && $this->has_norm_author ) {
            $like_t = '%' . $wpdb->esc_like( $nt ) . '%';
            $like_a = '%' . $wpdb->esc_like( $na ) . '%';
            $candidates = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->tbl_books}
                     WHERE normalized_title LIKE %s AND normalized_author LIKE %s
                     LIMIT 20",
                    $like_t, $like_a
                ),
                ARRAY_A
            );
            $picked = $this->pick_best_similarity( $candidates, $nt, $na, 'normalized_title', 'normalized_author' );
            if ( $picked ) {
                return [ 'match' => $picked, 'method' => 'normalized_like' ];
            }
        }

        // 3) Raw LIKE search (fallback)
        $like_t = '%' . $wpdb->esc_like( $title ) . '%';
        $like_a = '%' . $wpdb->esc_like( $author ) . '%';
        $candidates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->tbl_books}
                 WHERE title LIKE %s AND author LIKE %s
                 LIMIT 20",
                $like_t, $like_a
            ),
            ARRAY_A
        );
        $picked = $this->pick_best_similarity( $candidates, $nt, $na, 'title', 'author' );
        if ( $picked ) {
            return [ 'match' => $picked, 'method' => 'raw_like' ];
        }

        return [ 'match' => null, 'method' => 'none' ];
    }

    /**
     * Similarity-based picker among candidate rows.
     * Uses PHP's similar_text() as a quick heuristic (0..100).
     *
     * @param array<int,array> $rows
     * @param string $nt target normalized title
     * @param string $na target normalized author
     * @param string $col_title column name to compare title against (normalized or raw)
     * @param string $col_author column name to compare author against (normalized or raw)
     * @return array|null best row or null
     */
    protected function pick_best_similarity( $rows, $nt, $na, $col_title, $col_author ) {
        if ( empty( $rows ) ) return null;

        $best = null;
        $best_score = -1;

        foreach ( $rows as $r ) {
            $ct = isset($r[$col_title]) ? $this->normalize($r[$col_title]) : '';
            $ca = isset($r[$col_author]) ? $this->normalize($r[$col_author]) : '';

            $score_t = 0; $score_a = 0;
            similar_text( $nt, $ct, $score_t ); // percentage
            similar_text( $na, $ca, $score_a );

            // Weighted average: title is a bit more important than author (60/40)
            $score = (0.6 * $score_t) + (0.4 * $score_a);

            if ( $score > $best_score ) {
                $best_score = $score;
                $best = $r;
            }
        }

        // Optional: set a floor to avoid weak matches being accepted blindly
        if ( $best_score < 55 ) { // heuristic threshold
            return null;
        }

        return $best;
    }

    /**
     * Insert a canonical book into the catalog.
     * If hash column exists, it will be stored; if normalized_* columns exist, they will be stored.
     *
     * @param string $title
     * @param string $author
     * @param array  $extra Optional extra fields to merge (e.g., 'isbn', 'year', etc.)
     * @return int|\WP_Error New book ID on success, WP_Error on failure
     */
    public function insert_book( $title, $author, $extra = [] ) {
        global $wpdb;

        $data = [
            'title'  => sanitize_text_field( $title ),
            'author' => sanitize_text_field( $author ),
        ];
        $fmt  = [ '%s', '%s' ];

        if ( $this->has_norm_title ) {
            $data['normalized_title'] = $this->normalize( $title );
            $fmt[] = '%s';
        }
        if ( $this->has_norm_author ) {
            $data['normalized_author'] = $this->normalize( $author );
            $fmt[] = '%s';
        }
        if ( $this->has_hash_col ) {
            $data['title_author_hash'] = $this->title_author_hash( $title, $author );
            $fmt[] = '%s';
        }

        // Merge extras (only scalar values)
        foreach ( (array) $extra as $k => $v ) {
            if ( is_scalar( $v ) && ! isset( $data[ $k ] ) ) {
                $data[ $k ] = sanitize_text_field( (string) $v );
                $fmt[] = '%s';
            }
        }

        $ok = $wpdb->insert( $this->tbl_books, $data, $fmt );
        if ( ! $ok ) {
            return new \WP_Error(
                'politeia_insert_failed',
                __( 'Failed to insert the book into the catalog.', $this->td )
            );
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Ensure a canonical book exists; if found, return it; else insert.
     *
     * @param string $title
     * @param string $author
     * @param array  $extra
     * @return array{book_id:int, created:bool, method:string, row:array|null} method: hash|normalized_like|raw_like|inserted
     */
    public function ensure_book( $title, $author, $extra = [] ) {
        $ready = $this->is_ready();
        if ( is_wp_error( $ready ) ) {
            return [
                'book_id' => 0,
                'created' => false,
                'method'  => 'error',
                'row'     => null,
                'error'   => $ready,
            ];
        }

        // Try internal match first
        $match = $this->find_best_match_internal( $title, $author );
        if ( $match['match'] ) {
            return [
                'book_id' => (int) $match['match']['id'],
                'created' => false,
                'method'  => $match['method'],
                'row'     => $match['match'],
            ];
        }

        // Not found -> insert
        $insert_id = $this->insert_book( $title, $author, $extra );
        if ( is_wp_error( $insert_id ) ) {
            return [
                'book_id' => 0,
                'created' => false,
                'method'  => 'insert_failed',
                'row'     => null,
                'error'   => $insert_id,
            ];
        }

        return [
            'book_id' => (int) $insert_id,
            'created' => true,
            'method'  => 'inserted',
            'row'     => null,
        ];
    }

    /**
     * Link a user to a given book, avoiding duplicates.
     *
     * @param int $user_id
     * @param int $book_id
     * @return array{linked:bool, created:bool} created=true if a new row was inserted
     */
    public function ensure_user_book( $user_id, $book_id ) {
        global $wpdb;
        $user_id = (int) $user_id;
        $book_id = (int) $book_id;

        // Already linked?
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->tbl_user_books} WHERE user_id=%d AND book_id=%d LIMIT 1",
                $user_id, $book_id
            )
        );
        if ( $exists ) {
            return [ 'linked' => true, 'created' => false ];
        }

        $ok = $wpdb->insert(
            $this->tbl_user_books,
            [ 'user_id' => $user_id, 'book_id' => $book_id ],
            [ '%d', '%d' ]
        );

        if ( ! $ok ) {
            // We keep `linked=false` here; caller can decide what to do
            return [ 'linked' => false, 'created' => false ];
        }

        return [ 'linked' => true, 'created' => true ];
    }

    /**
     * Convenience: ensure the book exists and link it to the user.
     *
     * @param int    $user_id
     * @param string $title
     * @param string $author
     * @param array  $extra
     * @return array{
     *   ok: bool,
     *   book_id: int,
     *   created: bool,
     *   method: string,
     *   linked: bool,
     *   link_created: bool,
     *   error: \WP_Error|null
     * }
     */
    public function ensure_book_and_link_user( $user_id, $title, $author, $extra = [] ) {
        $result = $this->ensure_book( $title, $author, $extra );

        if ( isset($result['error']) && is_wp_error($result['error']) ) {
            return [
                'ok'           => false,
                'book_id'      => 0,
                'created'      => false,
                'method'       => 'error',
                'linked'       => false,
                'link_created' => false,
                'error'        => $result['error'],
            ];
        }

        $link = $this->ensure_user_book( (int)$user_id, (int)$result['book_id'] );

        return [
            'ok'           => ( $result['book_id'] > 0 && $link['linked'] ),
            'book_id'      => (int) $result['book_id'],
            'created'      => (bool) $result['created'],
            'method'       => (string) $result['method'],
            'linked'       => (bool) $link['linked'],
            'link_created' => (bool) $link['created'],
            'error'        => null,
        ];
    }

    /* ----------------------------- Helpers ----------------------------- */

    /**
     * Check if a DB table exists.
     * @param string $table
     * @return bool
     */
    protected function table_exists( $table ) {
        global $wpdb;
        $table = esc_sql( $table );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare( "SHOW TABLES LIKE %s", $table );
        $res = $wpdb->get_var( $sql );
        return ( $res === $table );
    }

    /**
     * Check if a column exists in a table.
     * @param string $table
     * @param string $column
     * @return bool
     */
    protected function column_exists( $table, $column ) {
        global $wpdb;
        $table  = esc_sql( $table );
        $column = esc_sql( $column );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column );
        $res = $wpdb->get_var( $sql );
        return ! empty( $res );
    }
}
