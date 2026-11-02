document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-auto-submit]').forEach((field) => {
        field.addEventListener('change', () => field.form?.requestSubmit());
    });

    const shell = document.querySelector('[data-app-shell]');
    const toggles = [...document.querySelectorAll('[data-sidebar-toggle]')];
    const setSidebar = (open) => {
        shell?.classList.toggle('is-sidebar-open', open);
        toggles.forEach((toggle) => toggle.setAttribute('aria-expanded', open ? 'true' : 'false'));
    };
    toggles.forEach((toggle) => toggle.addEventListener('click', () => {
        setSidebar(!shell?.classList.contains('is-sidebar-open'));
    }));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && shell?.classList.contains('is-sidebar-open')) {
            setSidebar(false);
            document.querySelector('.app-topbar__menu')?.focus();
        }
    });

    const resetForm = (form) => {
        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');
        form.querySelectorAll('[data-submit-locked]').forEach((button) => {
            button.disabled = false;
            button.textContent = button.dataset.originalLabel || button.textContent;
            delete button.dataset.submitLocked;
            delete button.dataset.originalLabel;
        });
    };
    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.method.toLowerCase() === 'get') return;
            if (form.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }
            form.dataset.submitting = 'true';
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
                button.dataset.submitLocked = 'true';
                button.dataset.originalLabel = button.textContent || button.value;
                button.disabled = true;
                if (button instanceof HTMLButtonElement) button.textContent = 'Đang xử lý…';
                if (button instanceof HTMLInputElement) button.value = 'Đang xử lý…';
            });
        });
        window.addEventListener('pageshow', () => resetForm(form));
    });
});
