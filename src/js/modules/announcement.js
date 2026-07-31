function initAnnouncementDismiss() {
  var announcement = document.querySelector('[data-announcement]');
  if (!announcement) return;

  var closeButton = announcement.querySelector('[data-announcement-close]');
  var progress = announcement.querySelector('[data-announcement-progress]');
  var announcementId = announcement.getAttribute('data-announcement-id') || 'actual';
  var announcementVersion = announcement.getAttribute('data-announcement-version') || 'sin-version';
  var duration = parseInt(announcement.getAttribute('data-duration') || '8000', 10);
  duration = Number.isFinite(duration) && duration > 0 ? duration : 8000;
  var storageKey = 'cp-announcement-hidden:' + announcementId + ':' + announcementVersion;
  var hero = announcement.closest('.hero');
  var hasLink = announcement.classList.contains('hero-announcement--has-link');
  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var remaining = duration;
  var previousTime = 0;
  var animationFrame = 0;
  var pointerPaused = false;
  var focusPaused = false;
  var dismissed = false;

  function setProgress() {
    if (!progress) return;
    var ratio = Math.max(0, Math.min(1, remaining / duration));
    if (reducedMotion) {
      ratio = Math.ceil(ratio * 4) / 4;
    }
    progress.style.transform = 'scaleX(' + ratio + ')';
  }

  function finishHiding() {
    announcement.hidden = true;
    if (hero) {
      hero.classList.remove(
        'hero--has-announcement',
        'hero--announcement-has-link',
        'hero--announcement-without-link'
      );
    }
  }

  function hideAnnouncement(storeDismissal) {
    if (dismissed) return;
    dismissed = true;
    if (animationFrame) {
      window.cancelAnimationFrame(animationFrame);
      animationFrame = 0;
    }
    if (storeDismissal) {
      try {
        window.sessionStorage.setItem(storageKey, '1');
      } catch (error) {
        // El elemento se oculta aunque el almacenamiento no esté disponible.
      }
    }
    announcement.classList.add('is-closing');
    if (reducedMotion) {
      finishHiding();
      return;
    }
    announcement.addEventListener('transitionend', finishHiding, { once: true });
    window.setTimeout(finishHiding, 240);
  }

  function isPaused() {
    return pointerPaused || focusPaused;
  }

  function tick(now) {
    if (dismissed) return;
    if (!previousTime) previousTime = now;
    if (!isPaused()) {
      remaining = Math.max(0, remaining - (now - previousTime));
      setProgress();
      if (remaining <= 0) {
        hideAnnouncement(true);
        return;
      }
    }
    previousTime = now;
    animationFrame = window.requestAnimationFrame(tick);
  }

  try {
    if (window.sessionStorage.getItem(storageKey) === '1') {
      dismissed = true;
      finishHiding();
      return;
    }
  } catch (error) {
    // El cierre sigue disponible durante la página actual sin almacenamiento.
  }

  announcement.addEventListener('mouseenter', function() {
    pointerPaused = true;
  });
  announcement.addEventListener('mouseleave', function() {
    pointerPaused = false;
    previousTime = performance.now();
  });
  announcement.addEventListener('focusin', function() {
    focusPaused = true;
  });
  announcement.addEventListener('focusout', function(event) {
    if (!announcement.contains(event.relatedTarget)) {
      focusPaused = false;
      previousTime = performance.now();
    }
  });

  if (closeButton) {
    closeButton.addEventListener('click', function() {
      hideAnnouncement(true);
    });
  }

  setProgress();
  if (hero) {
    hero.classList.add('hero--has-announcement');
    hero.classList.add(hasLink ? 'hero--announcement-has-link' : 'hero--announcement-without-link');
  }
  announcement.hidden = false;
  animationFrame = window.requestAnimationFrame(tick);
}
