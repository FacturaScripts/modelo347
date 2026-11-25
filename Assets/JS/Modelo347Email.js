/**
 * This file is part of Modelo347 plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * Selección de destinatarios de la carta del modelo 347:
 *   - doble lista incluidos/seleccionados,
 *   - filtro de búsqueda y envío por fetch sin recargar la pantalla.
 *
 * @author Jose Antonio Cuello Principal <yopli2000@gmail.com>
 */
window.Modelo347 = window.Modelo347 || {};

Modelo347.Email = {
    // ===== PUBLIC =====

    /**
     * Arranca el módulo si existe su contenedor en la página.
     */
    init() {
        this._container = document.getElementById('m347-email');
        if (!this._container) {
            return;
        }

        this._form = document.getElementById('m347-email-form');
        this._left = document.getElementById('m347-left');
        this._right = document.getElementById('m347-right');
        this._search = document.getElementById('m347-search');
        this._sendButton = document.getElementById('m347-send');
        this._items = JSON.parse(document.getElementById('m347-recipients').textContent).map(row => ({
            ...row,
            marked: false,
            searchFields: [row.cifnif, row.name, row.email].map(value => this._normalize(value)),
            selected: false,
            sent: false
        }));

        this._bindEvents();
        this.render();
    },

    /**
     * Pinta las dos listas, los contadores y el estado del botón de envío.
     */
    render() {
        const terms = this._parseTerms(this._search.value);
        const left = this._items.filter(item => !item.selected && this._matches(item, terms));
        const right = this._items.filter(item => item.selected);

        this._renderList(this._left, left);
        this._renderList(this._right, right);
        document.getElementById('m347-left-count').textContent = left.length;
        document.getElementById('m347-right-count').textContent = right.length;
        document.getElementById('m347-send-count').textContent = right.length;
        this._sendButton.disabled = this._sending || right.length === 0;
    },

    // ===== PRIVATE =====

    /** @type {HTMLElement|null} Contenedor principal del módulo. */
    _container: null,

    /** @type {HTMLFormElement|null} Formulario con los filtros y el token. */
    _form: null,

    /** @type {Array} Destinatarios con su estado (marcado, seleccionado y enviado). */
    _items: [],

    /** @type {HTMLElement|null} Lista de incluidos. */
    _left: null,

    /** @type {HTMLElement|null} Lista de seleccionados. */
    _right: null,

    /** @type {HTMLInputElement|null} Campo de búsqueda. */
    _search: null,

    /** @type {HTMLButtonElement|null} Botón de envío. */
    _sendButton: null,

    /** @type {boolean} Indica si hay un envío en curso. */
    _sending: false,

    /**
     * Asocia los eventos de botones, listas y buscador.
     */
    _bindEvents() {
        document.getElementById('m347-add').addEventListener('click', () => this._move(true, false));
        document.getElementById('m347-add-all').addEventListener('click', () => this._move(true, true));
        document.getElementById('m347-remove').addEventListener('click', () => this._move(false, false));
        document.getElementById('m347-remove-all').addEventListener('click', () => this._move(false, true));
        document.getElementById('m347-search-clear').addEventListener('click', () => {
            this._search.value = '';
            this.render();
            this._search.focus();
        });
        this._search.addEventListener('input', () => this.render());
        this._sendButton.addEventListener('click', () => this._send());

        [this._left, this._right].forEach(list => {
            list.addEventListener('click', event => this._onItemEvent(event, false));
            list.addEventListener('dblclick', event => this._onItemEvent(event, true));
        });
    },

    /**
     * Busca el destinatario por su código.
     *
     * @param {string} code
     * @returns {Object|undefined}
     */
    _findItem(code) {
        return this._items.find(item => item.code === code);
    },

    /**
     * Indica si el destinatario cumple todos los términos en alguno de sus campos.
     *
     * @param {Object} item
     * @param {Array} terms
     * @returns {boolean}
     */
    _matches(item, terms) {
        return terms.every(term => item.searchFields.some(field => {
            switch (term.mode) {
                case 'start':
                    return field.startsWith(term.value);
                case 'end':
                    return field.endsWith(term.value);
                default:
                    return field.includes(term.value);
            }
        }));
    },

    /**
     * Mueve los marcados (o todos) entre listas. Hacia seleccionados solo mueve los visibles por el filtro.
     *
     * @param {boolean} toSelected
     * @param {boolean} all
     */
    _move(toSelected, all) {
        const terms = this._parseTerms(this._search.value);
        this._items.forEach(item => {
            const source = toSelected ? !item.selected && this._matches(item, terms) : item.selected;
            if (source && (all || item.marked)) {
                item.selected = toSelected;
                item.marked = false;
            }
        });
        this.render();
    },

    /**
     * Normaliza un texto para comparar sin mayúsculas ni acentos.
     *
     * @param {string} text
     * @returns {string}
     */
    _normalize(text) {
        return String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    },

    /**
     * Clic: marca o desmarca el destinatario. Doble clic: lo pasa a la otra lista.
     *
     * @param {MouseEvent} event
     * @param {boolean} isDouble
     */
    _onItemEvent(event, isDouble) {
        const element = event.target.closest('[data-code]');
        const item = element ? this._findItem(element.dataset.code) : undefined;
        if (!item) {
            return;
        }

        if (false === isDouble) {
            // sin repintar: si se reconstruye la lista entre los dos clics, el navegador no lanza el dblclick
            item.marked = !item.marked;
            this._setItemClasses(element, item);
            return;
        }

        item.selected = !item.selected;
        item.marked = false;
        this.render();
    },

    /**
     * Convierte el texto de búsqueda en términos: 'texto%' comienza por, '%texto' termina por
     * y el resto contiene. Un % intermedio se trata como literal.
     *
     * @param {string} text
     * @returns {Array}
     */
    _parseTerms(text) {
        return this._normalize(text).split(/\s+/).filter(word => word !== '').map(word => {
            const starts = word.startsWith('%');
            const ends = word.length > 1 && word.endsWith('%');
            const value = word.substring(starts ? 1 : 0, ends ? word.length - 1 : word.length);
            const mode = starts === ends ? 'contains' : (ends ? 'start' : 'end');
            return {mode: mode, value: value};
        }).filter(term => term.value !== '');
    },

    /**
     * Pinta los destinatarios en una lista.
     *
     * @param {HTMLElement} list
     * @param {Array} items
     */
    _renderList(list, items) {
        list.replaceChildren();
        if (items.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'list-group-item text-muted text-center';
            empty.textContent = this._container.dataset.empty;
            list.appendChild(empty);
            return;
        }

        items.forEach(item => {
            const element = document.createElement('div');
            element.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            this._setItemClasses(element, item);
            element.dataset.code = item.code;
            element.style.cursor = 'pointer';

            const info = document.createElement('div');
            const title = document.createElement('div');
            title.textContent = item.cifnif + ' · ' + item.name;
            const email = document.createElement('small');
            email.textContent = item.email;
            info.append(title, email);

            const side = document.createElement('div');
            side.className = 'text-end text-nowrap ms-2';
            if (item.sent) {
                const badge = document.createElement('span');
                badge.className = 'badge bg-success me-2';
                badge.textContent = this._container.dataset.sent;
                side.appendChild(badge);
            }
            side.appendChild(document.createTextNode(item.total));

            element.append(info, side);
            list.appendChild(element);
        });
    },

    /**
     * Envía los seleccionados. Al terminar los devuelve a incluidos marcados como enviados.
     */
    async _send() {
        const codes = this._items.filter(item => item.selected).map(item => item.code);
        if (codes.length === 0 || this._sending) {
            return;
        }

        const data = new FormData(this._form);
        data.append('action', 'send-selected');
        codes.forEach(code => data.append('codes[]', code));

        this._setLocked(true);
        try {
            const response = await fetch(this._container.dataset.url, {method: 'POST', body: data});
            const result = await response.json();
            if (result.token) {
                this._form.querySelector('[name="multireqtoken"]').value = result.token;
            }
            if (result.ok) {
                result.codes.forEach(code => {
                    const item = this._findItem(code);
                    if (item) {
                        item.selected = false;
                        item.sent = true;
                    }
                });
            }
            this._showResult(result.ok, result.message);
        } catch (error) {
            this._showResult(false, error.message);
        } finally {
            this._setLocked(false);
        }
    },

    /**
     * Aplica al elemento las clases de su estado: marcado o enviado.
     *
     * @param {HTMLElement} element
     * @param {Object} item
     */
    _setItemClasses(element, item) {
        element.classList.toggle('active', item.marked);
        element.classList.toggle('list-group-item-success', item.sent);
    },

    /**
     * Bloquea o desbloquea la pantalla durante el envío: listas, buscador, botones y "Volver".
     *
     * @param {boolean} locked
     */
    _setLocked(locked) {
        this._sending = locked;
        this._container.inert = locked;
        this._form.inert = locked;
        this._container.classList.toggle('opacity-50', locked);
        document.getElementById('m347-send-spinner').classList.toggle('d-none', !locked);
        this.render();
    },

    /**
     * Muestra el resultado del envío.
     *
     * @param {boolean} ok
     * @param {string} message
     */
    _showResult(ok, message) {
        const result = document.getElementById('m347-result');
        result.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
        result.textContent = message;
    }
};

document.addEventListener('DOMContentLoaded', () => Modelo347.Email.init());
