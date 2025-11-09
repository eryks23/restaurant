// assets/js/main.js (poprawiona wersja)

function throttle(fn, wait) {
  let last = 0;
  return function(...args) {
    const now = Date.now();
    if (now - last >= wait) {
      last = now;
      fn.apply(this, args);
    }
  };
}

function initMobileNav() {
  const navToggle = document.querySelector(".nav-toggle");
  const navMenu = document.querySelector(".nav-menu") || document.querySelector("nav");
  if (!navToggle || !navMenu) return;

  // upewnij się, że navToggle jest buttonem i ma aria-controls
  if (!navToggle.id) navToggle.id = 'nav-toggle';
  if (!navMenu.id) navMenu.id = 'nav-menu';
  navToggle.setAttribute('aria-controls', navMenu.id);

  function isOpen() {
    return navMenu.classList.contains('open');
  }

  function openMenu() {
    navMenu.classList.add("open");
    navToggle.setAttribute("aria-expanded", "true");
    document.documentElement.classList.add('nav-open'); // można użyć do blokady scrolla w CSS
    // focus management: przenieś fokus do pierwszego linku w menu
    const firstLink = navMenu.querySelector('a, button, [tabindex]:not([tabindex="-1"])');
    if (firstLink) firstLink.focus();
  }

  function closeMenu() {
    navMenu.classList.remove("open");
    navToggle.setAttribute("aria-expanded", "false");
    document.documentElement.classList.remove('nav-open');
    // przywróć fokus do toggle
    try { navToggle.focus(); } catch (e) {}
  }

  navToggle.addEventListener("click", function (e) {
    e.preventDefault();
    isOpen() ? closeMenu() : openMenu();
  });

  navToggle.addEventListener("keydown", function(e) {
    // spacja: ' ', 'Spacebar' (stare), lub e.code === 'Space'
    if (e.key === "Enter" || e.key === " " || e.key === "Spacebar" || e.code === 'Space') {
      e.preventDefault();
      isOpen() ? closeMenu() : openMenu();
    }
  });

  // kliknięcie poza menu -> zamknij tylko jeśli otwarte
  document.addEventListener("click", function(e) {
    if (!isOpen()) return;
    if (!navMenu.contains(e.target) && e.target !== navToggle) {
      closeMenu();
    }
  });

  // zamknij menu po escape
  navMenu.addEventListener("keydown", function(e) {
    if (e.key === "Escape" || e.key === "Esc") {
      closeMenu();
    }
  });

  // zamknij menu przy zmianie rozmiaru powyżej breakpointu (np. desktop)
  window.addEventListener('resize', throttle(function() {
    const desktopWidth = 900; // dostosuj do CSS breakpointów
    if (window.innerWidth >= desktopWidth && isOpen()) {
      closeMenu();
    }
  }, 200));

  // jeśli brak linków, to querySelectorAll zwróci NodeList pusty i forEach nic nie zrobi
  navMenu.querySelectorAll("a").forEach(function(link) {
    link.addEventListener("click", function() {
      // zamknij po kliknięciu linku (użyteczne na mobile)
      if (isOpen()) closeMenu();
    });
  });

  // optional: trap focus while menu open (prosty wariant)
  document.addEventListener('focusin', function(e) {
    if (!isOpen()) return;
    if (!navMenu.contains(e.target) && e.target !== navToggle) {
      // jeśli fokus wypadł poza menu — przenieś do pierwszego focusable
      const first = navMenu.querySelector('a, button, [tabindex]:not([tabindex="-1"])');
      if (first) first.focus();
    }
  });
}

function initSmoothScroll() {
  const links = document.querySelectorAll('a[href^="#"]');
  const header = document.querySelector("header");
  const offset = header ? header.offsetHeight : 0;

  links.forEach(function(link) {
    const href = link.getAttribute("href");
    if (!href || href === "#" ) return;

    link.addEventListener("click", function(e) {
      const targetId = href.slice(1);
      const targetEl = document.getElementById(targetId);
      if (targetEl) {
        e.preventDefault();
        window.scrollTo({
          top: targetEl.getBoundingClientRect().top + window.pageYOffset - offset,
          behavior: "smooth"
        });
      }
    });
  });
}

