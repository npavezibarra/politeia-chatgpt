/**
 * politeia-chatgpt-scripts.js
 * v3.0 — Envío de texto/audio/imagen. NO dibuja tabla inline.
 * - Muestra estados en #politeia-chat-status
 * - Cuando hay candidatos, refresca el shortcode [politeia_confirm_table]
 *   llamando a window.PoliteiaConfirmTable.reload() (si existe) o hace
 *   fallback a recargar la página.
 */
(function () {
  // ---------------- Boot / globals ----------------
  if (typeof window.politeia_chatgpt_vars === 'undefined') return;

  const AJAX  = String(window.politeia_chatgpt_vars.ajaxurl || '');
  const NONCE = String(window.politeia_chatgpt_vars.nonce  || '');

  // DOM del bloque de chat
  const txt       = document.getElementById('politeia-chat-prompt');
  const btnSend   = document.getElementById('politeia-submit-btn');
  const btnMic    = document.getElementById('politeia-mic-btn');
  const fileInput = document.getElementById('politeia-file-upload');
  const statusEl  = document.getElementById('politeia-chat-status');
  const respPre   = document.getElementById('politeia-chat-response'); // solo para debug puntual

  // Si no existe el bloque principal, salir (no romper otras páginas)
  if (!txt || !btnSend || !btnMic || !fileInput || !statusEl || !respPre) return;

  // ---------------- Helpers UI ----------------
  let busy = false;
  function setStatus(msg){ statusEl.textContent = msg || ''; }
  function setBusy(on){
    busy = !!on;
    [btnSend, btnMic, fileInput, txt].forEach(el => el && (el.disabled = busy));
    [btnSend, btnMic].forEach(el => el && (el.style.opacity = busy ? '0.6' : '1'));
  }

  async function postFD(fd){
    const res = await fetch(AJAX, { method:'POST', body: fd });
    try { return await res.clone().json(); }
    catch (_e) { return { success:false, data: await res.text() }; }
  }

  function stripFences(s){
    if (!s || typeof s !== 'string') return s;
    return s.replace(/^```json\s*|\s*```$/g, '');
  }

  function parseBooksFromAny(raw){
    if (!raw) return [];
    try {
      const t = typeof raw === 'string' ? stripFences(raw) : raw;
      const o = typeof t === 'string' ? JSON.parse(t) : t;
      if (Array.isArray(o)) return o;
      if (o && Array.isArray(o.books)) return o.books;
      return [];
    } catch { return []; }
  }

  // --------- Refresh del shortcode (canónico) ----------
  async function refreshConfirmTable(){
    if (window.PoliteiaConfirmTable && typeof window.PoliteiaConfirmTable.reload === 'function') {
      try { await window.PoliteiaConfirmTable.reload(); return; } catch(_){}
    }
    // Fallback si el shortcode no está en la página
    location.reload();
  }

  // Dispara un evento para que el shortcode pueda engancharse si quiere
  function notifyQueued(count){
    try {
      document.dispatchEvent(new CustomEvent('politeia:confirm:queued', { detail: { count } }));
    } catch(_) {}
  }

  // --------- Manejo de respuestas del backend ----------
  function handleProcessResult(payload){
    // Normalizamos distintas respuestas posibles
    const d = payload?.data || {};
    const queuedCount = Number(
      (d.queued_count ?? d.queued ?? d.books_added ?? 0)
    );

    if (queuedCount > 0) {
      setStatus(`Listo. Candidatos encolados: ${queuedCount}.`);
      notifyQueued(queuedCount);
      refreshConfirmTable();
      // ocultamos cualquier debug anterior
      respPre.style.display = 'none';
      respPre.textContent   = '';
      return;
    }

    // Si la API no reporta conteo, intentamos deducir desde raw_response
    const fromRaw = parseBooksFromAny(d.raw_response);
    if (fromRaw.length > 0) {
      setStatus(`Listo. Candidatos encolados: ${fromRaw.length}.`);
      notifyQueued(fromRaw.length);
      refreshConfirmTable();
      respPre.style.display = 'none';
      respPre.textContent   = '';
      return;
    }

    // Nada detectado
    setStatus('No se detectaron libros.');
    respPre.style.display = 'block';
    respPre.textContent   = typeof d.raw_response === 'string' ? d.raw_response : '';
  }

  // ---------------- Envío de TEXTO ----------------
  async function sendText(){
    const prompt = (txt.value || '').trim();
    if (!prompt || busy) return;

    setBusy(true);
    setStatus('Procesando texto…');
    respPre.style.display = 'none';
    respPre.textContent   = '';

    const fd = new FormData();
    fd.append('action','politeia_process_input');
    fd.append('nonce', NONCE);
    fd.append('type','text');
    fd.append('prompt', prompt);

    try{
      const data = await postFD(fd);
      if (data && data.success) {
        handleProcessResult(data);
      } else {
        setStatus('Error');
        respPre.style.display = 'block';
        respPre.textContent   = String(data?.data || 'Error desconocido');
      }
    } catch(e){
      setStatus('Error de red');
      respPre.style.display = 'block';
      respPre.textContent   = e.message || String(e);
    } finally {
      setBusy(false);
    }
  }

  btnSend.addEventListener('click', sendText);
  txt.addEventListener('keydown', (e)=>{ if (e.key==='Enter' && !e.shiftKey){ e.preventDefault(); sendText(); } });
  // auto-grow
  txt.addEventListener('input', ()=>{ txt.style.height='auto'; txt.style.height = (txt.scrollHeight)+'px'; });
  txt.dispatchEvent(new Event('input'));

  // ---------------- Audio (mic) ----------------
  function isSecureOk(){ return window.isSecureContext || ['localhost','127.0.0.1'].includes(location.hostname); }
  function pickAudioMime(){
    if (typeof MediaRecorder === 'undefined') return '';
    try {
      if (MediaRecorder.isTypeSupported('audio/webm')) return 'audio/webm';
      if (MediaRecorder.isTypeSupported('audio/mp4'))  return 'audio/mp4';
    } catch {}
    return '';
  }

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
      const mime = pickAudioMime();
      mediaRecorder = mime ? new MediaRecorder(stream,{ mimeType:mime }) : new MediaRecorder(stream);
      chunks = [];
      mediaRecorder.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
      mediaRecorder.onstop = onStopRecording;
      mediaRecorder.start();
      recording = true;
      setStatus('Grabando… pulsa el mic para detener.');
    } catch(err){
      let msg='No se pudo acceder al micrófono.';
      if (err?.name==='NotAllowedError') msg='Permiso denegado. Revisa permisos del navegador.';
      if (err?.name==='NotFoundError')  msg='No se encontró ningún micrófono.';
      setStatus(msg);
      console.error('[Politeia ChatGPT] getUserMedia error:', err);
      setBusy(false);
    }
  }

  function stopRecording(){
    if (mediaRecorder && recording && mediaRecorder.state === 'recording') {
      mediaRecorder.stop();
      recording = false;
      setStatus('Procesando audio…');
    }
  }

  async function onStopRecording(){
    try{
      const outType = mediaRecorder.mimeType || pickAudioMime() || 'audio/webm';
      const blob = new Blob(chunks, { type: outType }); chunks = [];

      const fd = new FormData();
      fd.append('action','politeia_process_input');
      fd.append('nonce', NONCE);
      fd.append('type','audio');
      fd.append('audio_data', blob, 'grabacion.'+(outType.includes('mp4')?'mp4':'webm'));

      respPre.style.display = 'none';
      respPre.textContent   = '';

      const data = await postFD(fd);
      if (data && data.success) {
        handleProcessResult(data);
      } else {
        setStatus('Error');
        respPre.style.display = 'block';
        respPre.textContent   = String(data?.data || 'Error desconocido');
      }
    } catch(e){
      setStatus('Error de red');
      respPre.style.display = 'block';
      respPre.textContent   = e.message || String(e);
    } finally {
      setBusy(false);
    }
  }

  btnMic.addEventListener('click', () => { if(!recording) startRecording(); else stopRecording(); });

  // ---------------- Imagen ----------------
  fileInput.addEventListener('change', async (e)=>{
    if (busy) return;
    const file = e.target.files && e.target.files[0]; if (!file) return;

    function toDataURL(file){
      return new Promise((res,rej)=>{ const r=new FileReader(); r.onload=()=>res(r.result); r.onerror=rej; r.readAsDataURL(file); });
    }

    try{
      setBusy(true);
      setStatus('Analizando imagen…');
      respPre.style.display = 'none';
      respPre.textContent   = '';

      const dataUrl = await toDataURL(file);
      const fd = new FormData();
      fd.append('action','politeia_process_input');
      fd.append('nonce', NONCE);
      fd.append('type','image');
      fd.append('image_data', dataUrl);

      const data = await postFD(fd);
      if (data && data.success) {
        handleProcessResult(data);
      } else {
        setStatus('Error');
        respPre.style.display = 'block';
        respPre.textContent   = String(data?.data || 'Error desconocido');
      }
    } catch(err){
      setStatus('Error al leer/enviar la imagen');
      respPre.style.display = 'block';
      respPre.textContent   = err.message || String(err);
    } finally {
      fileInput.value = '';
      setBusy(false);
    }
  });
})();
