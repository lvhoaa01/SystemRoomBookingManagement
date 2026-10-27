document.addEventListener('DOMContentLoaded', () => {
    const appShell = document.querySelector('[data-app-shell]');
    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.addEventListener('click', () => appShell?.classList.toggle('is-sidebar-open'));
    });

    document.querySelectorAll('[data-toggle-target]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const target = document.getElementById(trigger.dataset.toggleTarget);
            if (!target) return;
            target.hidden = !target.hidden;
            if (!target.hidden) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    const browser = document.querySelector('[data-property-browser]');
    if (browser) {
        const cards = [...browser.querySelectorAll('[data-property-card]')];
        const search = browser.querySelector('[data-property-search]');
        const filters = [...browser.querySelectorAll('[data-property-filter]')];
        const statusSelect = browser.querySelector('[data-property-status-select]');
        const noResults = browser.querySelector('[data-property-no-results]');
        const visibleCount = browser.querySelector('[data-visible-count]');
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
            if (visibleCount) visibleCount.textContent = visible;
        };

        filters.forEach((filter) => filter.addEventListener('click', () => {
            activeStatus = filter.dataset.propertyFilter;
            filters.forEach((item) => item.classList.toggle('is-active', item === filter));
            refresh();
        }));
        statusSelect?.addEventListener('change', () => {
            activeStatus = statusSelect.value;
            refresh();
        });
        search?.addEventListener('input', refresh);
    }

    const uploader = document.querySelector('[data-image-uploader]');
    const jsonField = document.querySelector('#images-json');
    const gallery = document.querySelector('[data-image-gallery]');

    const readImages = () => {
        try {
            const images = JSON.parse(jsonField?.value || '[]');
            return Array.isArray(images) ? images : [];
        } catch {
            return [];
        }
    };

    const writeImages = (images) => {
        images.forEach((image, index) => { image.sort = index + 1; });
        if (jsonField) jsonField.value = JSON.stringify(images);
        renderGallery();
    };

    const renderGallery = () => {
        if (!gallery) return;
        const images = readImages();
        gallery.replaceChildren();
        if (images.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'image-gallery__empty';
            const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            icon.classList.add('app-icon');
            const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
            use.setAttribute('href', '#icon-image');
            icon.append(use);
            const title = document.createElement('strong');
            title.textContent = 'Chưa có ảnh nào';
            const help = document.createElement('small');
            help.textContent = 'Thêm ảnh thật của chỗ nghỉ để hoàn thiện hồ sơ.';
            empty.append(icon, title, help);
            gallery.append(empty);
            return;
        }
        images.forEach((image, index) => {
            const item = document.createElement('article');
            item.className = 'image-tile';
            const img = document.createElement('img');
            img.src = `${gallery.dataset.baseUrl}${image.path}`;
            img.alt = image.alt;
            const meta = document.createElement('div');
            meta.className = 'image-tile__meta';
            const label = document.createElement('span');
            label.textContent = image.cover ? 'Ảnh bìa' : `Ảnh ${index + 1}`;
            const actions = document.createElement('span');
            if (!image.cover) {
                const coverButton = document.createElement('button');
                coverButton.type = 'button';
                coverButton.textContent = 'Đặt làm bìa';
                coverButton.addEventListener('click', () => writeImages(images.map((entry, entryIndex) => ({ ...entry, cover: entryIndex === index }))));
                actions.append(coverButton);
            }
            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = 'Xóa';
            removeButton.addEventListener('click', () => {
                const remaining = images.filter((_, entryIndex) => entryIndex !== index);
                if (remaining.length > 0 && !remaining.some((entry) => entry.cover)) remaining[0].cover = true;
                writeImages(remaining);
            });
            actions.append(removeButton);
            meta.append(label, actions);
            item.append(img, meta);
            gallery.append(item);
        });
    };

    renderGallery();

    document.querySelectorAll('[data-character-field]').forEach((field) => {
        const counter = field.parentElement.querySelector('[data-character-count]');
        const update = () => { if (counter) counter.textContent = field.value.length; };
        field.addEventListener('input', update);
        update();
    });

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
            const images = readImages();
            images.push({ path: payload.data.path, alt: alt.value.trim() || 'Ảnh cơ sở lưu trú', sort: images.length + 1, cover: images.length === 0 });
            writeImages(images);
            file.value = '';
            status.textContent = 'Đã tải ảnh và thêm vào danh sách. Hãy bấm Lưu thay đổi.';
        } catch (error) {
            status.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
});
