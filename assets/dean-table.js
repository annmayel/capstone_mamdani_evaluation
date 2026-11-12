document.querySelectorAll('[data-dean-table]').forEach((container) => {
  const table = container.querySelector('table');
  if (!table) return;

  const rows = Array.from(table.querySelectorAll('tbody tr[data-table-row]'));
  const search = container.querySelector('[data-table-search]');
  const filters = Array.from(container.querySelectorAll('[data-table-filter]'));
  const pageSizeControl = container.querySelector('[data-page-size]');
  const count = container.querySelector('[data-table-count]');
  const pagination = container.querySelector('[data-table-pagination]');
  const pageIndicator = container.querySelector('[data-page-indicator]');
  const emptyState = container.querySelector('[data-table-empty]');
  let page = 1;

  function render() {
    const query = (search?.value || '').trim().toLocaleLowerCase();
    const filteredRows = rows.filter((row) => {
      const matchesSearch = !query || (row.dataset.search || row.textContent).toLocaleLowerCase().includes(query);
      return matchesSearch && filters.every((filter) => {
        const selected = filter.value.trim().toLocaleLowerCase();
        return !selected || (row.dataset[filter.dataset.tableFilter] || '').toLocaleLowerCase() === selected;
      });
    });

    const pageSize = Number(pageSizeControl?.value || 10);
    const pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize));
    page = Math.min(page, pageCount);
    const firstVisible = (page - 1) * pageSize;
    const visibleRows = new Set(filteredRows.slice(firstVisible, firstVisible + pageSize));
    rows.forEach((row) => { row.hidden = !visibleRows.has(row); });

    if (count) {
      const firstRecord = filteredRows.length ? firstVisible + 1 : 0;
      const lastRecord = Math.min(firstVisible + pageSize, filteredRows.length);
      count.textContent = `Showing ${firstRecord}-${lastRecord} of ${filteredRows.length} records`;
    }
    if (pagination) pagination.hidden = filteredRows.length <= pageSize;
    if (pageIndicator) pageIndicator.textContent = `${page} / ${pageCount}`;
    if (emptyState) emptyState.hidden = filteredRows.length > 0;
    const previous = container.querySelector('[data-page-prev]');
    const next = container.querySelector('[data-page-next]');
    if (previous) previous.disabled = page <= 1;
    if (next) next.disabled = page >= pageCount;
  }

  search?.addEventListener('input', () => { page = 1; render(); });
  filters.forEach((filter) => filter.addEventListener('change', () => { page = 1; render(); }));
  pageSizeControl?.addEventListener('change', () => { page = 1; render(); });
  container.querySelector('[data-page-prev]')?.addEventListener('click', () => { page = Math.max(1, page - 1); render(); });
  container.querySelector('[data-page-next]')?.addEventListener('click', () => { page += 1; render(); });
  render();
});