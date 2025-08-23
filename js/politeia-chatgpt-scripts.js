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
