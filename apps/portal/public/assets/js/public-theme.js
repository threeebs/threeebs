(() => {
  let saved = null;
  try { saved = localStorage.getItem('threeebs-theme'); } catch { /* Storage can be unavailable. */ }
  const preferred = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  document.documentElement.dataset.theme = saved === 'light' || saved === 'dark' ? saved : preferred;
})();
