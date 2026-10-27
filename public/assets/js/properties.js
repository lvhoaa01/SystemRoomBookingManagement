document.addEventListener('DOMContentLoaded', () => {
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
