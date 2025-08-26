<?php
/**
 * Shortcode: [politeia_confirm_table]
 * Muestra los libros pendientes desde wp_politeia_book_confirm (status='pending')
 * del usuario actual. Renderiza server–side para que se vean siempre, y agrega
 * JS mínimo para Confirm / Confirm All y refresco opcional.
 */

if ( ! defined('ABSPATH') ) exit;

function politeia_confirm_table_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Debes iniciar sesión para ver tus libros pendientes.</p>';
    }

    global $wpdb;
    $uid        = get_current_user_id();
    $tbl        = $wpdb->prefix . 'politeia_book_confirm';
    $items      = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, title, author
               FROM {$tbl}
              WHERE user_id=%d AND status='pending'
              ORDER BY id DESC
              LIMIT 200",
            $uid
        ),
        ARRAY_A
    );
    $count      = is_array($items) ? count($items) : 0;

    // Nonce para AJAX (usamos el mismo que en el resto del plugin)
    $nonce = wp_create_nonce('politeia-chatgpt-nonce');
    $ajax  = admin_url('admin-ajax.php');

    ob_start();
    ?>
    <style>
      .pol-confirm-card{background:#fff;border-radius:14px;padding:14px 16px;box-shadow:0 6px 20px rgba(0,0,0,.06);margin:16px 0}
      .pol-confirm-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
      .pol-confirm-title{margin:0;font-weight:600}
      .pol-confirm-table{width:100%;border-collapse:collapse}
      .pol-confirm-table th,.pol-confirm-table td{padding:14px 16px;border-top:1px solid #eee;text-align:left}
      .pol-btn{padding:10px 16px;border-radius:12px;border:1px solid #e6e6e6;background:#f7f7f7;cursor:pointer}
      .pol-btn-primary{background:#1a73e8;color:#fff;border-color:#1a73e8}
      .pol-btn[disabled]{opacity:.6;cursor:not-allowed}
      .pol-muted{opacity:.55}
    </style>

    <div class="pol-confirm-card" id="pol-confirm-card"
         data-ajax="<?php echo esc_url($ajax); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>">
      <div class="pol-confirm-head">
        <h3 class="pol-confirm-title">
          Queued candidates: <span id="pol-confirm-count"><?php echo (int)$count; ?></span>
        </h3>
        <button class="pol-btn pol-btn-primary" id="pol-confirm-all-btn">Confirm All</button>
      </div>

      <div class="pol-confirm-table-wrap">
        <table class="pol-confirm-table">
          <thead>
            <tr>
              <th style="width:44%">Title</th>
              <th style="width:36%">Author</th>
              <th style="width:10%">Year</th>
              <th style="width:10%"></th>
            </tr>
          </thead>
          <tbody id="pol-confirm-tbody">
            <?php if ($count === 0): ?>
              <tr class="pol-empty"><td colspan="4">No pending candidates.</td></tr>
            <?php else: foreach ($items as $row): ?>
              <tr data-id="<?php echo (int)$row['id']; ?>"
                  data-title="<?php echo esc_attr($row['title']); ?>"
                  data-author="<?php echo esc_attr($row['author']); ?>">
                <td><?php echo esc_html($row['title']); ?></td>
                <td><?php echo esc_html($row['author']); ?></td>
                <td class="pol-year">…</td>
                <td>
                  <button class="pol-btn" data-pol-confirm>Confirm</button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <script>
    (function(){
      const card   = document.getElementById('pol-confirm-card');
      const tbody  = document.getElementById('pol-confirm-tbody');
      const btnAll = document.getElementById('pol-confirm-all-btn');
      const countE = document.getElementById('pol-confirm-count');
      const AJAX   = card?.dataset.ajax || '';
      const NONCE  = card?.dataset.nonce || '';

      const qs  = (sel,root=document)=>root.querySelector(sel);
      const qsa = (sel,root=document)=>Array.from(root.querySelectorAll(sel));

      function rows(){ return qsa('tr[data-id]', tbody); }
      function leftCount(){ return rows().length; }
      function updateCount(){ if(countE) countE.textContent = String(leftCount()); }

      function rowToItem(tr){
        const id     = parseInt(tr.dataset.id,10);
        const title  = tr.dataset.title || qs('td:nth-child(1)', tr)?.textContent || '';
        const author = tr.dataset.author|| qs('td:nth-child(2)', tr)?.textContent || '';
        const ytxt   = qs('.pol-year', tr)?.textContent || '';
        const y      = /^\d{3,4}$/.test(ytxt.trim()) ? parseInt(ytxt,10) : null;
        return { id, title, author, year: y };
      }

      async function postFD(fd){
        const res = await fetch(AJAX, { method:'POST', body:fd });
        try { return await res.clone().json(); }
        catch { return { success:false, data: await res.text() }; }
      }

      // ---- Lookup de años (OpenLibrary/Google) para las filas visibles
      async function lookupYears(){
        const list = rows().map(rowToItem);
        if (!list.length) return;
        try{
          const fd = new FormData();
          fd.append('action','politeia_lookup_book_years');
          fd.append('nonce', NONCE);
          fd.append('items', JSON.stringify(list));
          const resp = await postFD(fd);
          if (resp && resp.success && resp.data && Array.isArray(resp.data.years)) {
            rows().forEach((tr, i) => {
              const cell = qs('.pol-year', tr);
              const y    = resp.data.years[i];
              if (cell) cell.textContent = Number.isInteger(y) ? String(y) : '—';
            });
          } else {
            // Muestra guiones si falla
            rows().forEach(tr => { const c = qs('.pol-year',tr); if(c) c.textContent = '—'; });
          }
        } catch {
          rows().forEach(tr => { const c = qs('.pol-year',tr); if(c) c.textContent = '—'; });
        }
      }

      // ---- Confirm individual
      tbody.addEventListener('click', async (ev)=>{
        const btn = ev.target.closest('button[data-pol-confirm]');
        if (!btn) return;
        const tr = btn.closest('tr'); if (!tr) return;
        btn.disabled = true;

        try{
          const fd = new FormData();
          fd.append('action','politeia_buttons_confirm');
          fd.append('nonce', NONCE);
          fd.append('items', JSON.stringify([rowToItem(tr)]));
          const resp = await postFD(fd);
          if (resp && resp.success){
            tr.remove();
            if (!rows().length) tbody.innerHTML = '<tr class="pol-empty"><td colspan="4">No pending candidates.</td></tr>';
            updateCount();
          } else {
            btn.disabled = false;
            console.error('[Confirm] error', resp);
          }
        } catch(e){
          btn.disabled = false;
          console.error(e);
        }
      });

      // ---- Confirm All
      btnAll.addEventListener('click', async ()=>{
        const list = rows().map(rowToItem);
        if (!list.length) return;

        btnAll.disabled = true;
        try{
          const fd = new FormData();
          fd.append('action','politeia_buttons_confirm_all');
          fd.append('nonce', NONCE);
          fd.append('items', JSON.stringify(list));
          const resp = await postFD(fd);
          if (resp && resp.success){
            tbody.innerHTML = '<tr class="pol-empty"><td colspan="4">No pending candidates.</td></tr>';
            updateCount();
          } else {
            btnAll.disabled = false;
            console.error('[Confirm All] error', resp);
          }
        } catch(e){
          btnAll.disabled = false;
          console.error(e);
        }
      });

      // ---- Refresh (opcional): escucha un evento para recargar desde el servidor
      window.addEventListener('politeia:queue-updated', refreshFromServer);

      async function refreshFromServer(){
        try{
          const fd = new FormData();
          fd.append('action','politeia_confirm_table_fetch');
          fd.append('nonce', NONCE);
          const resp = await postFD(fd);
          if (resp && resp.success && Array.isArray(resp.data?.items)) {
            renderRows(resp.data.items);
            updateCount();
            lookupYears();
          }
        } catch(e){ console.error('[Refresh] error', e); }
      }

      function renderRows(items){
        if (!items.length){
          tbody.innerHTML = '<tr class="pol-empty"><td colspan="4">No pending candidates.</td></tr>';
          return;
        }
        tbody.innerHTML = items.map(it => `
          <tr data-id="${Number(it.id)}"
              data-title="${escapeHtml(it.title)}"
              data-author="${escapeHtml(it.author)}">
            <td>${escapeHtml(it.title)}</td>
            <td>${escapeHtml(it.author)}</td>
            <td class="pol-year">…</td>
            <td><button class="pol-btn" data-pol-confirm>Confirm</button></td>
          </tr>
        `).join('');
      }

      function escapeHtml(s){
        return String(s ?? '')
          .replaceAll('&','&amp;').replaceAll('<','&lt;')
          .replaceAll('>','&gt;').replaceAll('"','&quot;')
          .replaceAll("'","&#039;");
      }

      // Inicial: mirar años para lo que ya está renderizado
      lookupYears();

      // (Opcional) auto-refresh suave 1 vez a los 1.5s por si justo vienes de subir foto:
      setTimeout(refreshFromServer, 1500);
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('politeia_confirm_table', 'politeia_confirm_table_shortcode');


/**
 * Endpoint de refresco (usado por el JS de arriba).
 * Devuelve los pendientes del usuario logueado.
 */
function politeia_confirm_table_fetch_ajax(){
    try {
        check_ajax_referer('politeia-chatgpt-nonce','nonce');
        if ( ! is_user_logged_in() ) {
            wp_send_json_success(['items'=>[]]); // vacío si no logueado
        }
        global $wpdb;
        $uid  = get_current_user_id();
        $tbl  = $wpdb->prefix . 'politeia_book_confirm';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, author
                   FROM {$tbl}
                  WHERE user_id=%d AND status='pending'
                  ORDER BY id DESC
                  LIMIT 200",
                $uid
            ),
            ARRAY_A
        );
        wp_send_json_success(['items' => $rows ?: []]);
    } catch (Throwable $e){
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            wp_send_json_error($e->getMessage());
        }
        wp_send_json_error('error');
    }
}
add_action('wp_ajax_politeia_confirm_table_fetch', 'politeia_confirm_table_fetch_ajax');
add_action('wp_ajax_nopriv_politeia_confirm_table_fetch', 'politeia_confirm_table_fetch_ajax'); // si quieres permitir visitantes
