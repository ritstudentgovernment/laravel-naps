
/**
 * First we will load all of this project's JavaScript dependencies which
 * includes Vue and other libraries. It is a great starting point when
 * building robust, powerful web applications using Vue and Laravel.
 */

import API from "./classes/api/API"
import Vue from 'vue';
import ElementUI from 'element-ui';
import locale from 'element-ui/lib/locale/lang/en'

require('./bootstrap');

window.api = new API(window.axios);

Vue.use(ElementUI, { locale });

Vue.prototype.$http = window.axios;


/**
 * Next, we will create a fresh Vue application instance and attach it to
 * the page. Then, you may begin adding components to this application
 * or customize the JavaScript scaffolding to fit your unique needs.
 */

Vue.component('about', require('./components/pages/about/About.vue').default);
Vue.component('new-spot', require('./components/pages/home/NewSpot.vue').default);
Vue.component('filter-spots', require('./components/pages/home/FilterSpots.vue').default);
Vue.component('admin-nav', require('./components/pages/admin/Nav.vue').default);
Vue.component('admin-dashboard', require('./components/pages/admin/dashboard/Dashboard.vue').default);
Vue.component('admin-category-cards', require('./components/pages/admin/categories/CategoryCards.vue').default);
Vue.component('admin-category-editor', require('./components/pages/admin/categories/editor/CategoryEditor.vue').default);
Vue.component('admin-user-manager', require('./components/pages/admin/users/UserManager.vue').default);
Vue.component('admin-staff-manager', require('./components/pages/admin/users/StaffManager.vue').default);
Vue.component('admin-settings-bulk-spots', require('./components/pages/admin/settings/BulkSpotUpload').default);

export const EventBus = new Vue();

window.vue = new Vue({
    el: '#app',
    components: {
        ElementUI
    }
});

window.capitalize = (s) => {
    if (typeof s !== 'string') return '';
    return s.charAt(0).toUpperCase() + s.slice(1);
};

$.urlParam = (name, value = false) => {

    function getValue(name, url) {
        // Do some regex magic to get the value.
        let results = new RegExp('[\?&]' + name + '=([^&#]*)').exec(url);
        if (results){
            return decodeURI(results[1]) || 0;
        }
        return null;
    }

    let url = window.location.href,
        currentValue = getValue(name, url);

    // Check if we are just looking to get the value of a url parameter
    if (!value) { return currentValue; }

    // We are trying to update the value of a url parameter (or set one)
    let newUrl = "",
        splitUrl = [];

    // just return true if the current value matches the value we are tyring to set it to.
    if (value === currentValue) { return true; }

    // the value is different than what you want it to be, or not set at all, lets fix that.
    if (currentValue) {
        // The URL Parameter exists currently, we have to update it.
        let regex = new RegExp("([?;&])" + name + "[^&;]*[;&]?"),
            query = url.replace(regex, "$1").replace(/&$/, '');

        splitUrl = url.split('&');
        newUrl = query + (splitUrl[1] ? '&' : '') + (value ? name + "=" + value : '');
    } else {
        // The URL Parameter did not exist so we have to create it
        splitUrl = url.split('?');
        newUrl = url + (splitUrl[1] ? '&' : '?') + name + '=' + value;
    }

    // Set the current url to the updated one, return the value of the parameter
    window.history.pushState({}, '', newUrl);
    return getValue(name);

};

/**
 * In order to deal with axios headers not being initialized properly (auth and csrf headers missing)
 * early on in the execution trace of the app, specifically in the mounted or created methods of Vue
 * components, use this queue to defer processing until the app.js script is finished loading.
 * Each component should (if calling the API, and does so in its created method) implement API calls
 * in a 'setup' method, and add that method to the onLoadedQueue for deferred processing.
 */
if (window.coreApiLoaded) { window.coreApiLoaded(window.api); }

if (window.onLoadedQueue) {
    window.onLoadedQueue.forEach(methodToCall => {
        methodToCall();
    });
}