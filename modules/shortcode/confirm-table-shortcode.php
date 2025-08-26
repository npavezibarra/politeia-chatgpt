<?php
// modules/shortcode/confirm-table-shortcode.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Shortcode: [politeia_confirm_table]
 * - Muestra los candidatos pendientes del usuario desde wp_politeia_book_confirm
 * - Consulta el año vía AJAX (politeia_lookup_book_years)
 * - Permite Confirm y Confirm All (politeia_buttons_confirm / _all)
 */
add_action('init', function () {
    add_shortcode('politeia_confirm_table', 'politeia_confirm_table_shortcode');
});

function politeia_confirm_table_shortcode( $atts = [] ) {
    if ( ! is_user_logged_in() ) {
        return '<p>'.esc_html__('You must be logged in to manage your library.', 'politeia-chatgpt').'</p>';
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $tbl     = $wpdb->prefix . 'politeia_book_confirm';

    // Obtiene los "pending" del usuario (limit por seguridad)
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, title, author
               FROM {$tbl}
              WHERE user_id=%d AND status='pending'
              ORDER BY id ASC
              LIMIT 200",
            $user_id
        ),
        ARRAY_A
    );

    // Lista de items para lookup de año y confirm
    $items = array_map(function($r){
        return [
            'title'  => (string) $r['title'],
            'author' => (string) $r['author'],
        ];
    }, (array) $rows);

    $json_items = wp_json_encode($items);
    $ajaxurl    = admin_url('admin-ajax.php');
    $nonce      = wp_create_nonce('politeia-chatgpt-nonce');
    $uid        = 'pct_' . ( function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid() );

    ob_start();
    ?>
    <div id="<?php echo esc_attr($uid); ?>" class="politeia-confirm-wrapper">
        <div class="politeia-status" data-pct="status"></div>

        <div class="politeia-confirm-card">
            <div class="politeia-confirm-card__header">
                <h3 class="politeia-title">
                    <?php
                    printf(
                        /* translators: %d = count of candidates */
                        esc_html__('Queued candidates: %d', 'politeia-chatgpt'),
                        count($rows)
                    );
                    ?>
                </h3>
                <button class="pol-btn pol-btn-primary" data-pct="confirm-all">
                    <?php echo esc_html__('Confirm All', 'politeia-chatgpt'); ?>
                </button>
            </div>

            <div class="politeia-table-wrap">
                <table class="politeia-table" data-pct="table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Title', 'politeia-chatgpt'); ?></th>
                            <th><?php echo esc_html__('Author', 'politeia-chatgpt'); ?></th>
                            <th style="width:100px"><?php echo esc_html__('Year', 'politeia-chatgpt'); ?></th>
                            <th style="width:140px"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( $rows ) : ?>
                        <?php foreach ( $rows as $r ) : ?>
                            <tr data-title="<?php echo esc_attr($r['title']); ?>"
                                data-author="<?php echo esc_attr($r['author']); ?>">
                                <td class="pct-title"><?php echo esc_html($r['title']); ?></td>
                                <td class="pct-author"><?php echo esc_html($r['author']); ?></td>
                                <td class="pct-year">…</td>
                                <td class="pct-actions">
                                    <button class="pol-btn pol-btn-ghost" data-pct="confirm">
                                        <?php echo esc_html__('Confirm', 'politeia-chatgpt'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="4"><?php echo esc_html__('No pending candidates.', 'politeia-chatgpt'); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <style>
        .politeia-confirm-card {
            background:#fff; border-radius:14px; padding:14px 16px; box-shadow:0 6px 20px rgba(0,0,0,.06);
        }
        .politeia-confirm-card__header {
            display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;
        }
        .politeia-title { margin:0; font-weight:600; }
        .politeia-table { width:100%; border-collapse:collapse; }
        .politeia-table th, .politeia-table td { padding:14px 16px; border-top:1px solid #eee; }
        .politeia-table th { text-align:left; }
        .politeia-status { text-align:center; margin:8px 0 12px; color:#333; }
        .pol-btn { padding:10px 16px; border-radius:12px; border:1px solid #e6e6e6; background:#f7f7f7; cursor:pointer; }
        .pol-btn-primary { background:#1a73e8; color:#fff; border-color:#1a73e8; }
        .pol-btn-ghost { background:#fafafa; }
        tr.pct-confirmed { opacity:.5; }
    </style>

    <script>
    (function(){
        const root    = document.getElementById('<?php echo esc_js($uid); ?>');
        if(!root) return;

        const ajaxurl = '<?php echo esc_url( $ajaxurl ); ?>';
        const nonce   = '<?php echo esc_js( $nonce ); ?>';
        const items   = <?php echo $json_items ? $json_items : '[]'; ?>;

        const statusEl = root.querySelector('[data-pct="status"]');
        const tbody    = root.querySelector('tbody');
        const btnAll   = root.querySelector('[data-pct="confirm-all"]');

        function setStatus(msg){ if(statusEl) statusEl.textContent = msg || ''; }
        async function postFD(fd){
            const res = await fetch(ajaxurl, { method:'POST', body: fd });
            try { return await res.clone().json(); }
            catch(e){ return { success:false, data: await res.text() }; }
        }
        function rowToItem(tr){
            const y = tr.dataset.year ? parseInt(tr.dataset.year,10) : null;
            return {
                title:  tr.dataset.title  || tr.querySelector('.pct-title')?.textContent || '',
                author: tr.dataset.author || tr.querySelector('.pct-author')?.textContent || '',
                year:   Number.isInteger(y) ? y : null
            };
        }

        // 1) Lookup de años
        if(items.length){
            setStatus('<?php echo esc_js(__('Looking up years…', 'politeia-chatgpt')); ?>');
            const fd = new FormData();
            fd.append('action','politeia_lookup_book_years');
            fd.append('nonce', nonce);
            fd.append('items', JSON.stringify(items));
            postFD(fd).then(resp=>{
                if(resp && resp.success && resp.data && Array.isArray(resp.data.years)){
                    const years = resp.data.years;
                    [...tbody.querySelectorAll('tr')].forEach((tr,i)=>{
                        const y = years[i];
                        tr.dataset.year = (Number.isInteger(y) ? String(y) : '');
                        const cell = tr.querySelector('.pct-year');
                        if(cell) cell.textContent = (Number.isInteger(y) ? String(y) : '…');
                    });
                    setStatus('');
                } else {
                    setStatus('<?php echo esc_js(__('Year lookup failed.', 'politeia-chatgpt')); ?>');
                }
            }).catch(e=> setStatus('Error: '+ e));
        }

        // 2) Confirm individual
        tbody.addEventListener('click', async (ev)=>{
            const btn = ev.target.closest('button[data-pct="confirm"]');
            if(!btn) return;
            const tr = btn.closest('tr'); if(!tr) return;

            btn.disabled = true; setStatus('<?php echo esc_js(__('Confirming…', 'politeia-chatgpt')); ?>');
            const fd = new FormData();
            fd.append('action','politeia_buttons_confirm');
            fd.append('nonce', nonce);
            fd.append('items', JSON.stringify([rowToItem(tr)]));

            const resp = await postFD(fd);
            if(resp && resp.success){
                tr.classList.add('pct-confirmed');
                setStatus('<?php echo esc_js(__('Confirmed.', 'politeia-chatgpt')); ?>');
            } else {
                btn.disabled = false;
                setStatus('<?php echo esc_js(__('Error confirming.', 'politeia-chatgpt')); ?>');
                console.error('[Politeia Confirm]', resp);
            }
        });

        // 3) Confirm All
        if(btnAll){
            btnAll.addEventListener('click', async ()=>{
                const list = [...tbody.querySelectorAll('tr:not(.pct-confirmed)')].map(tr=>rowToItem(tr));
                if(!list.length){ setStatus('<?php echo esc_js(__('Nothing to confirm.', 'politeia-chatgpt')); ?>'); return; }

                btnAll.disabled = true; setStatus('<?php echo esc_js(__('Confirming all…', 'politeia-chatgpt')); ?>');
                const fd = new FormData();
                fd.append('action','politeia_buttons_confirm_all');
                fd.append('nonce', nonce);
                fd.append('items', JSON.stringify(list));

                const resp = await postFD(fd);
                if (resp && resp.success) {
                const confirmedCount =
                    resp.data && Number.isInteger(resp.data.confirmed)
                    ? resp.data.confirmed
                    : list.length;

                [...tbody.querySelectorAll('tr')].forEach(tr => tr.remove());
                setStatus(`All confirmed: ${confirmedCount}.`);
                } else {
                btnAll.disabled = false;
                setStatus('Error confirming all.');
                console.error('[Politeia Confirm All]', resp);
                }
            });
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}
