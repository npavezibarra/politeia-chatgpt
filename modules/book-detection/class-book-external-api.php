<?php
/**
 * Class: Politeia_Book_External_API
 * Purpose: Query external book sources (Open Library, Google Books) to find the
 *          best match for a given (title, author). Always maps provider fields
 *          to a common shape and extracts a usable publication year.
 *
 * Returned candidate shape:
 * [
 *   'title'  => (string),
 *   'author' => (string),
 *   'isbn'   => (string|null),
 *   'source' => 'openlibrary'|'googlebooks',
 *   'year'   => (int|null),   // derived from provider-specific fields
 *   'score'  => (float)       // 0..100 similarity to the requested title/author
 * ]
 *
 * Filters:
 * - 'politeia_book_external_min_score' (int)    Default 62 (0..100). Minimum score to accept a match.
 * - 'politeia_book_external_providers' (array)  Default ['openlibrary','googlebooks'].
 */

if ( ! defined('ABSPATH') ) exit;

class Politeia_Book_External_API {

    /** @var string */
    protected $text_domain = 'politeia-chatgpt';

    /** @var string|null */
    protected $google_api_key = null;

    /** @var int Similarity threshold (0..100) */
    protected $min_score = 62;

    /** @var array<string> Enabled providers */
    protected $providers = [ 'openlibrary', 'googlebooks' ];

    /**
     * @param string|null $google_api_key  Optional Google Books API key.
     *                                     If null, uses WP option 'politeia_google_books_api_key' if present.
     */
    public function __construct( $google_api_key = null ) {
        $this->google_api_key = $google_api_key;
        if ( $this->google_api_key === null ) {
            $opt = get_option( 'politeia_google_books_api_key' );
            if ( is_string( $opt ) && $opt !== '' ) {
                $this->google_api_key = $opt;
            }
        }

        // Allow customization via filters
        $this->min_score = (int) apply_filters( 'politeia_book_external_min_score', $this->min_score );
        $providers = apply_filters( 'politeia_book_external_providers', $this->providers );
        if ( is_array( $providers ) && ! empty( $providers ) ) {
            $this->providers = array_values( array_unique( array_map( 'strval', $providers ) ) );
        }
    }

    /** Public getter for current threshold. */
    public function get_min_score() { return $this->min_score; }

    /** Public getter for enabled providers. */
    public function get_providers() { return $this->providers; }

    /**
     * Find best external candidate for (title, author).
     *
     * @param string $title
     * @param string $author
     * @param array  $args { @type int $limit_per_provider Default 5 }
     * @return array|null Candidate (see header) or null if none meets threshold.
     */
    public function search_best_match( $title, $author, $args = [] ) {
        $limit = isset( $args['limit_per_provider'] ) ? (int) $args['limit_per_provider'] : 5;
        if ( $limit <= 0 ) $limit = 5;

        $target_title_norm  = $this->normalize( $title );
        $target_author_norm = $this->normalize( $author );

        $candidates = [];

        foreach ( $this->providers as $provider ) {
            if ( $provider === 'openlibrary' ) {
                $candidates = array_merge(
                    $candidates,
                    $this->search_openlibrary( $title, $author, $limit )
                );
            } elseif ( $provider === 'googlebooks' ) {
                $candidates = array_merge(
                    $candidates,
                    $this->search_googlebooks( $title, $author, $limit )
                );
            }
        }

        if ( empty( $candidates ) ) return null;

        // De-duplicate by normalized (title|author) keeping highest score
        $dedup = [];
        foreach ( $candidates as $cand ) {
            $key = $this->normalize( $cand['title'] ) . '|' . $this->normalize( $cand['author'] );
            if ( ! isset( $dedup[ $key ] ) || $cand['score'] > $dedup[ $key ]['score'] ) {
                $dedup[ $key ] = $cand;
            }
        }

        // Final scoring vs requested (title, author)
        $best = null; $best_score = -1;
        foreach ( $dedup as $cand ) {
            $score = $this->score(
                $this->normalize( $cand['title'] ),
                $this->normalize( $cand['author'] ),
                $target_title_norm,
                $target_author_norm
            );
            $cand['score'] = $score;
            if ( $score > $best_score ) { $best_score = $score; $best = $cand; }
        }

        if ( $best && $best['score'] >= $this->min_score ) return $best;
        return null;
    }

