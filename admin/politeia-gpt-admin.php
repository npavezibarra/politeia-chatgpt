<?php
/**
 * Admin UI para PoliteiaGPT
 * - Menú principal "PoliteiaGPT" con pestañas "General" y "GPT Instructions".
 * - Guarda:
 *     - politeia_chatgpt_api_token        (API Key)
 *     - politeia_gpt_instruction_text     (instrucción para TEXTO)
 *     - politeia_gpt_instruction_audio    (instrucción para AUDIO)
 *     - politeia_gpt_instruction_image    (instrucción para IMAGEN)
 * - Fallback: si la de AUDIO está vacía, el handler usará la de TEXTO.
 */

if ( ! defined('ABSPATH') ) exit;

/** Sanitiza párrafos (sin HTML) */
function politeia_gpt_sanitize_paragraph( $input ) {
    $input = wp_strip_all_tags( $input ); // quita HTML/JS
    return trim( $input );
}

/** Registra settings y campos */
function politeia_gpt_register_settings() {

    // === Pestaña: General ===
    register_setting(
        'politeia_gpt_general',
        'politeia_chatgpt_api_token',
        [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]
    );

    add_settings_section(
        'politeia_gpt_section_general',
        'Configuración General',
        function () {
            echo '<p>Define tu API Key de OpenAI para habilitar las funciones del plugin.</p>';
        },
        'politeia_gpt_general'
    );

    add_settings_field(
        'politeia_chatgpt_api_token_field',
        'OpenAI API Key',
        function () {
            $token = get_option('politeia_chatgpt_api_token', '');
            echo '<input type="password" name="politeia_chatgpt_api_token" value="' . esc_attr($token) . '" class="regular-text" />';
            echo '<p class="description">Obtén tu llave en <a target="_blank" href="https://platform.openai.com/api-keys">platform.openai.com/api-keys</a>.</p>';
        },
        'politeia_gpt_general',
        'politeia_gpt_section_general'
    );

    // === Pestaña: GPT Instructions ===
    register_setting(
        'politeia_gpt_instructions',
        'politeia_gpt_instruction_text',
        [
            'type'              => 'string',
            'sanitize_callback' => 'politeia_gpt_sanitize_paragraph',
            'default'           => '',
        ]
    );
    register_setting(
        'politeia_gpt_instructions',
        'politeia_gpt_instruction_audio',
        [
            'type'              => 'string',
            'sanitize_callback' => 'politeia_gpt_sanitize_paragraph',
            'default'           => '',
        ]
    );
    register_setting(
        'politeia_gpt_instructions',
        'politeia_gpt_instruction_image',
        [
            'type'              => 'string',
            'sanitize_callback' => 'politeia_gpt_sanitize_paragraph',
            'default'           => '',
        ]
    );

    add_settings_section(
        'politeia_gpt_section_instructions',
        'GPT Instructions',
        function () {
            echo '<p>Define instrucciones por tipo de entrada. Si dejas "Audio" vacío, se usará la de "Texto".</p>';
        },
        'politeia_gpt_instructions'
    );

    // Campo TEXTO
    add_settings_field(
        'politeia_gpt_instruction_text_field',
        'Instrucción para TEXTO',
        function () {
            $val = get_option('politeia_gpt_instruction_text', '');
            if ($val === '') {
                $val = 'A partir del siguiente texto, extrae los libros mencionados y devuelve EXCLUSIVAMENTE un JSON con esta forma exacta: { "books": [ { "title": "...", "author": "..." } ] }. No incluyas comentarios, notas ni markdown.';
            }
            echo '<textarea name="politeia_gpt_instruction_text" rows="6" class="large-text" style="max-width: 900px;">' . esc_textarea($val) . '</textarea>';
            echo '<p class="description">Para prompts escritos (o transcripción de audio si no defines una instrucción específica de audio).</p>';
        },
        'politeia_gpt_instructions',
        'politeia_gpt_section_instructions'
    );

    // Campo AUDIO
    add_settings_field(
        'politeia_gpt_instruction_audio_field',
        'Instrucción para AUDIO (opcional)',
        function () {
            $val = get_option('politeia_gpt_instruction_audio', '');
            echo '<textarea name="politeia_gpt_instruction_audio" rows="6" class="large-text" style="max-width: 900px;">' . esc_textarea($val) . '</textarea>';
            echo '<p class="description">Útil para dictados: pide ignorar muletillas, corregir pronunciación de títulos, eliminar repeticiones y normalizar autores. Si se deja vacío, se usa la instrucción de TEXTO.</p>';
        },
        'politeia_gpt_instructions',
        'politeia_gpt_section_instructions'
    );

    // Campo IMAGEN
    add_settings_field(
        'politeia_gpt_instruction_image_field',
        'Instrucción para IMAGEN',
        function () {
            $val = get_option('politeia_gpt_instruction_image', '');
            if ($val === '') {
                $val = 'Analiza esta imagen (estantería de libros). Extrae los títulos y autores visibles y devuelve EXCLUSIVAMENTE un JSON con esta forma exacta: { "books": [ { "title": "...", "author": "..." } ] }. Omite elementos dudosos o parciales. Nada de markdown ni texto adicional.';
            }
            echo '<textarea name="politeia_gpt_instruction_image" rows="6" class="large-text" style="max-width: 900px;">' . esc_textarea($val) . '</textarea>';
            echo '<p class="description">Para análisis de imágenes con visión.</p>';
        },
        'politeia_gpt_instructions',
        'politeia_gpt_section_instructions'
    );
}
add_action('admin_init', 'politeia_gpt_register_settings');

/** Menú principal */
function politeia_gpt_add_menu() {
    $cap = 'manage_options';
    add_menu_page(
        'PoliteiaGPT',
        'PoliteiaGPT',
        $cap,
        'politeia-gpt',
        'politeia_gpt_render_page',
        'dashicons-art',
        59
    );
}
add_action('admin_menu', 'politeia_gpt_add_menu');

/** Render de página con tabs */
function politeia_gpt_render_page() {
    if ( ! current_user_can('manage_options') ) return;

    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
    if ( ! in_array($tab, ['general', 'instructions'], true) ) $tab = 'general';

    $base_url       = admin_url('admin.php?page=politeia-gpt');
    $url_general    = add_query_arg( ['tab' => 'general'], $base_url );
    $url_instructions = add_query_arg( ['tab' => 'instructions'], $base_url );
    ?>
    <div class="wrap">
        <h1>PoliteiaGPT</h1>
        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url($url_general); ?>" class="nav-tab <?php echo $tab === 'general' ? 'nav-tab-active' : ''; ?>">General</a>
            <a href="<?php echo esc_url($url_instructions); ?>" class="nav-tab <?php echo $tab === 'instructions' ? 'nav-tab-active' : ''; ?>">GPT Instructions</a>
        </h2>

        <?php if ($tab === 'general'): ?>
            <form action="options.php" method="post">
                <?php
                settings_fields('politeia_gpt_general');
                do_settings_sections('politeia_gpt_general');
                submit_button('Guardar cambios');
                ?>
            </form>
        <?php else: ?>
            <form action="options.php" method="post">
                <?php
                settings_fields('politeia_gpt_instructions');
                do_settings_sections('politeia_gpt_instructions');
                submit_button('Guardar instrucciones');
                ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}
