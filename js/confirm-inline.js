(function(){
    const NONCE = (window.politeia_chatgpt_vars && window.politeia_chatgpt_vars.nonce) || '';
    const AJAX  = (window.politeia_chatgpt_vars && window.politeia_chatgpt_vars.ajaxurl) || '';
    if (!AJAX || !NONCE) return;
  
    const root = document.getElementById('politeia-confirm-table');
    if (!root) return;
  
    function postFD(fd){
      return fetch(AJAX, { method:'POST', body: fd })
        .then(r=>r.json().catch(async()=>({success:false,data:await r.text()})));
    }
    function q(sel, el){ return (el||document).querySelector(sel); }
    function qa(sel, el){ return Array.from((el||document).querySelectorAll(sel)); }
  
    function makeEditable(td, rowId, field){
      if (!td || td.dataset.editing === '1') return;
      const text = td.textContent.trim();
      td.dataset.editing = '1';
  
      const inp = document.createElement(field==='year' ? 'input' : 'textarea');
      inp.value = text;
      inp.className = 'pct-inline-input';
      if (field !== 'year') { inp.rows = 1; inp.style.resize='none'; }
      td.innerHTML = '';
      td.appendChild(inp);
      inp.focus();
      inp.select();
  
      const save = async () => {
        const value = inp.value.trim();
        td.dataset.editing = '0';
        td.textContent = value || (field==='year'?'':'');
        // Guardar vía AJAX
        const fd = new FormData();
        fd.append('action', 'politeia_confirm_inline_update');
        fd.append('nonce', NONCE);
        fd.append('id', String(rowId));
        fd.append('field', field);
        fd.append('value', value);
  
        const resp = await postFD(fd);
        if (!resp || !resp.success) {
          // revert UI if failed
          td.textContent = text;
          console.warn('[Politeia] inline save failed:', resp);
          return;
        }
  
        // Merge: si el backend consolidó, quitamos la fila y (opcional) refrescamos
        if (resp.data && resp.data.merged_into) {
          const tr = td.closest('tr');
          tr && tr.remove();
          return;
        }
  
        // Actualizar UI con datos devueltos (año, etc.)
        const r = resp.data && resp.data.row;
        if (r) {
          const tr = td.closest('tr');
          if (tr) {
            tr.dataset.id = String(r.id);
            const yearCell = q('[data-col="year"]', tr);
            if (yearCell) yearCell.textContent = Number.isInteger(r.year) ? String(r.year) : '…';
          }
        }
      };
  
      inp.addEventListener('keydown', (e)=>{
        if (e.key === 'Enter' && (field==='year' || e.ctrlKey || e.metaKey)) {
          e.preventDefault();
          inp.blur();
        }
        if (e.key === 'Escape') {
          td.dataset.editing = '0';
          td.textContent = text;
        }
      });
      inp.addEventListener('blur', save);
    }
  
    // Delegación: click en icono ✎
    root.addEventListener('click', (e)=>{
      const btn = e.target.closest('[data-edit]');
      if (!btn) return;
      const tr = btn.closest('tr');
      const id = tr ? parseInt(tr.dataset.id, 10) : 0;
      if (!id) return;
  
      const field = btn.dataset.edit;
      const cell  = q(`[data-col="${field}"]`, tr);
      makeEditable(cell, id, field);
    });
  })();
  