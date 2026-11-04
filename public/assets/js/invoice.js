(() => {
    'use strict';

    const button = document.querySelector('[data-print-invoice]');
    if (button instanceof HTMLButtonElement) {
        button.addEventListener('click', () => window.print());
    }
})();
