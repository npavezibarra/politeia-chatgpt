<?php
/**
 * Class: Politeia_Book_Confirm_Schema
 * Purpose: Create/upgrade the confirmation queue table (wp_politeia_book_confirm).
 * Language: English, translatable via 'politeia-chatgpt'.
 *
 * This module only manages the schema for the confirmation queue used by
 * Politeia ChatGPT. It does NOT create the canonical books tables (those are
 * owned by the Politeia Reading plugin).
 */

if ( ! defined('ABSPATH') ) exit;

class Politeia_Book_Confirm_Schema {

    /** @var string */
    protected static $td = 'politeia-chatgpt';

    /**
     * Return full table name with prefix.
     * @return string
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'politeia_book_confirm';
    }

    /**
     * Check if table exists.
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
     * Ensure table is created/updated (idempotent).
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
          input_type VARCHAR(20) NOT NULL,              -- text | audio | image
          source_note VARCHAR(190) DEFAULT '',
          title VARCHAR(255) NOT NULL,
          author VARCHAR(255) NOT NULL,
          normalized_title VARCHAR(255) DEFAULT NULL,
          normalized_author VARCHAR(255) DEFAULT NULL,
          title_author_hash CHAR(64) DEFAULT NULL,      -- dedupe
          external_isbn VARCHAR(32) DEFAULT NULL,
          external_source VARCHAR(50) DEFAULT NULL,     -- openlibrary | googlebooks | null
          external_score FLOAT DEFAULT NULL,
          match_method VARCHAR(30) DEFAULT NULL,        -- hash | normalized_like | raw_like | external | none
          matched_book_id BIGINT UNSIGNED DEFAULT NULL, -- if matched with internal catalog
          status ENUM('pending','confirmed','discarded') NOT NULL DEFAULT 'pending',
          raw_response LONGTEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_user_status (user_id, status),
          KEY idx_hash (title_author_hash),
          KEY idx_matched (matched_book_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
