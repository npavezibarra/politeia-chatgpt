<?php
// Evita el acceso directo al archivo para mayor seguridad.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Envía un archivo de audio a la API de Whisper para su transcripción.
 *
 * @param string $file_path La ruta temporal del archivo de audio subido.
 * @param string $original_name El nombre original del archivo.
 * @return string El texto transcrito o un mensaje de error.
 */
function politeia_chatgpt_transcribe_audio($file_path, $original_name) {
    $api_token = get_option('politeia_chatgpt_api_token');
    if (empty($api_token)) {
        return 'Error: No se ha configurado el token de API.';
    }

    $api_url = 'https://api.openai.com/v1/audio/transcriptions';

    // Para enviar un archivo con cURL, necesitas crear un objeto CURLFile.
    // Esto asegura que el archivo se envíe correctamente como multipart/form-data.
    $cfile = new CURLFile($file_path, mime_content_type($file_path), $original_name);

    $post_fields = [
        'file'  => $cfile,
        'model' => 'whisper-1', // El modelo de transcripción de OpenAI.
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_token,
        // Nota: No establezcas 'Content-Type' aquí, cURL lo hará automáticamente
        // para multipart/form-data cuando uses CURLFile.
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return 'Error de cURL al conectar con Whisper: ' . $curl_error;
    }
    
    if ($http_code !== 200) {
        return 'Error (' . $http_code . ') al conectar con la API de Whisper: ' . $response;
    }

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return 'Error de la API de Whisper: ' . $data['error']['message'];
    }

    return $data['text'] ?? 'No se pudo obtener la transcripción del audio.';
}