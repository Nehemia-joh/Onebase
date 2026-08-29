    </main><!-- /main -->
  </div><!-- /main area -->
</div><!-- /flex wrapper -->

<script>
// Global CSRF token for AJAX
window.CSRF_TOKEN = '<?= csrf_token() ?>';

// SweetAlert2 defaults
const Toast = Swal.mixin({
  toast: true, position: 'top-end', showConfirmButton: false,
  timer: 3000, timerProgressBar: true
});

function confirmDelete(url, name = 'this item') {
  Swal.fire({
    title: 'Delete ' + name + '?',
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Yes, delete',
    cancelButtonText: 'Cancel'
  }).then(r => { if (r.isConfirmed) window.location.href = url; });
}

// ── Type-to-search selects ─────────────────────────────────────────────
// Any <select class="searchable"> becomes a text box that filters options.
document.querySelectorAll('select.searchable').forEach(makeSearchable);
function makeSearchable(sel) {
  const wrap = document.createElement('div');
  wrap.className = 'relative' + (sel.classList.contains('flex-1') ? ' flex-1' : '');
  sel.parentNode.insertBefore(wrap, sel);
  wrap.appendChild(sel);
  // keep it focusable (not display:none) so native "required" validation still works
  sel.style.position = 'absolute'; sel.style.opacity = '0';
  sel.style.height = '1px'; sel.style.width = '1px'; sel.style.pointerEvents = 'none';
  sel.tabIndex = -1;

  const inp = document.createElement('input');
  inp.type = 'text'; inp.className = 'form-input'; inp.autocomplete = 'off';
  inp.placeholder = 'Type to search...';
  const list = document.createElement('div');
  list.className = 'searchable-list'; list.style.display = 'none';
  wrap.appendChild(inp); wrap.appendChild(list);

  const opts = [...sel.options].filter(o => o.value !== '');
  const sync = () => {
    const o = sel.options[sel.selectedIndex];
    inp.value = (o && o.value) ? o.text : '';
  };
  sync();

  function render(q) {
    const t = q.trim().toLowerCase();
    const m = opts.filter(o => o.text.toLowerCase().includes(t)).slice(0, 50);
    list.innerHTML = m.length
      ? m.map(o => `<div class="searchable-item" data-v="${o.value}">${o.text.replace(/</g,'&lt;')}</div>`).join('')
      : '<div class="searchable-empty">No match found</div>';
    list.style.display = 'block';
  }
  function pick(el) {
    sel.value = el.dataset.v;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    inp.value = el.textContent;
    list.style.display = 'none';
  }
  inp.addEventListener('focus', () => { inp.select(); render(''); });
  inp.addEventListener('input', () => render(inp.value));
  inp.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const first = list.querySelector('.searchable-item');
      if (first) pick(first);
    } else if (e.key === 'Escape') { list.style.display = 'none'; }
  });
  list.addEventListener('mousedown', e => {
    const it = e.target.closest('.searchable-item');
    if (it) { e.preventDefault(); pick(it); }
  });
  inp.addEventListener('blur', () => setTimeout(() => { list.style.display = 'none'; sync(); }, 150));
}

// ── Bulk select & delete (checkbox lists/tables) ───────────────────────
(function () {
  const boxes     = () => [...document.querySelectorAll('[data-row-check]')];
  const selectAll = document.querySelector('[data-select-all]');
  const bar       = document.querySelector('[data-bulk-bar]');
  const countEl   = document.querySelector('[data-selected-count]');
  if (!boxes().length) return;

  function refresh() {
    const n = boxes().filter(b => b.checked).length;
    if (bar) bar.classList.toggle('hidden', n === 0);
    if (countEl) countEl.textContent = n;
    if (selectAll) selectAll.checked = n > 0 && n === boxes().length;
  }
  if (selectAll) selectAll.addEventListener('change', () => {
    boxes().forEach(b => b.checked = selectAll.checked);
    refresh();
  });
  boxes().forEach(b => b.addEventListener('change', refresh));
  refresh();
})();

function confirmBulkDelete(formId, label) {
  const form = document.getElementById(formId);
  const n = document.querySelectorAll('[data-row-check]:checked').length;
  if (n === 0) return;
  Swal.fire({
    title: `Delete ${n} ${label}${n === 1 ? '' : 's'}?`,
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Yes, delete',
    cancelButtonText: 'Cancel'
  }).then(r => { if (r.isConfirmed) form.submit(); });
}

function ajaxPost(url, data, onSuccess, onError) {
  data._csrf = window.CSRF_TOKEN;
  fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.CSRF_TOKEN },
    body: JSON.stringify(data)
  })
  .then(r => r.json())
  .then(onSuccess)
  .catch(onError || console.error);
}
</script>
</body>
</html>
