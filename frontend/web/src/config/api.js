import axios from "axios";

export const api = axios.create({
    baseURL: '/api',

    withCredentials: true,
    withXSRFToken: true,

    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * Menginisialisasi cookie CSRF Laravel Sanctum.
 *
 * Panggil sebelum mengirim request login.
 * Endpoint ini berada di /sanctum, bukan /api/sanctum.
 */
export async function getCsrfCookie() {
    return axios.get('/sanctum/csrf-cookie', {
        withCredentials: true,
        withXSRFToken: true,

        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
}

export default api;