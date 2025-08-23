<?php
/**
 * Politeia ChatGPT Shortcode + AJAX
 * - Render del UI (input + mic + imagen)
 * - Enqueue del JS
 * - Handler AJAX unificado (texto, audio, imagen) con instrucciones desde Admin
 */

if ( ! defined('ABSPATH') ) exit;

/** ========= Enqueue del JS ========= */
function politeia_chatgpt_enqueue_scripts() {
    if ( is_admin() ) return;

    wp_enqueue_script(
        'politeia-chatgpt-scripts',
        plugin_dir_url(__FILE__) . 'js/politeia-chatgpt-scripts.js',
        [],
        '1.4',
        true
    );

    wp_localize_script(
        'politeia-chatgpt-scripts',
        'politeia_chatgpt_vars',
        [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('politeia-chatgpt-nonce'),
        ]
    );
}
add_action('wp_enqueue_scripts', 'politeia_chatgpt_enqueue_scripts');


/** ========= Shortcode ========= */
function politeia_chatgpt_shortcode_callback() {
    // Requiere token para operar
    if ( empty( get_option('politeia_chatgpt_api_token') ) ) {
        return '<p>Error: La funcionalidad de IA no está configurada. Por favor, añade un token de API en PoliteiaGPT → General.</p>';
    }

    ob_start();
    ?>
    <style>
      .politeia-chat-container { max-width: 700px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
      .politeia-chat-input-bar { display: flex; align-items: center; gap: 6px; padding: 8px; border: 1px solid #e0e0e0; border-radius: 999px; background: #fff; box-shadow: 0 4px 10px rgba(0, 0, 0, .07); }
      #politeia-chat-prompt { flex-grow: 1; border: none; outline: none; background: transparent; font-size: 16px; padding: 8px; resize: none; line-height: 1.5; }
      .politeia-icon-button { background: transparent; border: none; cursor: pointer; padding: 8px; display: inline-flex; align-items: center; justify-content: center; color: #555; border-radius: 50%; }
      .politeia-icon-button:hover { background-color: #f0f0f0; }
      .politeia-chat-response-area { margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 8px; white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
      #politeia-chat-status { margin-top: 10px; text-align: center; color: #333; }
    </style>

    <div class="politeia-chat-container">
      <div class="politeia-chat-input-bar">
        <input type="file" id="politeia-file-upload" accept="image/*" style="display:none;" />
        <label for="politeia-file-upload" class="politeia-icon-button" title="Subir imagen de tus libros">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
        </label>

        <textarea id="politeia-chat-prompt" placeholder="Describe tus libros, graba tu voz o sube una foto..." rows="1"></textarea>

        <button class="politeia-icon-button" id="politeia-mic-btn" title="Grabar tu voz narrando los libros">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
        </button>

        <button class="politeia-icon-button" id="politeia-submit-btn" title="Enviar texto">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
        </button>
      </div>

      <div id="politeia-chat-status"></div>
      <pre id="politeia-chat-response" class="politeia-chat-response-area" style="display:none;"></pre>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('politeia_chatgpt_input', 'politeia_chatgpt_shortcode_callback');


/** ========= AJAX handler ========= */
function politeia_process_input_ajax() {
    // Seguridad
    check_ajax_referer('politeia-chatgpt-nonce', 'nonce');

    // DB
    global $wpdb;
    $table_name = $wpdb->prefix . 'politeia_user_books'; // (ya no se usa en este flujo, se deja por compat.)

    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    if (! $type) {
        wp_send_json_error('Tipo de entrada no válido.');
    }

    // Defaults de instrucciones (por si están vacías en admin)
    $default_text_instr = 'A partir del siguiente texto, extrae los libros y devuelve SOLO un JSON con la forma: { "books": [ { "title": "...", "author": "..." } ] }.';
    $default_img_instr  = 'Analiza la imagen y devuelve SOLO un JSON con { "books": [ { "title": "...", "author": "..." } ] }. Omite elementos dudosos.';

    // Instrucciones desde Admin
    $instr_text  = get_option('politeia_gpt_instruction_text', '');
    $instr_audio = get_option('politeia_gpt_instruction_audio', '');
    $instr_image = get_option('politeia_gpt_instruction_image', '');

    $instr_text  = $instr_text  !== '' ? $instr_text  : $default_text_instr;
    $instr_audio = $instr_audio !== '' ? $instr_audio : $instr_text; // fallback a texto
    $instr_image = $instr_image !== '' ? $instr_image : $default_img_instr;

    $raw_from_api = '';

    try {
        switch ($type) {
            case 'image':
                if (empty($_POST['image_data'])) {
                    throw new Exception('No se recibieron datos de imagen.');
                }
                // IMPORTANTE: la función politeia_chatgpt_process_image actualmente
                // acepta SOLO 1 parámetro ($base64_image). Si luego la actualizas
                // para recibir instrucciones, aquí podrás pasar $instr_image.
                $raw_from_api = politeia_chatgpt_process_image($_POST['image_data']);
                break;

            case 'audio':
                if (empty($_FILES['audio_data'])) {
                    throw new Exception('No se recibió el archivo de audio.');
                }

                if (! function_exists('wp_handle_upload')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }

                $file = $_FILES['audio_data'];
                // Aseguramos una extensión válida si viene sin una conocida
                if (!preg_match('/\.(webm|mp4|m4a|wav|ogg)$/i', $file['name'])) {
                    $file['name'] = 'grabacion-' . time() . '.webm';
                }

                $movefile = wp_handle_upload($file, ['test_form' => false]);

                if ($movefile && empty($movefile['error'])) {
                    $transcription = politeia_chatgpt_transcribe_audio($movefile['file'], $file['name']);
                    @unlink($movefile['file']); // limpia temporal

                    if (strpos($transcription, 'Error:') === 0) {
                        throw new Exception($transcription);
                    }

                    // Construye el prompt y llama al modelo
                    $full_prompt  = $instr_audio . "\n\nTexto:\n\"{$transcription}\"";
                    $raw_from_api = politeia_chatgpt_send_query($full_prompt);
                } else {
                    throw new Exception('Error al manejar el archivo de audio: ' . ($movefile['error'] ?? 'desconocido'));
                }
                break;

            case 'text':
                if (empty($_POST['prompt'])) {
                    throw new Exception('El texto no puede estar vacío.');
                }
                $user_text    = sanitize_textarea_field($_POST['prompt']);
                $full_prompt  = $instr_text . "\n\nTexto:\n\"{$user_text}\"";
                $raw_from_api = politeia_chatgpt_send_query($full_prompt);
                break;

            default:
                throw new Exception('Tipo de entrada no válido.');
        }

        if (strpos($raw_from_api, 'Error:') === 0) {
            throw new Exception($raw_from_api);
        }

        // ------- Parseo de JSON -------
        if (function_exists('politeia_extract_json')) {
            // Por si la respuesta viene envuelta en ```json ... ```
            $raw_from_api = politeia_extract_json($raw_from_api);
        }

        $decoded = json_decode($raw_from_api, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("La respuesta de la API no es un JSON válido. Respuesta recibida:\n" . $raw_from_api);
        }

        // Acepta { "books": [...] } o [ {...}, ... ]
        $books = [];
        if (isset($decoded['books']) && is_array($decoded['books'])) {
            $books = $decoded['books'];
        } elseif (is_array($decoded) && (empty($decoded) || isset($decoded[0]))) {
            $books = $decoded;
        } else {
            throw new Exception("La respuesta no tiene el formato esperado. Respuesta:\n" . $raw_from_api);
        }

        // ------- ENCOLAR EN wp_politeia_book_confirm -------
        if (! function_exists('politeia_chatgpt_queue_confirm_items')) {
            throw new Exception('Falta la función politeia_chatgpt_queue_confirm_items().');
        }

        $raw_payload = is_string($raw_from_api) ? $raw_from_api : wp_json_encode($raw_from_api);

        $queued = politeia_chatgpt_queue_confirm_items(
            is_array($books) ? $books : [],
            [
                'user_id'      => get_current_user_id(),
                'input_type'   => $type,                                  // 'text' | 'audio' | 'image'
                'source_note'  => ($type === 'image') ? 'vision' : $type, // nota útil para auditoría
                'raw_response' => $raw_payload,
            ]
        );

        wp_send_json_success([
            'message'      => 'Candidatos encolados para confirmación.',
            'queued'       => (int) $queued,
            'raw_response' => $raw_from_api,
        ]);

    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

add_action('wp_ajax_politeia_process_input', 'politeia_process_input_ajax');
add_action('wp_ajax_nopriv_politeia_process_input', 'politeia_process_input_ajax'); // si permites uso sin login
