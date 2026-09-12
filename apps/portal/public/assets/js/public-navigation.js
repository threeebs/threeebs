(() => {
  const root = document.documentElement;
  const body = document.body;
  const nav = document.querySelector('[data-public-nav]');
  const more = document.querySelector('[data-more-menu]');
  const header = document.querySelector('.marketing-header');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  const syncThemeButtons = () => {
    const light = root.dataset.theme === 'light';
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
      button.setAttribute('aria-label', light ? 'Ativar tema escuro' : 'Ativar tema claro');
      button.setAttribute('aria-pressed', String(light));
    });
  };

  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('threeebs-theme', root.dataset.theme); } catch { /* Storage can be unavailable. */ }
      syncThemeButtons();
    });
  });
  syncThemeButtons();

  const syncHeader = () => header?.classList.toggle('is-scrolled', window.scrollY > 12);
  syncHeader();
  window.addEventListener('scroll', syncHeader, { passive: true });

  body.classList.add('page-enter');
  if (!reducedMotion.matches) {
    body.classList.add('motion-ready');
    const revealSelectors = [
      '.marketing-hero__copy', '.system-scene', '.section-heading', '.journey-line',
      '.start-small-grid', '.evolution-section', '.ecosystem-grid', '.marketing-cta',
      '.internal-hero > *', '.plans-section .section-heading', '.plan-card',
      '.marketing-interest > *', '.about-story article', '.about-quote',
      '.login-story', '.login-panel'
    ];
    const reveals = document.querySelectorAll(revealSelectors.join(','));
    reveals.forEach((element, index) => {
      element.classList.add('reveal-item');
      element.style.setProperty('--reveal-delay', `${Math.min(index % 3, 2) * 70}ms`);
    });
    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        });
      }, { threshold: 0.08, rootMargin: '0px 0px -6% 0px' });
      reveals.forEach((element) => observer.observe(element));
    } else {
      reveals.forEach((element) => element.classList.add('is-visible'));
    }
  }

  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank' || link.hasAttribute('download')) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin || url.pathname === window.location.pathname || link.hash || reducedMotion.matches || 'onpagereveal' in window) return;
    event.preventDefault();
    body.classList.add('is-leaving');
    window.setTimeout(() => { window.location.href = url.href; }, 150);
  });

  if (!nav) return;

  const mobileViewport = window.matchMedia('(max-width: 48rem)');
  const navSummary = nav.querySelector(':scope > summary');

  const exposeNavState = () => {
    navSummary?.setAttribute('aria-label', nav.open ? 'Fechar navegação' : 'Abrir navegação');
  };

  const syncViewport = () => {
    nav.open = !mobileViewport.matches;
    if (more) more.open = false;
    exposeNavState();
  };

  const closeMenus = () => {
    if (more) more.open = false;
    if (mobileViewport.matches) nav.open = false;
    else nav.open = true;
  };

  nav.addEventListener('toggle', exposeNavState);
  mobileViewport.addEventListener?.('change', syncViewport);
  syncViewport();

  document.addEventListener('click', (event) => {
    if (!nav.contains(event.target)) closeMenus();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (more?.open) {
      more.open = false;
      more.querySelector('summary')?.focus();
      return;
    }
    if (mobileViewport.matches && nav.open) {
      nav.open = false;
      navSummary?.focus();
    }
  });

  nav.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      if (mobileViewport.matches) closeMenus();
    });
  });
})();
