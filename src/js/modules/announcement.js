function initAnnouncementDismiss() {
  var announcement = document.querySelector('[data-announcement]');
  if (!announcement) return;

  var closeButton = announcement.querySelector('[data-announcement-close]');
  var version = announcement.getAttribute('data-announcement-version') || 'actual';
  var storageKey = 'cp-announcement-dismissed:' + version;
  var hero = announcement.closest('.hero');

  function hideAnnouncement() {
    announcement.hidden = true;
    if (hero) hero.classList.remove('hero--has-announcement');
  }

  try {
    if (window.localStorage.getItem(storageKey) === '1') {
      hideAnnouncement();
      return;
    }
  } catch (error) {
    // El cierre sigue disponible durante la página actual sin almacenamiento.
  }

  if (!closeButton) return;

  closeButton.addEventListener('click', function() {
    hideAnnouncement();

    try {
      window.localStorage.setItem(storageKey, '1');
    } catch (error) {
      // El elemento ya quedó oculto para esta carga de página.
    }
  });
}