    /* ======================================================================
     * Providers
     * ====================================================================== */

    /**
     * Open Library Search API:
     *   https://openlibrary.org/search.json?title=...&author=...&limit=...
     *
     * @return array<int,array> candidates in common shape (includes 'year')
     */
    protected function search_openlibrary( $title, $author, $limit = 5 ) {
        $url = add_query_arg(
            [
                'title' => $title,
                'author'=> $author,
                'limit' => $limit,
            ],
            'https://openlibrary.org/search.json'
        );

        $resp = $this->http_get( $url );
        if ( is_wp_error( $resp ) ) return [];

        $json = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $json ) || empty( $json['docs'] ) ) return [];

        $results = [];
        $nt = $this->normalize( $title );
        $na = $this->normalize( $author );

        foreach ( (array) $json['docs'] as $doc ) {
            $t = '';
            if ( isset( $doc['title'] ) && is_string( $doc['title'] ) ) {
                $t = $doc['title'];
            } elseif ( isset( $doc['title_suggest'] ) && is_string( $doc['title_suggest'] ) ) {
                $t = $doc['title_suggest'];
            }
            if ( $t === '' ) continue;

            $a = '';
            if ( isset( $doc['author_name'][0] ) ) {
                $a = (string) $doc['author_name'][0];
            } elseif ( isset( $doc['author_alternative_name'][0] ) ) {
                $a = (string) $doc['author_alternative_name'][0];
            }

            // Year mapping: prefer first_publish_year then first publish_year entry
            $year = null;
            if ( ! empty( $doc['first_publish_year'] ) ) {
                $year = (int) $doc['first_publish_year'];
            } elseif ( ! empty( $doc['publish_year'][0] ) ) {
                $year = (int) $doc['publish_year'][0];
            }

            $isbn = null;
            if ( ! empty( $doc['isbn'] ) && is_array( $doc['isbn'] ) ) {
                foreach ( $doc['isbn'] as $i ) {
                    $i = (string) $i;
                    if ( preg_match( '/^\d{13}$/', $i ) ) { $isbn = $i; break; }
                }
                if ( $isbn === null ) $isbn = (string) $doc['isbn'][0];
            }

            $score = $this->score( $this->normalize( $t ), $this->normalize( $a ), $nt, $na );

            $results[] = [
                'title'  => $t,
                'author' => $a,
                'isbn'   => $isbn,
                'source' => 'openlibrary',
                'year'   => $year ?: null,
                'score'  => $score,
            ];
        }

        return $results;
    }

    /**
     * Google Books API:
     *   https://www.googleapis.com/books/v1/volumes?q=intitle:...+inauthor:...&maxResults=...&key=API_KEY
     *
     * @return array<int,array> candidates in common shape (includes 'year')
     */
    protected function search_googlebooks( $title, $author, $limit = 5 ) {
        $q_parts = [];
        if ( $title !== '' )  $q_parts[] = 'intitle:' . $title;
        if ( $author !== '' ) $q_parts[] = 'inauthor:' . $author;

        $args = [
            'q'          => implode( ' ', $q_parts ),
            'maxResults' => max(1, min(10, (int)$limit) ),
            // You may restrict language if you wish:
            // 'langRestrict' => 'es',
            'printType'  => 'books',
        ];
        if ( $this->google_api_key ) $args['key'] = $this->google_api_key;

        $url  = add_query_arg( $args, 'https://www.googleapis.com/books/v1/volumes' );
        $resp = $this->http_get( $url );
        if ( is_wp_error( $resp ) ) return [];

        $json = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $json ) || empty( $json['items'] ) ) return [];

        $results = [];
        $nt = $this->normalize( $title );
        $na = $this->normalize( $author );

        foreach ( (array) $json['items'] as $item ) {
            $vi = $item['volumeInfo'] ?? null;
            if ( ! is_array( $vi ) ) continue;

            $t = isset( $vi['title'] ) ? (string) $vi['title'] : '';
            if ( $t === '' ) continue;

            $a = '';
            if ( ! empty( $vi['authors'][0] ) ) $a = (string) $vi['authors'][0];

            // Prefer ISBN_13 if present
            $isbn = null;
            if ( ! empty( $vi['industryIdentifiers'] ) && is_array( $vi['industryIdentifiers'] ) ) {
                foreach ( $vi['industryIdentifiers'] as $id ) {
                    if ( isset( $id['type'], $id['identifier'] ) && $id['type'] === 'ISBN_13' ) {
                        $isbn = (string) $id['identifier']; break;
                    }
                }
                if ( $isbn === null ) {
                    foreach ( $vi['industryIdentifiers'] as $id ) {
                        if ( isset( $id['identifier'] ) ) { $isbn = (string) $id['identifier']; break; }
                    }
                }
            }

            // Extract year from any publishedDate form (YYYY, YYYY-MM, YYYY-MM-DD)
            $year = $this->extract_year( $vi['publishedDate'] ?? null );

            $score = $this->score( $this->normalize( $t ), $this->normalize( $a ), $nt, $na );

            $results[] = [
                'title'  => $t,
                'author' => $a,
                'isbn'   => $isbn,
                'source' => 'googlebooks',
                'year'   => $year,
                'score'  => $score,
            ];
        }

        return $results;
    }

    /* ======================================================================
     * Utils
     * ====================================================================== */

    /** Normalize text: strip tags, remove accents, lowercase, collapse spaces. */
    protected function normalize( $text ) {
        $t = (string) $text;
        $t = wp_strip_all_tags( $t );
        $t = trim( $t );
        $t = remove_accents( $t );
        $t = mb_strtolower( $t, 'UTF-8' );
        $t = preg_replace( '/\s+/u', ' ', $t );
        return trim( $t );
    }

    /**
     * Similarity score (0..100), weighted title 60% / author 40%.
     * @param string $cand_title_norm
     * @param string $cand_author_norm
     * @param string $target_title_norm
     * @param string $target_author_norm
     */
    protected function score( $cand_title_norm, $cand_author_norm, $target_title_norm, $target_author_norm ) {
        $st = 0.0; $sa = 0.0;
        similar_text( $target_title_norm,  $cand_title_norm,  $st );
        similar_text( $target_author_norm, $cand_author_norm, $sa );
        return (0.6 * $st) + (0.4 * $sa);
    }

    /** Extracts a 4-digit year from various date formats. */
    protected function extract_year( $val ) {
        if ( ! $val ) return null;
        if ( preg_match( '/\d{4}/', (string) $val, $m ) ) return (int) $m[0];
        return null;
    }

    /**
     * HTTP GET wrapper with sane defaults.
     * @param string $url
     * @return array|\WP_Error Response from wp_remote_get()
     */
    protected function http_get( $url ) {
        $args = [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'PoliteiaChatGPT/1.0 (+WordPress)',
            ],
        ];
        $resp = wp_remote_get( esc_url_raw( $url ), $args );
        if ( is_wp_error( $resp ) ) return $resp;

        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return new \WP_Error(
                'politeia_http_error',
                sprintf(
                    /* translators: 1: HTTP status code, 2: URL */
                    __( 'External request failed (HTTP %1$d) for URL: %2$s', $this->text_domain ),
                    $code,
                    esc_url_raw( $url )
                )
            );
        }
        return $resp;
    }
}
