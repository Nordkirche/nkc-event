/**
 * Month navigation of the event calendar without reloading the page.
 * The links work without JavaScript, too (data-calendar-uri: AJAX page type).
 */
(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || !event.target.closest) {
      return;
    }
    var link = event.target.closest('a[data-calendar-uri]');
    var calendar = link && link.closest('[data-nkc-calendar]');
    if (!calendar || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) {
      return;
    }
    event.preventDefault();
    calendar.setAttribute('aria-busy', 'true');

    fetch(link.dataset.calendarUri, {headers: {Accept: 'text/html'}})
      .then(function (response) {
        if (!response.ok) {
          throw new Error(response.statusText);
        }
        return response.text();
      })
      .then(function (html) {
        var next = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-nkc-calendar]');
        if (!next) {
          throw new Error('No calendar in the response');
        }
        calendar.replaceWith(next);
        var navigation = next.querySelector('a.nkc-calendar__nav--' + (link.classList.contains('nkc-calendar__nav--previous') ? 'previous' : 'next'));
        if (navigation) {
          navigation.focus();
        }
        if (window.history && history.replaceState) {
          history.replaceState(null, '', link.href);
        }
      })
      .catch(function () {
        window.location.href = link.href;
      });
  });
})();
