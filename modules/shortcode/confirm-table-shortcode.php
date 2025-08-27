<?php
/**
 * Shortcode: [politeia_confirm_table]
 * - Lista pendientes desde wp_politeia_book_confirm (usuario actual)
 * - Botón Confirm / Confirm All (usa endpoints ya existentes)
 * - Íconos ✎ para editar Título/Autor inline y guardar automáticamente
 * - Tras editar, relanza lookup del año para esa fila
 * - Muestra "In Shelf" como link cuando el libro ya está en la biblioteca (sin botón Confirm)
 */

if ( ! defined('ABSPATH') ) exit;

function politeia_confirm_table_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<p>You must be logged in.</p>';
	}

	$user_id = get_current_user_id();

	// Obtener filas ya marcadas con "In Shelf"
	if ( class_exists('Politeia_Book_Confirm_Schema') ) {
		$rows = Politeia_Book_Confirm_Schema::get_confirm_rows_for_user(
			$user_id,
			['pending'],
			200,
			0
		);
	} else {
		// Fallback (sin marcado "In Shelf", no debería ocurrir)
		global $wpdb;
		$tbl  = $wpdb->prefix . 'politeia_book_confirm';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, author FROM {$tbl}
				 WHERE user_id=%d AND status='pending'
				 ORDER BY id DESC",
				$user_id
			),
			ARRAY_A
		);
	}

	// Cantidad de filas confirmables (sin "In Shelf")
	$confirmables = 0;
	foreach ( $rows as $r ) {
		if ( empty($r['already_in_shelf']) || empty($r['shelf_slug']) ) {
			$confirmables++;
		}
	}

	ob_start();
	$nonce = wp_create_nonce('politeia-chatgpt-nonce');
	?>
	<div id="pol-confirm" class="pol-confirm" data-nonce="<?php echo esc_attr($nonce); ?>">
		<div class="pol-card">
			<div class="pol-card__header">
				<h3 class="pol-title">
					Queued candidates:
					<span id="pol-count"><?php echo (int) count($rows); ?></span>
				</h3>
				<button class="pol-btn pol-btn-primary" id="pol-confirm-all" <?php disabled( $confirmables === 0 ); ?>>
					Confirm All
				</button>
			</div>

			<div class="pol-table-wrap">
				<table class="pol-table" id="pol-table">
					<thead>
						<tr>
							<th>Title</th>
							<th>Author</th>
							<th style="width:120px">Year</th>
							<th style="width:220px">Action</th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty($rows) ) : ?>
						<tr><td colspan="4">No pending candidates.</td></tr>
					<?php else : foreach ( $rows as $r ) : ?>
						<tr class="pol-row" data-id="<?php echo (int) $r['id']; ?>">
							<td class="pol-td">
								<span class="pol-cell" data-field="title">
									<span class="pol-text"><?php echo esc_html($r['title']); ?></span>
									<button class="pol-edit" title="Edit" aria-label="Edit title">✎</button>
								</span>
							</td>
							<td class="pol-td">
								<span class="pol-cell" data-field="author">
									<span class="pol-text"><?php echo esc_html($r['author']); ?></span>
									<button class="pol-edit" title="Edit" aria-label="Edit author">✎</button>
								</span>
							</td>
							<td class="pol-td pol-year"><span class="pol-year-text">…</span></td>
							<td class="pol-td pol-actions">
								<?php if ( ! empty($r['already_in_shelf']) && ! empty($r['shelf_slug']) ) : ?>
									<?php
										// Enlace correcto a la vista del libro en la biblioteca
										$href = home_url( '/my-books/my-book-' . $r['shelf_slug'] . '/' );
									?>
									<a class="pill pill-success link-shelf" href="<?php echo esc_url( $href ); ?>">
										In Shelf
									</a>
								<?php else : ?>
									<button class="pol-btn pol-btn-ghost pol-confirm-one">Confirm</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<style>
		.pol-card{background:#fff;border-radius:14px;padding:14px 16px;box-shadow:0 6px 20px rgba(0,0,0,.06);}
		.pol-card__header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
		.pol-title{margin:0;font-weight:600;}
		.pol-table{width:100%;border-collapse:collapse;}
		.pol-table th,.pol-table td{padding:14px 16px;border-top:1px solid #eee;text-align:left;vertical-align:middle;}
		.pol-btn{padding:6px 10px;border-radius:8px;border:1px solid #e6e6e6;background:#f7f7f7;cursor:pointer;font:inherit}
		.pol-btn[disabled]{opacity:.45;cursor:not-allowed}
		.pol-btn-primary{background:#1a73e8;color:#fff;border-color:#1a73e8}
		.pol-btn-ghost{background:#eaf2fe;border-color:#cfe0fd}
		.pol-edit{margin-left:8px;font-size:12px;line-height:1;border:0;background:#f0f0f0;border-radius:8px;padding:4px 6px;cursor:pointer}
		.pol-input{width:100%;max-width:600px;padding:6px 8px;border:1px solid #ddd;border-radius:8px;font:inherit;}
		.pol-row.saving{opacity:.6}
		.pill{display:inline-block;padding:.25rem .6rem;border-radius:9999px;font-size:.85em;border:1px solid #bde5c8;background:#e7f7ec;color:#166534;margin-right:8px}
		.pill-success{ /* alias visual */ }
		.link-shelf{font-weight:600;text-decoration:none}
		.link-shelf:hover{text-decoration:underline}
	</style>

	<script>
	(function(){
		const root  = document.getElementById('pol-confirm');
		if (!root) return;

		const NONCE = root.dataset.nonce || '';
		const AJAX  = (window.politeia_chatgpt_vars && window.politeia_chatgpt_vars.ajaxurl)
			? window.politeia_chatgpt_vars.ajaxurl
			: (window.ajaxurl || '/wp-admin/admin-ajax.php');

		function q(sel, el){ return (el||document).querySelector(sel); }
		function qa(sel, el){ return Array.from((el||document).querySelectorAll(sel)); }

		function setCount(n){
			const c = q('#pol-count');
			if (c) c.textContent = String(n);
		}

		async function postFD(fd){
			const res = await fetch(AJAX, { method:'POST', body:fd });
			try { return await res.clone().json(); }
			catch(_e){ return { success:false, data: await res.text() }; }
		}

		// -------- Lookup de años al cargar --------
		async function lookupYearsForVisible(){
			const rows = qa('tr.pol-row', root);
			if (!rows.length) return;

			const items = rows.map(tr => ({
				title:  q('[data-field="title"] .pol-text', tr)?.textContent?.trim() || '',
				author: q('[data-field="author"] .pol-text', tr)?.textContent?.trim() || ''
			}));
			try{
				const fd = new FormData();
				fd.append('action','politeia_lookup_book_years');
				fd.append('nonce', NONCE);
				fd.append('items', JSON.stringify(items));
				const resp = await postFD(fd);
				if (resp && resp.success && resp.data && Array.isArray(resp.data.years)){
					rows.forEach((tr, i) => {
						const y = resp.data.years[i];
						const cell = q('.pol-year-text', tr);
						if (cell) cell.textContent = Number.isInteger(y) ? String(y) : '…';
					});
				}
			} catch(e){
				console.warn('[Confirm Table] year lookup failed', e);
			}
		}

		// -------- Edición inline (título/autor) --------
		root.addEventListener('click', (ev)=>{
			const btn = ev.target.closest('.pol-edit');
			if (!btn) return;

			const cell = btn.closest('.pol-cell');
			const tr   = btn.closest('tr.pol-row');
			if (!cell || !tr) return;

			const field = cell.dataset.field; // title|author
			const textEl = q('.pol-text', cell);
			if (!field || !textEl) return;

			// ya en modo edición?
			if (q('input.pol-input', cell)) return;

			const current = textEl.textContent;
			const input = document.createElement('input');
			input.type = 'text';
			input.className = 'pol-input';
			input.value = current;

			// swap
			textEl.style.display = 'none';
			cell.appendChild(input);
			input.focus();
			input.select();

			const done = async (commit)=>{
				input.removeEventListener('blur', onBlur);
				input.removeEventListener('keydown', onKey);
				if (!commit){
					cell.removeChild(input);
					textEl.style.display = '';
					return;
				}
				const value = input.value.trim();
				if (value === '' || value === current){
					cell.removeChild(input);
					textEl.style.display = '';
					return;
				}

				try{
					tr.classList.add('saving');
					const fd = new FormData();
					fd.append('action','politeia_confirm_update_field');
					fd.append('nonce', NONCE);
					fd.append('id', tr.dataset.id || '0');
					fd.append('field', field);
					fd.append('value', value);
					const resp = await postFD(fd);
					if (resp && resp.success){
						textEl.textContent = value;
						// Relookup year para esta fila
						await lookupYearsForVisible();
					} else {
						alert('Error saving change.');
						console.warn(resp);
					}
				} catch(e){
					alert('Network error.');
					console.error(e);
				} finally {
					tr.classList.remove('saving');
					cell.removeChild(input);
					textEl.style.display = '';
				}
			};

			const onBlur = () => done(true);
			const onKey = (e) => {
				if (e.key === 'Enter') { e.preventDefault(); done(true); }
				else if (e.key === 'Escape') { e.preventDefault(); done(false); }
			};

			input.addEventListener('blur', onBlur);
			input.addEventListener('keydown', onKey);
		});

		// -------- Confirm individual --------
		root.addEventListener('click', async (ev)=>{
			const btn = ev.target.closest('.pol-confirm-one');
			if (!btn) return;

			const tr = btn.closest('tr.pol-row');
			if (!tr) return;

			try{
				btn.disabled = true;
				const item = {
					title:  q('[data-field="title"] .pol-text', tr)?.textContent?.trim() || '',
					author: q('[data-field="author"] .pol-text', tr)?.textContent?.trim() || '',
					year:   (q('.pol-year-text', tr)?.textContent || '').match(/^\d{3,4}$/) ? parseInt(q('.pol-year-text', tr).textContent,10) : null,
					id:     parseInt(tr.dataset.id || '0', 10)
				};
				const fd = new FormData();
				fd.append('action','politeia_buttons_confirm'); // ya existente
				fd.append('nonce', NONCE);
				fd.append('items', JSON.stringify([item]));
				const resp = await postFD(fd);
				if (resp && resp.success){
					// elimina fila y actualiza contador
					tr.parentNode.removeChild(tr);
					setCount(qa('tr.pol-row', root).length);
					// si ya no quedan confirmables, deshabilita Confirm All
					if (!q('tr.pol-row .pol-confirm-one', root)) {
						const bAll = q('#pol-confirm-all');
						if (bAll) bAll.disabled = true;
					}
				} else {
					alert('Error confirming.');
					btn.disabled = false;
					console.warn(resp);
				}
			} catch(e){
				alert('Network error.');
				btn.disabled = false;
				console.error(e);
			}
		});

		// -------- Confirm All (solo envía filas que tienen botón Confirm) --------
		const btnAll = document.getElementById('pol-confirm-all');
		if (btnAll){
			btnAll.addEventListener('click', async ()=>{
				// Solo filas con botón .pol-confirm-one (o sea, NO "In Shelf")
				const rows = qa('tr.pol-row', root).filter(tr => q('.pol-confirm-one', tr));
				if (!rows.length) return;

				btnAll.disabled = true;
				try{
					const items = rows.map(tr => ({
						title:  q('[data-field="title"] .pol-text', tr)?.textContent?.trim() || '',
						author: q('[data-field="author"] .pol-text', tr)?.textContent?.trim() || '',
						year:   (q('.pol-year-text', tr)?.textContent || '').match(/^\d{3,4}$/) ? parseInt(q('.pol-year-text', tr).textContent,10) : null,
						id:     parseInt(tr.dataset.id || '0', 10)
					}));
					const fd = new FormData();
					fd.append('action','politeia_buttons_confirm_all'); // ya existente
					fd.append('nonce', NONCE);
					fd.append('items', JSON.stringify(items));
					const resp = await postFD(fd);
					if (resp && resp.success){
						// quita solo las filas confirmadas (las que tenían botón)
						rows.forEach(tr => tr.parentNode.removeChild(tr));
						setCount(qa('tr.pol-row', root).length);
						// si no quedan confirmables, dejar botón deshabilitado
						btnAll.disabled = !q('tr.pol-row .pol-confirm-one', root);
					} else {
						alert('Error confirming all.');
						btnAll.disabled = false;
						console.warn(resp);
					}
				} catch(e){
					alert('Network error.');
					btnAll.disabled = false;
					console.error(e);
				}
			});
		}

		// -------- Refresh cuando el input encola nuevos ítems --------
		window.addEventListener('politeia:queue-updated', () => {
			location.reload();
		});

		// inicial
		lookupYearsForVisible();
	})();
	</script>
	<?php
	return ob_get_clean();
}
add_shortcode('politeia_confirm_table', 'politeia_confirm_table_shortcode');
