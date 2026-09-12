(() => {
  'use strict';

  const root = document.documentElement;
  const shell = document.querySelector('#app-shell');
  const openButton = document.querySelector('[data-sidebar-open]');
  const closeButtons = document.querySelectorAll('[data-sidebar-close]');
  const collapseButton = document.querySelector('[data-sidebar-collapse]');
  const scrim = document.querySelector('.sidebar-scrim');
  const themeButton = document.querySelector('[data-theme-toggle]');

  const updateThemeLabel = () => {
    if (!themeButton) return;
    const target = root.dataset.theme === 'light' ? 'escuro' : 'claro';
    themeButton.setAttribute('aria-label', `Alterar para tema ${target}`);
    themeButton.setAttribute('title', `Alterar para tema ${target}`);
  };

  try {
    root.dataset.theme = localStorage.getItem('threeebs-theme') === 'light' ? 'light' : 'dark';
    if (shell && localStorage.getItem('threeebs-sidebar') === 'collapsed') {
      shell.classList.add('is-collapsed');
      collapseButton?.setAttribute('aria-expanded', 'false');
    }
  } catch (_) {
    root.dataset.theme = 'dark';
  }
  updateThemeLabel();

  const setMobileSidebar = (open) => {
    document.body.classList.toggle('sidebar-open', open);
    openButton?.setAttribute('aria-expanded', String(open));
    if (scrim) scrim.hidden = !open;
  };

  openButton?.addEventListener('click', () => setMobileSidebar(true));
  closeButtons.forEach((button) => button.addEventListener('click', () => setMobileSidebar(false)));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setMobileSidebar(false);
  });

  collapseButton?.addEventListener('click', () => {
    if (!shell) return;
    const collapsed = shell.classList.toggle('is-collapsed');
    collapseButton.setAttribute('aria-expanded', String(!collapsed));
    try { localStorage.setItem('threeebs-sidebar', collapsed ? 'collapsed' : 'expanded'); } catch (_) {}
  });

  themeButton?.addEventListener('click', () => {
    const theme = root.dataset.theme === 'light' ? 'dark' : 'light';
    root.dataset.theme = theme;
    updateThemeLabel();
    try { localStorage.setItem('threeebs-theme', theme); } catch (_) {}
  });
})();
