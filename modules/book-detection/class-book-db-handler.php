<?php
/**
 * Class: Politeia_Book_DB_Handler
 * Purpose: Database utilities for book matching, deduplication (hash), insertion, and user linking.
 * Language: English (all user-facing strings are translatable via the 'politeia-chatgpt' text domain).
 *
 * Assumptions (created by the “Politeia Reading” plugin):
 *   - {$wpdb->prefix}politeia_books          (canonical catalog; primary key: id)
 *   - {$wpdb->prefix}politeia_user_books     (user-to-book links; primary key: id)
 *
 * Optional columns in politeia_books that this class will use if present:
 *   - title_author_hash (VARCHAR)  // unique dedup key from normalized title+author
 *   - normalized_title  (VARCHAR)
 *   - normalized_author (VARCHAR)
 *
 * This class does not create or migrate tables.
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

    /** @var string */
    protected $text_domain = 'politeia-chatgpt';

    public function __construct() {
        global $wpdb;
        $this->tbl_books      = $wpdb->prefix . 'politeia_books';
        $this->tbl_user_books = $wpdb->prefix . 'politeia_user_books';
        $this->introspect_schema();
    }

    /**
     * Public getters in case other modules need them.
     */
    public function get_books_table() {
        return $this->tbl_books;
    }
    public function get_user_books_table() {
        return $this->tbl_user_books;
    }

    /**
     * Check tables and optional columns to enable best behavior.
     */
    protected function introspect_schema() {
        // Quietly detect what exists; callers can verify readiness with is_ready().
        if ( $this->table_exists( $this->tbl_books ) ) {
            $this->has_hash_col    = $this->column_exists( $this->tbl_books, 'title_author_hash' );
            $this->has_norm_title  = $this->column_exists( $this->tbl_books, 'normalized_title' );
            $this->has_norm_author = $this->column_exists( $this->tbl_books, 'normalized_author' );
        }
    }

    /**
     * Whether dependency tables are present.
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

        if ( $missing ) {
            return new \WP_Error(
                'politeia_missing_tables',
                sprintf(
                    /* translators: %s: comma-separated list of missing tables */
                    __( 'Required tables are missing: %s. Please activate or repair the "Politeia Reading" plugin.', $this->text_domain ),
                    implode(', ', array_map( 'sanitize_text_field', $missing ) )
                )
            );
        }
        return true;
    }

    /**
     * Normalize free text for hashing / relaxed matching.
     * - strip tags, trim, remove accents
     * - lowercase
     * - keep only letters, numbers, spaces, and a few separators
     * - collapse whitespace
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
        $t = preg_replace( '/[^a-z0-9\s\-\_\'\":]+/u', ' ', $t );
        $t = preg_replace( '/\s+/u', ' ', $t );
        return trim( $t );
    }

    /**
     * Deterministic dedup hash for (title + author).
     * @param string $title
     * @param string $author
     * @return string sha256 hex
     */
    public function title_author_hash( $title, $author ) {
        $norm = $this->normalize( $title ) . '|' . $this->normalize( $author );
        return hash( 'sha256', $norm );
    }

    /**
     * Find by exact hash (if the column exists).
     * @param string $hash
     * @return array|null
     */
    public function find_by_hash( $hash ) {
        if ( ! $this->has_hash_col ) return null;
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->tbl_books} WHERE title_author_hash = %s LIMIT 1",
                $hash
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Internal best-match search strategy:
     *  1) Exact hash (if column exists)
     *  2) Normalized LIKE (if normalized_* columns exist)
     *  3) Raw LIKE fallback
     * Picks a candidate by simple similarity scoring (similar_text).
     *
     * @param string $title
     * @param string $author
     * @return array{match: array|null, method: string} method ∈ {hash, normalized_like, raw_like, none}
     */
    public function find_best_match_internal( $title, $author ) {
        global $wpdb;

        // 1) Hash
        if ( $this->has_hash_col ) {
            $hash = $this->title_author_hash( $title, $author );
            $row  = $this->find_by_hash( $hash );
            if ( $row ) {
                return [ 'match' => $row, 'method' => 'hash' ];
            }
        }

        // Prepare normalized inputs once
        $nt = $this->normalize( $title );
        $na = $this->normalize( $author );

        // 2) Normalized LIKE
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

        // 3) Raw LIKE
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
     * Pick best candidate by simple similarity (0..100), weighted title 60% / author 40%.
     *
     * @param array<int,array> $rows
     * @param string $nt normalized target title
     * @param string $na normalized target author
     * @param string $col_title column to compare for title (normalized_* or raw)
     * @param string $col_author column to compare for author (normalized_* or raw)
     * @return array|null
     */
    protected function pick_best_similarity( $rows, $nt, $na, $col_title, $col_author ) {
        if ( empty( $rows ) ) return null;

        $best = null;
        $best_score = -1;

        foreach ( $rows as $r ) {
            $ct = isset( $r[ $col_title ] ) ? $this->normalize( $r[ $col_title ] ) : '';
            $ca = isset( $r[ $col_author ] ) ? $this->normalize( $r[ $col_author ] ) : '';

            $score_t = 0; $score_a = 0;
            similar_text( $nt, $ct, $score_t );
            similar_text( $na, $ca, $score_a );

            $score = (0.6 * $score_t) + (0.4 * $score_a);
            if ( $score > $best_score ) {
                $best_score = $score;
                $best = $r;
            }
        }

        // threshold to avoid very weak matches
        if ( $best_score < 55 ) {
            return null;
        }
        return $best;
    }

    /**
     * Insert a canonical book (uses optional columns if available).
     *
     * @param string $title
     * @param string $author
     * @param array  $extra  Optional scalar extras (e.g., 'isbn', 'year', etc.)
     * @return int|\WP_Error New book ID or WP_Error
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

        // Merge extras (only scalar values; skip keys we already set)
        foreach ( (array) $extra as $k => $v ) {
            if ( is_scalar( $v ) && ! array_key_exists( $k, $data ) ) {
                $data[ $k ] = sanitize_text_field( (string) $v );
                $fmt[] = '%s';
            }
        }

        $ok = $wpdb->insert( $this->tbl_books, $data, $fmt );
        if ( ! $ok ) {
            return new \WP_Error(
                'politeia_insert_failed',
                __( 'Failed to insert the book into the catalog.', $this->text_domain )
            );
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Ensure a canonical book exists: try match, else insert.
     *
     * @param string $title
     * @param string $author
     * @param array  $extra
     * @return array{
     *   book_id:int,
     *   created:bool,
     *   method:string,   // hash|normalized_like|raw_like|inserted|error|insert_failed
     *   row:array|null,
     *   error:\WP_Error|null
     * }
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

        $match = $this->find_best_match_internal( $title, $author );
        if ( $match['match'] ) {
            return [
                'book_id' => isset($match['match']['id']) ? (int) $match['match']['id'] : 0,
                'created' => false,
                'method'  => $match['method'],
                'row'     => $match['match'],
                'error'   => null,
            ];
        }

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
            'error'   => null,
        ];
    }

    /**
     * Link a user to a book, avoiding duplicate links.
     *
     * @param int $user_id
     * @param int $book_id
     * @return array{linked:bool, created:bool}
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
            return [ 'linked' => false, 'created' => false ];
        }

        return [ 'linked' => true, 'created' => true ];
    }

    /**
     * Convenience helper: ensure the book exists and link it to the user.
     *
     * @param int    $user_id
     * @param string $title
     * @param string $author
     * @param array  $extra
     * @return array{
     *   ok: bool,
     *   book_id: int,
     *   created: bool,         // whether the catalog row was inserted now
     *   method: string,        // how it was resolved (hash|normalized_like|raw_like|inserted|error)
     *   linked: bool,
     *   link_created: bool,    // whether the link row was inserted now
     *   error: \WP_Error|null
     * }
     */
    public function ensure_book_and_link_user( $user_id, $title, $author, $extra = [] ) {
        $result = $this->ensure_book( $title, $author, $extra );
        if ( isset( $result['error'] ) && is_wp_error( $result['error'] ) ) {
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

        $link = $this->ensure_user_book( (int) $user_id, (int) $result['book_id'] );

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

    /* ============================= Helpers ============================= */

    /**
     * Check if a DB table exists.
     * @param string $table
     * @return bool
     */
    protected function table_exists( $table ) {
        global $wpdb;
        // Use LIKE with exact value; returns the table name when present.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $res = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
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
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $res = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
        return ! empty( $res );
    }
}
