/**
 * politeia-chatgpt-scripts.js
 * v1.7 – Entrada de texto/audio/imagen y tabla inline (sin popup).
 */
(function () {
  if (typeof window.politeia_chatgpt_vars === 'undefined') return;

  const txt       = document.getElementById('politeia-chat-prompt');
  const btnSend   = document.getElementById('politeia-submit-btn');
  const btnMic    = document.getElementById('politeia-mic-btn');
  const fileInput = document.getElementById('politeia-file-upload');
  const statusEl  = document.getElementById('politeia-chat-status');
  const respPre   = document.getElementById('politeia-chat-response');
  if (!txt || !btnSend || !btnMic || !fileInput || !statusEl || !respPre) return;

  /* ---------- estilos mínimos para la tabla ---------- */
  (function injectStyles(){
    if (document.getElementById('politeia-inline-table-css')) return;
    const css = `
      .pcg-table{width:100%;border-collapse:collapse;font-size:14px}
      .pcg-table th,.pcg-table td{border-bottom:1px solid #eee;padding:8px;vertical-align:top;text-align:left}
      .pcg-table th{background:#fafafa}
      .pcg-wrap{margin-top:10px;padding:12px;background:#fff;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.05)}
    `;
    const st = document.createElement('style');
    st.id = 'politeia-inline-table-css';
    st.textContent = css;
    document.head.appendChild(st);
  })();

  /* ---------- helpers ---------- */
  let busy = false;
  const setStatus = (m)=> statusEl.textContent = m || '';
  const showResp = (t)=> { respPre.style.display='block'; respPre.textContent = t==null?'':String(t); };
  const setBusy = (on)=> { busy=!!on; [btnSend,btnMic,fileInput,txt].forEach(b=>b.disabled=busy); };
  const postFD = async(fd)=>{ const r=await fetch(politeia_chatgpt_vars.ajaxurl,{method:'POST',body:fd}); try{return await r.json();}catch(e){throw new Error(await r.text());} };

  function ensureTableContainer() {
    let el = document.getElementById('politeia-confirm-table');
    if (!el) {
      el = document.createElement('div');
      el.id = 'politeia-confirm-table';
      el.className = 'pcg-wrap';
      // lo insertamos justo después del <pre> de respuesta
      respPre.parentNode.insertBefore(el, respPre.nextSibling);
    }
    return el;
  }

  function parseBooksFromRaw(raw) {
    if (!raw) return [];
    let data = raw;
    try { if (typeof raw === 'string') data = JSON.parse(raw); } catch (_) { return []; }
    if (Array.isArray(data)) return data;
    if (data && Array.isArray(data.books)) return data.books;
    return [];
  }

  function esc(s){ return String(s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[m])); }

  function renderBooksTable(raw) {
    const books = parseBooksFromRaw(raw);
    const host = ensureTableContainer();
    if (!books.length) { host.innerHTML = ''; return; }

    const rows = books.map(b => `
      <tr>
        <td>${esc(b.title)}</td>
        <td>${esc(b.author)}</td>
      </tr>
    `).join('');

    host.innerHTML = `
      <table class="pcg-table">
        <thead><tr><th>Título</th><th>Autor</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  }

  function handleResult(data) {
    if (data.success) {
      const hasQueued = data.data && typeof data.data.queued !== 'undefined';
      const hasAdded  = data.data && typeof data.data.books_added !== 'undefined';
      const count     = hasQueued ? data.data.queued : (hasAdded ? data.data.books_added : 0);
      const label     = hasQueued ? 'Candidatos encolados' : 'Libros agregados';
      setStatus(`Listo. ${label}: ${count}`);

      const raw = data.data && (data.data.raw_response || null);
      showResp(raw || '');
      renderBooksTable(raw);
    } else {
      setStatus('Error');
      showResp(String(data.data || 'Error desconocido'));
      renderBooksTable(null);
    }
  }

  /* ---------- TEXTO ---------- */
  async function sendText() {
    const prompt = (txt.value || '').trim();
    if (!prompt || busy) return;
    setBusy(true); setStatus('Procesando texto…'); showResp(''); renderBooksTable(null);

    const fd = new FormData();
    fd.append('action','politeia_process_input'); fd.append('nonce', politeia_chatgpt_vars.nonce);
    fd.append('type','text'); fd.append('prompt', prompt);

    try { handleResult(await postFD(fd)); } catch (e) { setStatus('Error de red'); showResp(e.message); }
    finally { setBusy(false); }
  }
  btnSend.addEventListener('click', sendText);
  txt.addEventListener('keydown', e=>{ if (e.key==='Enter' && !e.shiftKey){ e.preventDefault(); sendText(); } });
  txt.addEventListener('input', ()=>{ txt.style.height='auto'; txt.style.height=(txt.scrollHeight)+'px'; });
  txt.dispatchEvent(new Event('input'));

  /* ---------- AUDIO ---------- */
  let mediaRecorder=null, chunks=[], recording=false;
  function isSecureOk(){ if (window.isSecureContext) return true; const h=location.hostname; return h==='localhost'||h==='127.0.0.1'; }
  function pickAudioMime(){ if (typeof MediaRecorder==='undefined') return ''; try{
    if (MediaRecorder.isTypeSupported('audio/webm')) return 'audio/webm';
    if (MediaRecorder.isTypeSupported('audio/mp4'))  return 'audio/mp4';
  }catch(_){ } return ''; }

  btnMic.addEventListener('click', async ()=>{
    if (busy) return;

    if (!recording){
      if (!isSecureOk()){ setStatus('El micrófono requiere HTTPS o localhost.'); return; }
      if (!navigator.mediaDevices?.getUserMedia){ setStatus('getUserMedia no soportado.'); return; }
      if (typeof MediaRecorder==='undefined'){ setStatus('MediaRecorder no soportado.'); return; }
      try{
        setBusy(true); setStatus('Solicitando micrófono…');
        const stream = await navigator.mediaDevices.getUserMedia({ audio:true });
        const mt=pickAudioMime(); mediaRecorder = mt? new MediaRecorder(stream,{mimeType:mt}) : new MediaRecorder(stream);
        chunks=[]; mediaRecorder.ondataavailable = e=>{ if (e.data?.size) chunks.push(e.data); };
        mediaRecorder.onstop = async ()=>{
          try{
            const outType = mediaRecorder.mimeType || mt || 'audio/webm';
            const blob = new Blob(chunks, { type: outType }); chunks = [];
            const fd = new FormData();
            fd.append('action','politeia_process_input'); fd.append('nonce', politeia_chatgpt_vars.nonce);
            fd.append('type','audio'); fd.append('audio_data', blob, 'grabacion.'+(outType.includes('mp4')?'mp4':'webm'));
            setStatus('Transcribiendo audio…'); showResp(''); renderBooksTable(null);
            handleResult(await postFD(fd));
          }catch(err){ setStatus('Error de red'); showResp(err.message); }
          finally { setBusy(false); }
        };
        mediaRecorder.start(); recording=true; setStatus('Grabando… pulsa el mic para detener.');
      }catch(err){ setBusy(false); setStatus('No se pudo acceder al micrófono.'); console.error(err); }
    } else {
      mediaRecorder?.stop(); recording=false; setStatus('Procesando audio…');
    }
  });

  /* ---------- IMAGEN ---------- */
  fileInput.addEventListener('change', async (e)=>{
    if (busy) return;
    const file = e.target.files && e.target.files[0]; if (!file) return;

    const toDataURL = (file)=> new Promise((res,rej)=>{ const r=new FileReader(); r.onload=()=>res(r.result); r.onerror=rej; r.readAsDataURL(file); });

    try{
      setBusy(true); setStatus('Analizando imagen…'); showResp(''); renderBooksTable(null);
      const dataUrl = await toDataURL(file);
      const fd = new FormData();
      fd.append('action','politeia_process_input'); fd.append('nonce', politeia_chatgpt_vars.nonce);
      fd.append('type','image'); fd.append('image_data', dataUrl);
      handleResult(await postFD(fd));
    }catch(err){ setStatus('Error al leer/enviar la imagen'); showResp(err.message); }
    finally{ fileInput.value=''; setBusy(false); }
  });

})();
/**
 * politeia-chatgpt-scripts.js
 * v2.2 — Texto, Audio (mic), Imagen + Tabla de Confirmación con Año y botones Confirm.
 * Requiere: wp_localize_script('politeia_chatgpt_vars', { ajaxurl, nonce })
 */
(function () {
  // ---------------- Boot / globals ----------------
  if (typeof window.politeia_chatgpt_vars === 'undefined') {
    console.warn('[Politeia ChatGPT] Missing politeia_chatgpt_vars. Did you call wp_localize_script()?');
    return;
  }
  const AJAX = window.politeia_chatgpt_vars.ajaxurl;
  const NONCE = window.politeia_chatgpt_vars.nonce;

  // DOM del shortcode principal (chat)
  const txt = document.getElementById('politeia-chat-prompt');
  const btnSend = document.getElementById('politeia-submit-btn');
  const btnMic = document.getElementById('politeia-mic-btn');
  const fileInput = document.getElementById('politeia-file-upload');
  const statusEl = document.getElementById('politeia-chat-status');
  const respEl = document.getElementById('politeia-chat-response');

  // Si no existe el bloque principal, salir (puede que sólo uses el shortcode de tabla)
  if (!txt || !btnSend || !btnMic || !fileInput || !statusEl || !respEl) {
    // No romper otras páginas que sólo carguen el JS.
    return;
  }

  // ---------------- Polyfills ----------------
  (function polyfillGetUserMedia() {
    if (navigator.mediaDevices === undefined) navigator.mediaDevices = {};
    if (navigator.mediaDevices.getUserMedia === undefined) {
      const legacy =
        navigator.getUserMedia ||
        navigator.webkitGetUserMedia ||
        navigator.mozGetUserMedia ||
        navigator.msGetUserMedia;
      if (legacy) {
        navigator.mediaDevices.getUserMedia = function (constraints) {
          return new Promise((resolve, reject) => legacy.call(navigator, constraints, resolve, reject));
        };
      }
    }
  })();

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
    // intenta JSON primero, si no, devuelve texto
    try {
      return await res.clone().json();
    } catch (e) {
      const text = await res.text();
      // empaqueta como error manejable
      return { success: false, data: text };
    }
  }

  function stripFences(s) {
    if (!s || typeof s !== 'string') return s;
    return s.replace(/^```json\s*|\s*```$/g, '');
  }

  function parseBooksFromAny(raw) {
    if (!raw) return [];
    try {
      const txt = typeof raw === 'string' ? stripFences(raw) : raw;
      const obj = typeof txt === 'string' ? JSON.parse(txt) : txt;
      if (Array.isArray(obj)) return obj;
      if (obj && Array.isArray(obj.books)) return obj.books;
      return [];
    } catch (_) {
      return [];
    }
  }

  function isSecureOk() {
    if (window.isSecureContext) return true;
    const h = location.hostname;
    return h === 'localhost' || h === '127.0.0.1';
  }

  function pickAudioMime() {
    if (typeof MediaRecorder === 'undefined') return '';
    try {
      if (MediaRecorder.isTypeSupported('audio/webm')) return 'audio/webm';
      if (MediaRecorder.isTypeSupported('audio/mp4')) return 'audio/mp4';
    } catch (_) {}
    return '';
  }

  // ---------------- Tabla de confirmación ----------------
  function htmlConfirmTable(items) {
    const count = items.length;
    return `
      <div class="pol-card">
        <div class="pol-card__header">
          <h3 class="pol-title">Listo. Candidatos encolados: <span data-pol-count>${count}</span></h3>
          <button class="pol-btn pol-btn-primary" data-pol-confirm-all>Confirm All</button>
        </div>
        <div class="pol-table-wrap">
          <table class="pol-table" data-pol-table>
            <thead>
              <tr>
                <th>Título</th>
                <th>Autor</th>
                <th style="width:100px">Año</th>
                <th style="width:140px"></th>
              </tr>
            </thead>
            <tbody>
              ${items.map(it => `
                <tr data-title="${escapeHtml(it.title)}" data-author="${escapeHtml(it.author)}">
                  <td class="pol-td-title">${escapeHtml(it.title)}</td>
                  <td class="pol-td-author">${escapeHtml(it.author)}</td>
                  <td class="pol-td-year">…</td>
                  <td class="pol-td-actions">
                    <button class="pol-btn pol-btn-ghost" data-pol-confirm>Confirm</button>
                  </td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>
      <style>
        .pol-card{background:#fff;border-radius:14px;padding:14px 16px;box-shadow:0 6px 20px rgba(0,0,0,.06);}
        .pol-card__header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
        .pol-title{margin:0;font-weight:600;}
        .pol-table{width:100%;border-collapse:collapse;}
        .pol-table th,.pol-table td{padding:14px 16px;border-top:1px solid #eee;text-align:left;}
        .pol-btn{padding:10px 16px;border-radius:12px;border:1px solid #e6e6e6;background:#f7f7f7;cursor:pointer}
        .pol-btn-primary{background:#1a73e8;color:#fff;border-color:#1a73e8}
        .pol-btn-ghost{background:#fafafa}
        .pol-row-confirmed{opacity:.45}
      </style>
    `;
  }

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function attachConfirmTableBehavior(root) {
    const tbody = root.querySelector('tbody');
    const btnAll = root.querySelector('[data-pol-confirm-all]');
    const countEl = root.querySelector('[data-pol-count]');
    const getRows = () => Array.from(tbody.querySelectorAll('tr:not(.pol-row-confirmed)'));

    function rowToItem(tr) {
      const yearAttr = tr.dataset.year ? parseInt(tr.dataset.year, 10) : null;
      return {
        title: tr.dataset.title || tr.querySelector('.pol-td-title')?.textContent || '',
        author: tr.dataset.author || tr.querySelector('.pol-td-author')?.textContent || '',
        year: Number.isInteger(yearAttr) ? yearAttr : null,
      };
    }

    function updateCount() {
      if (!countEl) return;
      const left = getRows().length;
      countEl.textContent = String(left);
    }

    // Lookup años
    (async () => {
      const items = getRows().map(rowToItem);
      if (!items.length) return;
      try {
        const fd = new FormData();
        fd.append('action', 'politeia_lookup_book_years');
        fd.append('nonce', NONCE);
        fd.append('items', JSON.stringify(items));
        const resp = await postFD(fd);
        if (resp && resp.success && resp.data && Array.isArray(resp.data.years)) {
          const years = resp.data.years;
          getRows().forEach((tr, i) => {
            const y = years[i];
            tr.dataset.year = Number.isInteger(y) ? String(y) : '';
            const cell = tr.querySelector('.pol-td-year');
            if (cell) cell.textContent = Number.isInteger(y) ? String(y) : '…';
          });
        } else {
          console.warn('[Politeia] Year lookup failed:', resp);
        }
      } catch (err) {
        console.warn('[Politeia] lookupYears error:', err);
      }
    })();

    // Confirm individual
    tbody.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('button[data-pol-confirm]');
      if (!btn) return;
      const tr = btn.closest('tr'); if (!tr) return;

      btn.disabled = true; setStatus('Confirmando…');
      try {
        const fd = new FormData();
        fd.append('action', 'politeia_buttons_confirm');
        fd.append('nonce', NONCE);
        fd.append('items', JSON.stringify([rowToItem(tr)]));

        const resp = await postFD(fd);
        if (resp && resp.success) {
          // Usa el conteo que devuelve el backend; si no viene, cae al nº de filas locales
          const confirmedCount =
            resp.data && Number.isInteger(resp.data.confirmed)
              ? resp.data.confirmed
              : getRows().length;
        
          // eliminar todas las filas restantes
          getRows().forEach(tr => tr.remove());
          updateCount();
        
          setStatus(`Todo confirmado: ${confirmedCount}.`);
        } else {
          btnAll.disabled = false;
          setStatus('Error al confirmar todos.');
          console.error('[Politeia Confirm All]', resp);
        }        
      } catch (err) {
        btn.disabled = false;
        setStatus('Error de red al confirmar.');
        console.error(err);
      }
    });

    // Confirm All
    if (btnAll) {
      btnAll.addEventListener('click', async () => {
        const items = getRows().map(rowToItem);
        if (!items.length) { setStatus('No hay pendientes.'); return; }

        btnAll.disabled = true; setStatus('Confirmando todos…');
        try {
          const fd = new FormData();
          fd.append('action', 'politeia_buttons_confirm_all');
          fd.append('nonce', NONCE);
          fd.append('items', JSON.stringify(items));
          const resp = await postFD(fd);

          if (resp && resp.success) {
            // eliminar todas las filas restantes
            getRows().forEach(tr => tr.remove());
            updateCount();
            setStatus('Todo confirmado.');
          } else {
            btnAll.disabled = false;
            setStatus('Error al confirmar todos.');
            console.error('[Politeia Confirm All]', resp);
          }
        } catch (err) {
          btnAll.disabled = false;
          setStatus('Error de red al confirmar todos.');
          console.error(err);
        }
      });
    }
  }

  function renderConfirmTable(items) {
    respEl.style.display = 'block';
    respEl.innerHTML = htmlConfirmTable(items);
    const card = respEl.querySelector('.pol-card');
    if (card) attachConfirmTableBehavior(card);
  }

  // ---------------- Envío de TEXTO ----------------
  async function sendText() {
    const prompt = (txt.value || '').trim();
    if (!prompt || busy) return;

    setBusy(true);
    setStatus('Procesando texto…');
    respEl.style.display = 'block';
    respEl.innerHTML = '';

    const fd = new FormData();
    fd.append('action', 'politeia_process_input');
    fd.append('nonce', NONCE);
    fd.append('type', 'text');
    fd.append('prompt', prompt);

    try {
      const data = await postFD(fd);
      if (data && data.success) {
        // Esperamos items (ideal) o raw_response con JSON {books:[...]}
        let items = [];
        if (data.data && Array.isArray(data.data.items)) items = data.data.items;
        else if (data.data && data.data.raw_response) items = parseBooksFromAny(data.data.raw_response);

        if (items.length) {
          setStatus(`Listo. Candidatos encolados: ${items.length}`);
          renderConfirmTable(items);
          // txt.value = '';
        } else {
          setStatus('No se detectaron libros.');
          respEl.innerHTML = '<pre style="white-space:pre-wrap">' + escapeHtml(String(data.data?.raw_response || '')) + '</pre>';
        }
      } else {
        setStatus('Error');
        respEl.innerHTML = '<pre style="white-space:pre-wrap">' + escapeHtml(String(data?.data || 'Error desconocido')) + '</pre>';
      }
    } catch (e) {
      setStatus('Error de red');
      respEl.innerHTML = '<pre style="white-space:pre-wrap">' + escapeHtml(e.message) + '</pre>';
    } finally {
      setBusy(false);
    }
  }

  btnSend.addEventListener('click', sendText);
  txt.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendText(); }
  });
  // auto-grow
  txt.addEventListener('input', () => {
    txt.style.height = 'auto';
    txt.style.height = (txt.scrollHeight) + 'px';
  });
  txt.dispatchEvent(new Event('input'));

  // ---------------- Audio (mic) ----------------
  let mediaRecorder = null;
  let chunks = [];
  let recording = false;

  async function startRecording() {
    if (busy) return;
    if (!isSecureOk()) { setStatus('El micrófono requiere HTTPS o localhost.'); return; }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { setStatus('getUserMedia no soportado.'); return; }
    if (typeof MediaRecorder === 'undefined') { setStatus('MediaRecorder no soportado.'); return; }

    try {
      setBusy(true);
      setStatus('Solicitando micrófono…');
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const mime = pickAudioMime();
      mediaRecorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
      chunks = [];
      mediaRecorder.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data); };
      mediaRecorder.onstop = onStopRecording;
      mediaRecorder.start();
      recording = true;
      setStatus('Grabando… pulsa el mic para detener.');
    } catch (err) {
      let msg = 'No se pudo acceder al micrófono.';
      if (err && err.name === 'NotAllowedError') msg = 'Permiso denegado. Revisa permisos del navegador.';
      if (err && err.name === 'NotFoundError') msg = 'No se encontró ningún micrófono.';
      setStatus(msg);
      console.error('[Politeia ChatGPT] getUserMedia error:', err);
      setBusy(false);
    }
  }

  function stopRecording() {
    if (mediaRecorder && recording && mediaRecorder.state === 'recording') {
      mediaRecorder.stop();
      recording = false;
      setStatus('Procesando audio…');
    }
  }

  async function onStopRecording() {
    try {
      const outType = mediaRecorder.mimeType || pickAudioMime() || 'audio/webm';
      const blob = new Blob(chunks, { type: outType });
      chunks = [];

      const fd = new FormData();
      fd.append('action', 'politeia_process_input');
      fd.append('nonce', NONCE);
      fd.append('type', 'audio');
      const ext = outType.includes('mp4') ? 'mp4' : 'webm';
      fd.append('audio_data', blob, 'grabacion.' + ext);

      respEl.style.display = 'block';
      respEl.innerHTML = '';
      const data = await postFD(fd);

      if (data && data.success) {
        let items = [];
        if (data.data && Array.isArray(data.data.items)) items = data.data.items;
        else if (data.data && data.data.raw_response) items = parseBooksFromAny(data.data.raw_response);

        if (items.length) {
          setStatus(`Listo. Candidatos encolados: ${items.length}`);
          renderConfirmTable(items);
        } else {
          setStatus('No se detectaron libros.');
          respEl.innerHTML = '<pre style="white-space:pre-wrap">' + escapeHtml(String(data.data?.raw_response || '')) + '</pre>';
        }
      } else {
        setStatus('Error');
        respEl.innerHTML = '<pre style="white-space:pre-wrap">' + escapeHtml(String(data?.data || 'Error desconocido')) + '</pre>';
      }
    } catch (err) {
      setStatus('Error de red');
      respEl.innerHTML = '<pre style="white-space:pre-wrap">' + escapeHtml(err.message) + '</pre>';
    } finally {
      setBusy(false);
    }
  }

  btnMic.addEventListener('click', () => { if (!recording) startRecording(); else stopRecording(); });

  // ---------------- Imagen ----------------
  fileInput.addEventListener('change', async (e) => {
    if (busy) return;
    const file = e.target.files && e.target.files[0];
    if (!file) return;

    function toDataURL(file) {
      return new Promise((resolve, reject) => {
        const r = new FileReader();
        r.onload = () => resolve(r.result);
        r.onerror = reject;
        r.readAsDataURL(file);
      });
    }

    try {
      setBusy(true);
      setStatus('Analizando imagen…');
      respEl.style.display = 'block';
      respEl.innerHTML = '';
      const dataUrl = await toDataURL(file);

      const fd = new FormData();
      fd.append('action', 'politeia_process_input');
      fd.append('nonce', NONCE);
      fd.append('type', 'image');
      fd.append('image_data', dataUrl);

      const data = await postFD(fd);
      if (data && data.success) {
        let items = [];
        if (data.data && Array.isArray(data.data.items)) items = data.data.items;
        else if (data.data && data.data.raw_response) items = parseBooksFromAny(data.data.raw_response);

        if (items.length) {
          setStatus(`Listo. Candidatos encolados: ${items.length}`);
          renderConfirmTable(items);
        } else {
          setStatus('No se detectaron libros.');
          respEl.innerHTML = '<pre style="white-space:pre-wrap">' + escapeHtml(String(data.data?.raw_response || '')) + '</pre>';
        }
      } else {
        setStatus('Error');
        respEl.innerHTML = '<pre style="white-space:pre-wrap">' + escapeHtml(String(data?.data || 'Error desconocido')) + '</pre>';
      }
    } catch (err) {
      setStatus('Error al leer/enviar la imagen');
      respEl.innerHTML = '<pre style="white-space:pre-wrap">' + escapeHtml(err.message) + '</pre>';
    } finally {
      fileInput.value = '';
      setBusy(false);
    }
  });
})();
