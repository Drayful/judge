/**
 * Laravel default frontend bootstrap.
 *
 * Breeze's Blade stack expects this file to exist. Keep it lightweight:
 * we only wire up axios with CSRF support out of the box.
 */
import axios from 'axios';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
  window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

