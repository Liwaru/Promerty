/**
 * Reports bytes sent by the browser. Completion still requires a successful
 * server response. An interrupted multipart POST cannot safely be replayed.
 */
export function uploadAgentMedia(url, body, csrf, onProgress, createRequest = () => new XMLHttpRequest()) {
    return new Promise((resolve, reject) => {
        const request = createRequest();
        request.open('POST', url);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-CSRF-TOKEN', csrf);

        request.upload.addEventListener('progress', event => {
            if (event.lengthComputable && event.total > 0) {
                onProgress(Math.min(100, Math.floor(event.loaded / event.total * 100)));
            }
        });
        request.upload.addEventListener('load', () => onProgress(100));

        const interrupted = () => reject(new Error(
            'Koneksi unggah terputus. Periksa daftar media sebelum mengirim ulang.'
        ));
        for (const event of ['error', 'abort', 'timeout']) request.addEventListener(event, interrupted);
        request.addEventListener('load', () => {
            let data;
            try { data = JSON.parse(request.responseText); } catch { /* HTML errors have no JSON body. */ }
            if (request.status === 201 && Number.isInteger(data?.data?.id) && data.data.id > 0) {
                resolve(data);
                return;
            }
            const message = request.status === 413
                ? 'Ukuran unggahan melampaui batas server.'
                : request.status === 419
                    ? 'Sesi berakhir. Masuk kembali dan periksa daftar media.'
                    : request.status >= 500 || request.status < 400
                        ? 'Hasil unggahan belum dapat dipastikan. Periksa daftar media.'
                        : data?.message ?? 'Media belum dapat diunggah.';
            const error = new Error(message);
            if (request.status === 422) error.errors = data?.errors;
            reject(error);
        });
        request.send(body);
    });
}
