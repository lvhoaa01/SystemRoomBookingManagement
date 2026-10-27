document.addEventListener('DOMContentLoaded', () => {
    const browser = document.querySelector('[data-property-browser]');
    if (browser) {
        const cards = [...browser.querySelectorAll('[data-property-card]')];
        const search = browser.querySelector('[data-property-search]');
        const filters = [...browser.querySelectorAll('[data-property-filter]')];
        const noResults = browser.querySelector('[data-property-no-results]');
        let activeStatus = 'ALL';

        const refresh = () => {
            const term = (search?.value || '').trim().toLocaleLowerCase('vi');
            let visible = 0;
            cards.forEach((card) => {
                const statusMatches = activeStatus === 'ALL' || card.dataset.status === activeStatus;
                const searchMatches = !term || card.dataset.search.includes(term);
                card.hidden = !(statusMatches && searchMatches);
                if (!card.hidden) visible += 1;
            });
            if (noResults) noResults.hidden = visible !== 0;
        };

        filters.forEach((filter) => filter.addEventListener('click', () => {
            activeStatus = filter.dataset.propertyFilter;
            filters.forEach((item) => item.classList.toggle('is-active', item === filter));
            refresh();
        }));
        search?.addEventListener('input', refresh);
    }

    const uploader = document.querySelector('[data-image-uploader]');
    const jsonField = document.querySelector('#images-json');
    if (!uploader || !jsonField) return;

    const button = uploader.querySelector('[data-image-submit]');
    const file = uploader.querySelector('[data-image-file]');
    const alt = uploader.querySelector('[data-image-alt]');
    const status = uploader.querySelector('[data-image-status]');

    button.addEventListener('click', async () => {
        if (!file.files[0]) {
            status.textContent = 'Hãy chọn một ảnh.';
            return;
        }
        button.disabled = true;
        status.textContent = 'Đang tải ảnh…';
        const body = new FormData();
        body.append('_token', uploader.dataset.token);
        body.append('image', file.files[0]);
        try {
            const response = await fetch(uploader.dataset.url, { method: 'POST', body, headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error?.message || 'Không thể tải ảnh.');
            const images = JSON.parse(jsonField.value || '[]');
            images.push({ path: payload.data.path, alt: alt.value.trim() || 'Ảnh cơ sở lưu trú', sort: images.length + 1, cover: images.length === 0 });
            jsonField.value = JSON.stringify(images);
            file.value = '';
            status.textContent = 'Đã tải ảnh và thêm vào danh sách. Hãy bấm Lưu thay đổi.';
        } catch (error) {
            status.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
});
