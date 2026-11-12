(() => {
  const shell = document.querySelector('.evaluator-content-page');
  if (!shell) return;

  const menuToggle = shell.querySelector('.mobile-menu-toggle');
  const backdrop = shell.querySelector('.sidebar-backdrop');
  const sidebar = shell.querySelector('.evaluator-sidebar');
  const closeMenu = (restoreFocus = false) => {
    shell.classList.remove('nav-open');
    if (menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
    if (restoreFocus && menuToggle) menuToggle.focus();
  };
  if (menuToggle && backdrop && sidebar) {
    menuToggle.addEventListener('click', () => {
      const isOpen = shell.classList.toggle('nav-open');
      menuToggle.setAttribute('aria-expanded', String(isOpen));
      if (isOpen) {
        const firstLink = sidebar.querySelector('a');
        if (firstLink) firstLink.focus();
      } else {
        menuToggle.focus();
      }
    });
    backdrop.addEventListener('click', () => closeMenu(true));
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && shell.classList.contains('nav-open')) closeMenu(true);
    });
    sidebar.addEventListener('click', (event) => {
      if (event.target.closest('a')) closeMenu();
    });
  }

  const list = shell.querySelector('[data-evaluator-list]');
  if (list) {
    const body = list.querySelector('tbody');
    const allRows = Array.from(body.querySelectorAll('tr[data-record]'));
    const controls = Array.from(shell.querySelectorAll('[data-filter]'));
    const search = shell.querySelector('[data-filter="search"]');
    const sortControl = shell.querySelector('[data-filter="sort"]');
    const pageSizeControl = shell.querySelector('[data-page-size]');
    const pagination = shell.querySelector('[data-pagination]');
    const rangeLabel = shell.querySelector('[data-range-label]');
    const noResults = body.querySelector('[data-no-results]');
    const emptyState = shell.querySelector('[data-empty-state]');
    let page = 1;
    let pageSize = Number(pageSizeControl ? pageSizeControl.value : 10) || 10;

    const normalized = (value) => String(value || '').trim().toLocaleLowerCase();
    const valuesFor = (key) => Array.from(new Set(allRows.map((row) => row.dataset[key] || '').filter(Boolean)))
      .sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));
    shell.querySelectorAll('select[data-populate]').forEach((select) => {
      const current = select.value;
      valuesFor(select.dataset.populate).forEach((value) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value.replace(/_/g, ' ').replace(/\b[a-z]/g, (letter) => letter.toUpperCase());
        select.append(option);
      });
      if (current) select.value = current;
    });

    const isFiltered = () => controls.some((control) => control.value !== '');
    const matches = (row) => {
      const searchTerm = normalized(search ? search.value : '');
      if (searchTerm && !normalized(row.dataset.search).includes(searchTerm)) return false;
      for (const control of controls) {
        const value = control.value;
        if (!value || control === search || control === sortControl) continue;
        const key = control.dataset.filter;
        if (key === 'date-from' && row.dataset.date && row.dataset.date < value) return false;
        else if (key === 'date-to' && row.dataset.date && row.dataset.date > value) return false;
        else if (key === 'score-min' && Number(row.dataset.score) < Number(value)) return false;
        else if (key === 'score-max' && Number(row.dataset.score) > Number(value)) return false;
        else if (key === 'date-from' && !row.dataset.date) return false;
        else if (key === 'date-to' && !row.dataset.date) return false;
        else if (key !== 'date-from' && key !== 'date-to' && key !== 'score-min' && key !== 'score-max' &&
          normalized(row.dataset[key.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())]) !== normalized(value)) return false;
      }
      return true;
    };

    const compareRows = (a, b, order) => {
      const descending = order.endsWith('-desc') || order === 'date-newest' || order === 'score-high';
      let key = order.replace(/-(asc|desc)$/, '');
      if (order === 'date-newest' || order === 'date-oldest') key = 'date';
      if (order === 'score-high' || order === 'score-low') key = 'score';
      const left = key === 'score' ? Number(a.dataset[key]) : normalized(a.dataset[key]);
      const right = key === 'score' ? Number(b.dataset[key]) : normalized(b.dataset[key]);
      const result = left < right ? -1 : left > right ? 1 : 0;
      return descending ? -result : result;
    };

    const makePageButton = (label, target, current, ariaLabel) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      button.setAttribute('aria-label', ariaLabel);
      if (target === current) button.setAttribute('aria-current', 'page');
      button.disabled = target === current;
      button.addEventListener('click', () => {
        page = target;
        render();
      });
      return button;
    };

    const renderPagination = (pageCount) => {
      if (!pagination) return;
      pagination.replaceChildren();
      const previous = document.createElement('button');
      previous.type = 'button';
      previous.textContent = '‹';
      previous.setAttribute('aria-label', 'Previous page');
      previous.disabled = page <= 1;
      previous.addEventListener('click', () => { page -= 1; render(); });
      pagination.append(previous);
      const start = Math.max(1, Math.min(page - 1, pageCount - 2));
      const end = Math.min(pageCount, Math.max(3, page + 1));
      for (let number = start; number <= end; number += 1) {
        pagination.append(makePageButton(String(number), number, page, `Page ${number}`));
      }
      const next = document.createElement('button');
      next.type = 'button';
      next.textContent = '›';
      next.setAttribute('aria-label', 'Next page');
      next.disabled = page >= pageCount;
      next.addEventListener('click', () => { page += 1; render(); });
      pagination.append(next);
    };

    const render = () => {
      const filtered = allRows.filter(matches);
      if (sortControl && sortControl.value) filtered.sort((a, b) => compareRows(a, b, sortControl.value));
      filtered.forEach((row) => body.insertBefore(row, noResults));
      const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
      page = Math.min(page, pageCount);
      const startIndex = (page - 1) * pageSize;
      const visible = new Set(filtered.slice(startIndex, startIndex + pageSize));
      allRows.forEach((row) => { row.hidden = !visible.has(row); });
      if (noResults) noResults.hidden = filtered.length !== 0 || allRows.length === 0;
      if (emptyState) emptyState.hidden = allRows.length !== 0;
      if (rangeLabel) {
        const first = filtered.length ? startIndex + 1 : 0;
        const last = Math.min(startIndex + pageSize, filtered.length);
        rangeLabel.textContent = filtered.length
          ? `Showing ${first}–${last} of ${filtered.length}${filtered.length !== allRows.length ? ` (filtered from ${allRows.length})` : ''}`
          : (allRows.length && isFiltered() ? 'No matching records' : `Showing 0 of ${allRows.length}`);
      }
      renderPagination(pageCount);
    };

    controls.forEach((control) => {
      control.addEventListener('input', () => { page = 1; render(); });
      control.addEventListener('change', () => { page = 1; render(); });
    });
    if (pageSizeControl) {
      pageSizeControl.addEventListener('change', () => {
        pageSize = Number(pageSizeControl.value) || 10;
        page = 1;
        render();
      });
    }
    shell.querySelector('[data-reset-filters]')?.addEventListener('click', () => {
      controls.forEach((control) => { control.value = ''; });
      page = 1;
      render();
    });
    render();
  }

  const profileForm = shell.querySelector('[data-profile-form]');
  if (profileForm) {
    const nameInput = profileForm.querySelector('[name="full_name"]');
    const editButton = profileForm.querySelector('[data-profile-edit]');
    const cancelButton = profileForm.querySelector('[data-profile-cancel]');
    const saveButton = profileForm.querySelector('[data-profile-save]');
    const initialValue = nameInput ? nameInput.value : '';
    const initiallyEditing = profileForm.dataset.editing === 'true';
    const setEditing = (editing) => {
      if (!nameInput) return;
      nameInput.readOnly = !editing;
      if (editButton) editButton.hidden = editing;
      if (cancelButton) cancelButton.hidden = !editing;
      if (saveButton) saveButton.hidden = !editing;
      if (editing) nameInput.focus();
    };
    if (editButton) editButton.addEventListener('click', () => setEditing(true));
    if (cancelButton) cancelButton.addEventListener('click', () => {
      nameInput.value = initialValue;
      setEditing(false);
    });
    profileForm.addEventListener('submit', (event) => {
      const value = nameInput ? nameInput.value.trim() : '';
      if (!value || value.length > 200) {
        event.preventDefault();
        if (nameInput) {
          nameInput.setCustomValidity('Enter your full name using no more than 200 characters.');
          nameInput.reportValidity();
          nameInput.addEventListener('input', () => nameInput.setCustomValidity(''), { once: true });
        }
      } else if (nameInput) {
        nameInput.value = value;
      }
    });
    setEditing(initiallyEditing);
  }
})();
