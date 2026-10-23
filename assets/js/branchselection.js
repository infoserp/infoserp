/**
 * INFOS ERP — Branch selection (company cards, branch rows, search)
 */
(function () {
    'use strict';

    var form = document.getElementById('branch-select-form');
    if (!form) {
        return;
    }

    var rows = form.querySelectorAll('.branch-row');
    var cards = form.querySelectorAll('.company-card');
    var searchInput = document.getElementById('branch-search-input');
    var searchEmpty = document.getElementById('branch-search-empty');
    var submitted = false;

    function applySearch() {
        var term = searchInput.value.trim().toLowerCase();
        var visibleCards = 0;

        for (var c = 0; c < cards.length; c++) {
            var card = cards[c];
            var companyMatch = term === '' || (card.getAttribute('data-search') || '').indexOf(term) !== -1;
            var cardRows = card.querySelectorAll('.branch-row');
            var visibleRows = 0;

            for (var r = 0; r < cardRows.length; r++) {
                var rowMatch = companyMatch || (cardRows[r].getAttribute('data-search') || '').indexOf(term) !== -1;
                cardRows[r].hidden = !rowMatch;
                if (rowMatch) {
                    visibleRows++;
                }
            }

            card.hidden = visibleRows === 0;
            if (visibleRows > 0) {
                visibleCards++;
            }
        }

        if (searchEmpty) {
            searchEmpty.hidden = visibleCards > 0;
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applySearch);
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
    }

    form.addEventListener('submit', function (e) {
        var clicked = e.submitter;

        if (submitted) {
            e.preventDefault();
            return;
        }
        submitted = true;

        if (!clicked) {
            return;
        }

        clicked.classList.add('is-loading');
        var label = clicked.querySelector('.branch-row__action-label');
        if (label) {
            label.textContent = 'Opening...';
        }

        // Defer disabling so the clicked button's name/value is still submitted.
        setTimeout(function () {
            for (var i = 0; i < rows.length; i++) {
                rows[i].disabled = true;
            }
        }, 0);
    });
})();
