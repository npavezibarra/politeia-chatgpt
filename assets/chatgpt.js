(function(){
    function qs(root, sel){ return root.querySelector(sel); }
    
    
    function setupWidget(el){
    const input = qs(el, '#pcgpt-input');
    const out = qs(el, '[data-role="output"]');
    const status = qs(el, '[data-role="status"]');
    const btnMic = qs(el, '[data-role="mic"]');
    const btnSend= qs(el, '[data-role="send"]');
    
    
    let recognizing = false;
    let rec = null;
    
    
    // Mic (dictado) — Web Speech API
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) {
    btnMic.disabled = true;
    btnMic.title = 'Dictado no soportado en este navegador';
    } else {
    rec = new SR();
    rec.lang = (window.pcgpt && pcgpt.lang) ? pcgpt.lang : 'es-CL';
    rec.interimResults = true;
    rec.continuous = false;
    
    
    rec.onstart = () => { recognizing = true; status.textContent = 'Escuchando…'; btnMic.textContent = '⏹️ Detener'; };
    rec.onerror = () => { recognizing = false; status.textContent = 'Error en reconocimiento de voz'; btnMic.textContent = '🎤 Dictar'; };
    rec.onend = () => { recognizing = false; status.textContent = ''; btnMic.textContent = '🎤 Dictar'; };
    rec.onresult = (e) => {
    let finalText = input.value;
    for (let i = e.resultIndex; i < e.results.length; i++) {
    const t = e.results[i][0].transcript;
    if (e.results[i].isFinal) finalText += (finalText ? ' ' : '') + t.trim();
    }
    input.value = finalText;
    };
    
    
    btnMic.addEventListener('click', () => {
    if (!recognizing) { try { rec.start(); } catch (err) {} }
    else { try { rec.stop(); } catch (err) {} }
    });
    }
    
    
    async function ask(){
    const prompt = (input.value || '').trim();
    if (!prompt) { input.focus(); return; }
    btnSend.disabled = true; btnMic && (btnMic.disabled = true);
    status.textContent = 'Consultando…';
    out.textContent = '';
    
    
    try {
    const res = await fetch(pcgpt.rest, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': pcgpt.nonce },
    body: JSON.stringify({ prompt })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data && data.error ? data.error : 'Error');
    out.textContent = data.reply || '';
    } catch (err) {
    out.textContent = '⚠️ ' + (err.message || 'No se pudo completar la solicitud');
    } finally {
    status.textContent = '';
    btnSend.disabled = false; if (SR) btnMic.disabled = false;
    }
    }
    
    
    btnSend.addEventListener('click', ask);
    input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); ask(); }
    });
    }
    
    
    document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('[data-pcgpt]').forEach(setupWidget);
    });
    })();