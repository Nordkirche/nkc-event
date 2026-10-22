/**
 * Date presets of the event search ("Diese Woche" etc.): fill "from"/"to" and search.
 * The entry without a range (data-value="") resets the date filter.
 * Theme scripts can take over by calling event.preventDefault() first.
 */
(function () {
  'use strict';

  var isPresetList = function (list) {
    return list !== null && list.querySelector('[data-value-min], [data-value-max]') !== null;
  };

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || !event.target.closest) {
      return;
    }
    var item = event.target.closest('li[data-value-min], li[data-value-max], li[data-value=""]');
    var form = item && item.closest('form');
    if (!form || !isPresetList(item.parentElement)) {
      return;
    }

    var dateFrom = form.querySelector('input[name$="[dateFrom]"]');
    var dateTo = form.querySelector('input[name$="[dateTo]"]');
    if (!dateFrom || !dateTo) {
      return;
    }

    event.preventDefault();
    dateFrom.value = item.dataset.valueMin || '';
    dateTo.value = item.dataset.valueMax || '';

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });
})();