function initHeaderScroll() {
  const header = document.querySelector("header");
  if (!header) return;

  const onScroll = throttle(function() {
    if (window.pageYOffset > 20) {
      header.classList.add("scrolled");
    } else {
      header.classList.remove("scrolled");
    }
  }, 150);

  // passive for better performance
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();
}

function initLazyLoad() {
  const lazyImages = document.querySelectorAll("img[data-src], img[data-srcset], [data-bg]");
  if (!lazyImages || lazyImages.length === 0) return;

  function loadEl(el) {
    if (el.dataset.src) {
      el.src = el.dataset.src;
      el.removeAttribute('data-src');
      el.loading = 'lazy';
    }
    if (el.dataset.srcset) {
      el.srcset = el.dataset.srcset;
      el.removeAttribute('data-srcset');
    }
    if (el.dataset.bg) {
      el.style.backgroundImage = `url('${el.dataset.bg}')`;
      el.removeAttribute('data-bg');
    }
    // add loaded class for css transitions
    el.classList && el.classList.add('lazy-loaded');
  }

  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver(function(entries, obs) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          loadEl(entry.target);
          obs.unobserve(entry.target);
        }
      });
    }, { rootMargin: "0px 0px 100px 0px" });

    lazyImages.forEach(function(img) {
      observer.observe(img);
    });
  } else {
    // fallback: ładuj wszystkie
    lazyImages.forEach(function(el) {
      loadEl(el);
    });
  }
}

function initConsent() {
  const banner = document.querySelector(".cookie-banner");
  if (!banner) return;

  const acceptBtn = banner.querySelector(".accept");
  const rejectBtn = banner.querySelector(".reject");

  function hideBanner() {
    banner.style.display = "none";
  }

  function setConsent(value) {
    localStorage.setItem("cookieConsent", value ? "true" : "false");
    hideBanner();
    if (value === true && typeof window.gtag === "function") {
      try {
        window.gtag('consent', 'update', {
          'analytics_storage': 'granted'
        });
      } catch (e) {
        console.warn('gtag consent update failed', e);
      }
    } else {
      // jeśli odrzucono: postaraj się zablokować/nie ładować analytics
      if (typeof window.gtag === "function") {
        try {
          window.gtag('consent', 'update', {
            'analytics_storage': 'denied'
          });
        } catch (e) {}
      }
    }
  }

  // parsuj wartość localStorage (null/true/false)
  const stored = localStorage.getItem("cookieConsent");
  if (stored !== null) {
    // jeśli zapisane, nie pokazuj banner
    hideBanner();
  } else {
    if (acceptBtn) acceptBtn.addEventListener("click", function() { setConsent(true); });
    if (rejectBtn) rejectBtn.addEventListener("click", function() { setConsent(false); });
  }
}

/* public API */
window.App = {
  initMobileNav,
  initSmoothScroll,
  initHeaderScroll,
  initLazyLoad,
  initConsent
};

/* auto init */
document.addEventListener("DOMContentLoaded", function() {
  try {
    App.initMobileNav();
    App.initSmoothScroll();
    App.initHeaderScroll();
    App.initLazyLoad();
    App.initConsent();
  } catch (err) {
    console.error('Błąd inicjalizacji App:', err);
  }
});
document.addEventListener('DOMContentLoaded', function () {
  const payBtn = document.getElementById('payBtn'); // Twój <a>
  const bookingForm = document.getElementById('bookingForm');
  const gdpr = document.getElementById('gdpr');

  payBtn.addEventListener('click', function (e) {
    // Sprawdź czy wszystkie pola wymagane są wypełnione
    if (!bookingForm.checkValidity() || !gdpr.checked) {
      e.preventDefault(); // zablokuj przejście do linku

      // Wyświetl komunikaty
      if (!gdpr.checked) {
        gdpr.focus();
        alert('Zaznacz zgodę RODO, aby przejść do płatności.');
      } else {
        bookingForm.reportValidity(); // pokaż wbudowane komunikaty HTML5
      }
    }
    // jeśli wszystko ok, link się otworzy normalnie
  });
});
