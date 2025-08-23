<?php
/**
 * Politeia ChatGPT – API helpers
 *
 * - Envía prompts de texto y/o imagen a OpenAI y devuelve SIEMPRE JSON puro
 *   gracias a `response_format` con JSON Schema.
 * - Incluye un helper opcional `politeia_extract_json()` por si alguna vez
 *   recibieras Markdown con ```json ... ``` (no debería ocurrir con el schema).
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Devuelve el token de OpenAI desde opciones.
 *
 * @return string
 */
function politeia_chatgpt_get_api_token() {
    return (string) get_option('politeia_chatgpt_api_token', '');
}

/**
 * JSON Schema reutilizable para forzar la salida:
 * {
 *   "books": [ { "title": "…", "author": "…" }, ... ]
 * }
 *
 * @return array
 */
function politeia_chatgpt_books_schema() {
    return [
        'type'   => 'json_schema',
        'json_schema' => [
            'name'   => 'books_list',
            'strict' => true,
            'schema' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => [ 'books' ],
                'properties'           => [
                    'books' => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => [ 'title', 'author' ],
                            'properties'           => [
                                'title'  => [ 'type' => 'string' ],
                                'author' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Helper opcional: extrae JSON desde un bloque con fences ```json ... ```
 * Solo por robustez extra; con `response_format` no debería hacer falta.
 *
 * @param string $s
 * @return string JSON "puro" si detecta fences; si no, devuelve $s tal cual.
 */
function politeia_extract_json( $s ) {
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $s, $m)) {
        return trim($m[1]);
    }
    return trim($s, "\xEF\xBB\xBF \t\n\r\0\x0B");
}

/**
 * Llama al endpoint /v1/chat/completions con payload dado
 * y devuelve el string del contenido del primer choice o un mensaje de error.
 *
 * @param array $payload
 * @return string
 */
function politeia_chatgpt_post_payload( array $payload ) {
    $api_token = politeia_chatgpt_get_api_token();
    if (empty($api_token)) {
        return 'Error: No se ha configurado el token de API.';
    }

    $api_url = 'https://api.openai.com/v1/chat/completions';

    $args = [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_token,
        ],
        'body'    => wp_json_encode($payload),
        'method'  => 'POST',
        'timeout' => 90,
    ];

    $response = wp_remote_post($api_url, $args);

    if (is_wp_error($response)) {
        return 'Error al conectar con la API: ' . $response->get_error_message();
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['error'])) {
        return 'Error de la API: ' . $data['error']['message'];
    }

    if (isset($data['choices'][0]['message']['content'])) {
        // Debe ser JSON puro gracias a response_format; devolvemos tal cual.
        return $data['choices'][0]['message']['content'];
    }

    return 'No se pudo obtener una respuesta válida de la API.';
}

/**
 * Envía un prompt de TEXTO al modelo (sin imagen).
 * Debe devolver un JSON con forma { "books": [ {title, author}, ... ] }
 *
 * @param string $prompt Prompt completo (ya preparado por quien llama)
 * @return string JSON string o mensaje de error
 */
function politeia_chatgpt_send_query( $prompt ) {
    // Mensaje del usuario
    $messages = [
        [
            'role'    => 'user',
            'content' => $prompt,
        ],
    ];

    $payload = [
        'model'            => 'gpt-4o', // 4o soporta visión y structured output
        'messages'         => $messages,
        'temperature'      => 0,
        'max_tokens'       => 1500,
        'response_format'  => politeia_chatgpt_books_schema(), // fuerza JSON puro
    ];

    return politeia_chatgpt_post_payload($payload);
}

/**
 * Envía una IMAGEN (dataURL/base64 o URL pública) + texto de instrucción
 * al modelo de visión y devuelve JSON con la misma forma que arriba.
 *
 * @param string $base64_image dataURL o URL
 * @param string $instruction  (opcional) texto adicional al modelo
 * @return string JSON string o mensaje de error
 */
function politeia_chatgpt_process_image( $base64_image, $instruction = '' ) {
    $prompt = $instruction;
    if (!$prompt) {
        // Prompt por defecto: el shortcode/handler puede sobreescribirlo si quiere
        $prompt =
            "Analiza esta imagen de una estantería de libros. " .
            "Extrae los libros visibles y devuelve EXCLUSIVAMENTE un JSON con esta forma exacta:\n" .
            "{ \"books\": [ { \"title\": \"...\", \"author\": \"...\" } ] }\n" .
            "No incluyas comentarios, ni markdown, ni texto adicional.";
    }

    $messages = [
        [
            'role'    => 'user',
            'content' => [
                [ 'type' => 'text', 'text' => $prompt ],
                [ 'type' => 'image_url', 'image_url' => [ 'url' => $base64_image ] ],
            ],
        ],
    ];

    $payload = [
        'model'            => 'gpt-4o',
        'messages'         => $messages,
        'temperature'      => 0,
        'max_tokens'       => 2000,
        'response_format'  => politeia_chatgpt_books_schema(), // fuerza JSON puro
    ];

    return politeia_chatgpt_post_payload($payload);
}
