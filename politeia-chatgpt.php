<?php
/**
 * Plugin Name:       Politeia ChatGPT
 * Description:       Un plugin simple para guardar un token de API para la integración de ChatGPT.
 * Version:           1.0.0
 * Author:            Tu Nombre
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       politeia-chatgpt
 * Domain Path:       /languages
 */

// Evita el acceso directo al archivo.
if ( ! defined('ABSPATH') ) {
    exit;
}

/* -------------------------------------------------------------------------
 * Constantes del plugin
 * ------------------------------------------------------------------------- */
if ( ! defined('POLITEIA_CHATGPT_FILE') ) {
    define('POLITEIA_CHATGPT_FILE', __FILE__);
}
if ( ! defined('POLITEIA_CHATGPT_DIR') ) {
    define('POLITEIA_CHATGPT_DIR', plugin_dir_path(__FILE__));
}
if ( ! defined('POLITEIA_CHATGPT_URL') ) {
    define('POLITEIA_CHATGPT_URL', plugin_dir_url(__FILE__));
}
if ( ! defined('POLITEIA_CHATGPT_VERSION') ) {
    define('POLITEIA_CHATGPT_VERSION', '1.0.0');
}

/* -------------------------------------------------------------------------
 * i18n: cargar traducciones
 * ------------------------------------------------------------------------- */
