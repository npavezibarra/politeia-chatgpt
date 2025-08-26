/**
 * politeia-chatgpt-scripts.js
 * v3.0 — Entrada de texto/audio/imagen.
 * - No renderiza tabla inline.
 * - Tras encolar candidatos, dispara `politeia:queue-updated` para que el shortcode se refresque.
 */
(function () {
  // ---------------- Boot / globals ----------------
  if (typeof window.politeia_chatgpt_vars === 'undefined') {
    console.warn('[Politeia ChatGPT] Missing politeia_chatgpt_vars. Did you call wp_localize_script()?');
    return;
  }
  const AJAX  = String(window.politeia_chatgpt_vars.ajaxurl || '');
  const NONCE = String(window.politeia_chatgpt_vars.nonce  || '');

  // DOM del bloque de chat (input + botones)
  const txt       = document.getElementById('politeia-chat-prompt');
  const btnSend   = document.getElementById('politeia-submit-btn');
  const btnMic    = document.getElementById('politeia-mic-btn');
  const fileInput = document.getElementById('politeia-file-upload');
  const statusEl  = document.getElementById('politeia-chat-status');

  // Si no existe el bloque principal, salir sin romper otras páginas
  if (!txt || !btnSend || !btnMic || !fileInput || !statusEl) return;

  // ---------------- Helpers UI ----------------
  let busy = false;
  function setStatus(msg) { statusEl.textContent = msg || ''; }
  function setBusy(on) {
    busy = !!on;
    [btnSend, btnMic, fileInput, txt].forEach(el => { if (el) el.disabled = busy; });
    [btnSend, btnMic].forEach(el => { if (el) el.style.opacity = busy ? '0.6' : '1'; });
  }

  async function postFD(fd) {
    const res = await fetch(AJAX, { method: 'POST', body: fd });
    try {
      return await res.clone().json();
    } catch (_e) {
      const text = await res.text();
      return { success:false, data:text };
    }
  }

  // Avisa al shortcode de confirmación para que vuelva a leer DB
  function notifyQueueUpdated(count){
    try {
      window.dispatchEvent(new CustomEvent('politeia:queue-updated', {
        detail: { count: Number(count || 0) }
      }));
    } catch(_) {}
  }

  // Utilidades audio
  function isSecureOk(){
    if (window.isSecureContext) return true;
    const h = location.hostname;
    return h === 'localhost' || h === '127.0.0.1';
  }
  function pickAudioMime(){
    if (typeof MediaRecorder === 'undefined') return '';
    try {
      if (MediaRecorder.isTypeSupported('audio/webm')) return 'audio/webm';
      if (MediaRecorder.isTypeSupported('audio/mp4'))  return 'audio/mp4';
    } catch(_) {}
    return '';
  }

  // --------- Intérprete de respuesta (cuántos items hubo) ----------
  function safeParseJSON(s) {
    if (!s) return null;
    if (typeof s !== 'string') return s;
    // quitar fences ```json ... ```
    const t = s.replace(/^```json\s*|\s*```$/g, '');
    try { return JSON.parse(t); } catch { return null; }
  }

  function countItemsFromResponse(payload) {
    // payload = { success, data:{ queued_count|queued|items|candidates|raw_response } }
    if (!payload || !payload.data) return 0;

    const d = payload.data;

    // 1) respuestas explícitas
    if (typeof d.queued_count === 'number') return d.queued_count;
    if (typeof d.queued       === 'number') return d.queued;

    // 2) array de items (cuando la cola devuelve items para UI)
    if (Array.isArray(d.items))      return d.items.length;
    if (Array.isArray(d.candidates)) return d.candidates.length;

    // 3) raw_response con JSON {books:[...]} o [...]
    const raw = d.raw_response;
    const parsed = safeParseJSON(raw);
    if (Array.isArray(parsed)) return parsed.length;
    if (parsed && Array.isArray(parsed.books)) return parsed.books.length;

    return 0;
  }

  // ======================= TEXTO =======================
  async function sendText(){
    const prompt = (txt.value || '').trim();
    if (!prompt || busy) return;

    setBusy(true);
    setStatus('Procesando texto…');

    const fd = new FormData();
    fd.append('action','politeia_process_input');
    fd.append('nonce', NONCE);
    fd.append('type','text');
    fd.append('prompt', prompt);

    try{
      const resp = await postFD(fd);
      if (resp && resp.success){
        const n = countItemsFromResponse(resp);
        if (n > 0){
          setStatus(`Listo. Candidatos encolados: ${n}`);
          notifyQueueUpdated(n);
        } else {
          setStatus('No se detectaron libros.');
          notifyQueueUpdated(0);
        }
      } else {
        setStatus('Error al procesar el texto.');
        console.warn('[Politeia ChatGPT] text error:', resp);
      }
    } catch(e){
      setStatus('Error de red.');
      console.error(e);
    } finally {
      setBusy(false);
    }
  }

  btnSend.addEventListener('click', sendText);
  txt.addEventListener('keydown', (e)=>{ if (e.key==='Enter' && !e.shiftKey){ e.preventDefault(); sendText(); } });
  // auto-grow
  txt.addEventListener('input', ()=>{ txt.style.height='auto'; txt.style.height=(txt.scrollHeight)+'px'; });
  txt.dispatchEvent(new Event('input'));

  // ======================= AUDIO (mic) =======================
  let mediaRecorder=null, chunks=[], recording=false;

  async function startRecording(){
    if (busy) return;
    if (!isSecureOk()) { setStatus('El micrófono requiere HTTPS o localhost.'); return; }
    if (!navigator.mediaDevices?.getUserMedia){ setStatus('getUserMedia no soportado.'); return; }
    if (typeof MediaRecorder === 'undefined'){ setStatus('MediaRecorder no soportado.'); return; }

    try{
      setBusy(true);
      setStatus('Solicitando micrófono…');
      const stream = await navigator.mediaDevices.getUserMedia({ audio:true });
      const mt = pickAudioMime();
      mediaRecorder = mt ? new MediaRecorder(stream,{ mimeType:mt }) : new MediaRecorder(stream);
      chunks=[];
      mediaRecorder.ondataavailable = e => { if(e.data && e.data.size) chunks.push(e.data); };
      mediaRecorder.onstop = onStopRecording;
      mediaRecorder.start();
      recording = true;
      setStatus('Grabando… pulsa el mic para detener.');
    } catch(err){
      let msg='No se pudo acceder al micrófono.';
      if (err?.name==='NotAllowedError') msg='Permiso denegado. Revisa permisos del navegador.';
      if (err?.name==='NotFoundError')  msg='No se encontró ningún micrófono.';
      setStatus(msg);
      console.error(err);
      setBusy(false);
    }
  }

  function stopRecording(){
    if (mediaRecorder && recording && mediaRecorder.state==='recording'){
      mediaRecorder.stop();
      recording=false;
      setStatus('Procesando audio…');
    }
  }

  async function onStopRecording(){
    try{
      const outType = mediaRecorder.mimeType || pickAudioMime() || 'audio/webm';
      const blob = new Blob(chunks,{ type:outType }); chunks=[];
      const fd = new FormData();
      fd.append('action','politeia_process_input');
      fd.append('nonce', NONCE);
      fd.append('type','audio');
      fd.append('audio_data', blob, 'grabacion.'+(outType.includes('mp4')?'mp4':'webm'));

      const resp = await postFD(fd);
      if (resp && resp.success){
        const n = countItemsFromResponse(resp);
        if (n > 0){
          setStatus(`Listo. Candidatos encolados: ${n}`);
          notifyQueueUpdated(n);
        } else {
          setStatus('No se detectaron libros.');
          notifyQueueUpdated(0);
        }
      } else {
        setStatus('Error al procesar el audio.');
        console.warn('[Politeia ChatGPT] audio error:', resp);
      }
    } catch(e){
      setStatus('Error de red.');
      console.error(e);
    } finally {
      setBusy(false);
    }
  }

  btnMic.addEventListener('click', () => { if(!recording) startRecording(); else stopRecording(); });

  // ======================= IMAGEN =======================
  fileInput.addEventListener('change', async (e)=>{
    if (busy) return;
    const file = e.target.files && e.target.files[0]; if (!file) return;

    function toDataURL(file){
      return new Promise((resolve,reject)=>{
        const r = new FileReader();
        r.onload = ()=> resolve(r.result);
        r.onerror = reject;
        r.readAsDataURL(file);
      });
    }

    try{
      setBusy(true);
      setStatus('Analizando imagen…');
      const dataUrl = await toDataURL(file);

      const fd = new FormData();
      fd.append('action','politeia_process_input');
      fd.append('nonce', NONCE);
      fd.append('type','image');
      fd.append('image_data', dataUrl);

      const resp = await postFD(fd);
      if (resp && resp.success){
        const n = countItemsFromResponse(resp);
        if (n > 0){
          setStatus(`Listo. Candidatos encolados: ${n}`);
          notifyQueueUpdated(n);
        } else {
          setStatus('No se detectaron libros.');
          notifyQueueUpdated(0);
        }
      } else {
        setStatus('Error al procesar la imagen.');
        console.warn('[Politeia ChatGPT] image error:', resp);
      }
    } catch(e){
      setStatus('Error al leer/enviar la imagen.');
      console.error(e);
    } finally {
      fileInput.value = '';
      setBusy(false);
    }
  });

})();
