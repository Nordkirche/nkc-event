/**
 * Suggestions of the event search: titles of matching events in a <datalist>
 *
 *   <input data-autocomplete-uri="..." data-autocomplete-param="tx_nkcevent_list[q]" list="suggestions">
 *   <datalist id="suggestions"></datalist>
 */
(function () {
  'use strict';

  var MIN_LENGTH = 3;
  var DELAY = 250;

  var init = function (input) {
    var list = input.list;
    var timer = null;
    var controller = null;
    var lastTerm = '';

    if (!list) {
      return;
    }

    input.addEventListener('input', function () {
      var term = input.value.trim();
      clearTimeout(timer);
      if (term.length < MIN_LENGTH || term === lastTerm) {
        return;
      }
      timer = setTimeout(function () {
        lastTerm = term;
        if (controller) {
          controller.abort();
        }
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        var uri = input.dataset.autocompleteUri;
        uri += (uri.indexOf('?') === -1 ? '?' : '&') + encodeURIComponent(input.dataset.autocompleteParam) + '=' + encodeURIComponent(term);
        fetch(uri, {headers: {Accept: 'application/json'}, signal: controller ? controller.signal : undefined})
          .then(function (response) {
            return response.ok ? response.json() : [];
          })
          .then(function (titles) {
            list.replaceChildren();
            (Array.isArray(titles) ? titles : []).forEach(function (title) {
              var option = document.createElement('option');
              option.value = title;
              list.appendChild(option);
            });
          })
          .catch(function () {
            // Aborted or offline: no suggestions
          });
      }, DELAY);
    });
  };

  var start = function () {
    document.querySelectorAll('input[data-autocomplete-uri][data-autocomplete-param]').forEach(init);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