if ( ! function_exists('politeia_chatgpt_load_textdomain') ) {
    function politeia_chatgpt_load_textdomain() {
        load_plugin_textdomain('politeia-chatgpt', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
}
add_action('plugins_loaded', 'politeia_chatgpt_load_textdomain');

/* -------------------------------------------------------------------------
 * Helper: require seguro (evita fatales si falta un archivo)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('politeia_chatgpt_safe_require') ) {
    function politeia_chatgpt_safe_require( $relative_path ) {
        $path = POLITEIA_CHATGPT_DIR . ltrim($relative_path, '/');
        if ( file_exists($path) ) {
            require_once $path;
            return true;
        }
        return false;
    }
}

/* -------------------------------------------------------------------------
 * Carga de archivos del plugin (condicional y segura)
 * ------------------------------------------------------------------------- */
// Si tienes una pantalla de admin separada, se cargará aquí si existe.
politeia_chatgpt_safe_require('admin/politeia-gpt-admin.php');

politeia_chatgpt_safe_require('politeia-chatgpt-api.php');
politeia_chatgpt_safe_require('politeia-chatgpt-whisper.php');
politeia_chatgpt_safe_require('politeia-chatgpt-shortcode.php');



// Módulos book-detection
// Cargar SIEMPRE las clases base del módulo de libros
politeia_chatgpt_safe_require('modules/book-detection/class-book-db-handler.php');
politeia_chatgpt_safe_require('modules/book-detection/class-book-external-api.php');
politeia_chatgpt_safe_require('modules/book-detection/class-book-confirm-schema.php');
politeia_chatgpt_safe_require('modules/book-detection/functions-book-confirm-queue.php');
politeia_chatgpt_safe_require('modules/shortcode/confirm-table-shortcode.php');

// Endpoints AJAX
politeia_chatgpt_safe_require('modules/book-detection/ajax-book-year-lookup.php');
politeia_chatgpt_safe_require('modules/book-detection/ajax-confirm-inline-update.php');
politeia_chatgpt_safe_require('modules/buttons/class-buttons-confirm-controller.php');
/* -------------------------------------------------------------------------
 * Activación: crear/actualizar tabla wp_politeia_book_confirm
 * ------------------------------------------------------------------------- */
register_activation_hook(__FILE__, function () {
    if ( class_exists('Politeia_Book_Confirm_Schema') ) {
        Politeia_Book_Confirm_Schema::ensure();
    }
});

/* (Opcional) Rescate en runtime si la tabla desaparece por alguna razón */
add_action('plugins_loaded', function () {
    if ( class_exists('Politeia_Book_Confirm_Schema') && ! Politeia_Book_Confirm_Schema::exists() ) {
        Politeia_Book_Confirm_Schema::ensure();
    }
});

/* -------------------------------------------------------------------------
 * Lazy-load del módulo de detección de libros (DB handler / APIs externas / UI)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('politeia_chatgpt_load_book_detection') ) {
    function politeia_chatgpt_load_book_detection() {
        static $loaded = false;
        if ( $loaded ) return;

        $base = POLITEIA_CHATGPT_DIR . 'modules/book-detection/';

        if ( file_exists($base . 'class-book-db-handler.php') ) {
            require_once $base . 'class-book-db-handler.php';
        }
        if ( file_exists($base . 'class-book-external-api.php') ) {
            require_once $base . 'class-book-external-api.php';
        }
        if ( file_exists($base . 'class-book-confirm-schema.php') ) {
            require_once $base . 'class-book-confirm-schema.php';
        }
        if ( file_exists($base . 'class-book-confirmation-table.php') ) {
            require_once $base . 'class-book-confirmation-table.php';
        }

        $loaded = true;
    }
}

/* -------------------------------------------------------------------------
 * Ajustes (Settings API) — envueltos en function_exists para evitar colisión
 * con archivos admin externos que puedan definir lo mismo.
 * ------------------------------------------------------------------------- */
if ( ! function_exists('politeia_chatgpt_add_admin_menu') ) {
    function politeia_chatgpt_add_admin_menu() {
        add_options_page(
            'Ajustes de Politeia ChatGPT',
            'Politeia ChatGPT',
            'manage_options',
            'politeia-chatgpt-settings',
            'politeia_chatgpt_settings_page_html'
        );
    }
}
add_action('admin_menu', 'politeia_chatgpt_add_admin_menu');

if ( ! function_exists('politeia_chatgpt_settings_page_html') ) {
    function politeia_chatgpt_settings_page_html() {
        if ( ! current_user_can('manage_options') ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('politeia_chatgpt_settings');
                do_settings_sections('politeia-chatgpt-settings');
                submit_button( __('Guardar Token', 'politeia-chatgpt') );
                ?>
            </form>
        </div>
        <?php
    }
}

if ( ! function_exists('politeia_chatgpt_register_settings') ) {
    function politeia_chatgpt_register_settings() {
        register_setting(
            'politeia_chatgpt_settings',
            'politeia_chatgpt_api_token',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => ''
            )
        );

        add_settings_section(
            'politeia_chatgpt_settings_section',
            __('Configuración del Token de API', 'politeia-chatgpt'),
            'politeia_chatgpt_settings_section_callback',
            'politeia-chatgpt-settings'
        );

        add_settings_field(
            'politeia_chatgpt_api_token_field',
            __('Token de API de OpenAI', 'politeia-chatgpt'),
            'politeia_chatgpt_api_token_field_callback',
            'politeia-chatgpt-settings',
            'politeia_chatgpt_settings_section'
        );
    }
}
add_action('admin_init', 'politeia_chatgpt_register_settings');

if ( ! function_exists('politeia_chatgpt_settings_section_callback') ) {
    function politeia_chatgpt_settings_section_callback() {
        echo '<p>' . esc_html__( 'Introduce tu token de API de OpenAI para que el plugin pueda comunicarse con la API de ChatGPT.', 'politeia-chatgpt' ) . '</p>';
    }
}

if ( ! function_exists('politeia_chatgpt_api_token_field_callback') ) {
    function politeia_chatgpt_api_token_field_callback() {
        $token = get_option('politeia_chatgpt_api_token');
        ?>
        <input type="password" name="politeia_chatgpt_api_token" value="<?php echo esc_attr( $token ); ?>" class="regular-text" autocomplete="off">
        <p class="description">
            <?php echo wp_kses_post( sprintf(
                /* translators: %s: OpenAI API keys url */
                __( 'Puedes encontrar tu token en la página de %s.', 'politeia-chatgpt' ),
                '<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">'.esc_html__('llaves de API de OpenAI', 'politeia-chatgpt').'</a>'
            ) ); ?>
        </p>
        <?php
    }
}

/* -------------------------------------------------------------------------
 * (Opcional) Punto único para acceder al token (por si luego cambias el storage)
 * ------------------------------------------------------------------------- */
if ( ! function_exists('politeia_chatgpt_get_api_token') ) {
    function politeia_chatgpt_get_api_token() {
        return (string) get_option('politeia_chatgpt_api_token', '');
    }
}
