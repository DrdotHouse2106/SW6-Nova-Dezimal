/*
 * Nova Dezimal – Produktseite: Plus/Minus für den Dezimalwert, Eingabe auf Min/Max/Schritt runden,
 * Live-Gesamtpreis. Ohne Build-Schritt lauffähig (dist/storefront/js/nova-dezimal/nova-dezimal.js ist dieselbe Datei).
 */
(() => {
    if (!window.PluginManager || !window.PluginBaseClass) {
        return;
    }

    class NovaDezimalPlugin extends window.PluginBaseClass {
        static options = {};

        init() {
            this._opts = JSON.parse(this.el.getAttribute('data-nova-dezimal-options') || '{}');
            this._form = this.el.closest('form') || document;
            this._total = this.el.querySelector('.nova-dezimal-total');
            this._value = this._form.querySelector('.js-nova-dezimal-value');
            this._quantity = this._form.querySelector('.js-quantity-selector');
            this._running = this.el.querySelector('.nova-dezimal-running');

            // Großer Preis oben = Gesamtpreis für den gewählten Wert; der Preis je Einheit steht klein darunter
            const scope = this.el.closest('.product-detail-buy, .cms-element-buy-box, .product-detail, .modal-body') || document;
            this._priceEl = this._opts.mode === 'menge' ? scope.querySelector('.product-detail-price') : null;
            if (this._priceEl) {
                this._unitPriceEl = document.createElement('p');
                this._unitPriceEl.className = 'nova-dezimal-unit-price text-muted mb-1';
                this._unitPriceEl.textContent = String(this._opts.labelUnitPrice || '%price% / %unit%')
                    .replace('%price%', this._money(this._opts.price / (this._opts.basePriceValue || 1)))
                    .replace('%unit%', this._opts.unit);
                this._priceEl.insertAdjacentElement('afterend', this._unitPriceEl);
            }

            if (this._value) {
                this._value.addEventListener('input', () => this._update());
                this._value.addEventListener('change', () => this._normalizeInput());
                this._value.addEventListener('blur', () => this._normalizeInput());
                const minus = this._form.querySelector('.js-nova-dezimal-minus');
                const plus = this._form.querySelector('.js-nova-dezimal-plus');
                if (minus) {
                    minus.addEventListener('click', () => this._stepBy(-1));
                }
                if (plus) {
                    plus.addEventListener('click', () => this._stepBy(1));
                }
            }
            if (this._quantity) {
                this._quantity.addEventListener('input', () => this._update());
                this._quantity.addEventListener('change', () => this._update());
                // Plus/Minus von Shopware ändern den Wert ohne input-Event
                this._form.querySelectorAll('.js-btn-plus, .js-btn-minus').forEach((button) => {
                    button.addEventListener('click', () => window.setTimeout(() => this._update(), 0));
                });
            }

            this._update();
        }

        _parse(text) {
            const value = parseFloat(String(text || '').replace(/\s/g, '').replace(',', '.'));
            return Number.isFinite(value) ? value : NaN;
        }

        _normalize(value) {
            const o = this._opts;
            if (!Number.isFinite(value) || value <= 0) {
                value = o.min;
            }
            value = Math.min(o.max, Math.max(o.min, value));
            if (o.step > 0) {
                const steps = Math.ceil(Math.round(((value - o.min) / o.step) * 1e6) / 1e6 - 1e-6);
                value = o.min + steps * o.step;
                if (value > o.max + 1e-9) {
                    value = o.min + Math.floor((o.max - o.min) / o.step + 1e-9) * o.step;
                }
            }
            return Math.round(value * 10000) / 10000;
        }

        _formatValue(value) {
            return value.toFixed(this._opts.decimals || 0).replace('.', ',');
        }

        _normalizeInput() {
            const value = this._normalize(this._parse(this._value.value));
            this._value.value = this._formatValue(value);
            this._update();
        }

        _stepBy(direction) {
            const current = this._parse(this._value.value);
            const base = Number.isFinite(current) ? current : this._opts.min;
            this._value.value = this._formatValue(this._normalize(base + direction * (this._opts.step || 1)));
            this._update();
        }

        _money(value) {
            try {
                return new Intl.NumberFormat(this._opts.locale || 'de-DE', { style: 'currency', currency: this._opts.currency || 'EUR' }).format(value);
            } catch (e) {
                return value.toFixed(2).replace('.', ',') + ' €';
            }
        }

        _number(value, decimals) {
            return new Intl.NumberFormat(this._opts.locale || 'de-DE', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(value);
        }

        _update() {
            if (!this._total) {
                return;
            }
            const o = this._opts;

            if (o.mode === 'stueck') {
                const pieces = Math.max(1, parseInt(this._quantity ? this._quantity.value : '1', 10) || 1);
                const amount = pieces * o.pieceArea;
                const text = String(o.labelPieces || '%pieces% × = %amount% %unit%')
                    .replace('%pieces%', pieces)
                    .replace('%amount%', this._number(amount, 2))
                    .replace(/%unit%/g, o.unit);
                this._total.textContent = text + ' · ' + (o.labelTotal || 'Total') + ': ' + this._money(o.price * pieces);
                return;
            }

            const value = this._parse(this._value ? this._value.value : '');
            if (!Number.isFinite(value) || value <= 0) {
                this._total.textContent = '';
                return;
            }
            const normalized = this._normalize(value);
            const total = (o.price * normalized) / (o.basePriceValue || 1);
            const amount = this._number(normalized, o.decimals || 0) + ' ' + o.unit;

            if (this._priceEl) {
                this._priceEl.textContent = this._money(total);
                this._total.textContent = amount + ' × ' + this._money(o.price / (o.basePriceValue || 1)) + ' / ' + o.unit;
            } else {
                this._total.textContent = (o.labelTotal || 'Total') + ': ' + this._money(total) + ' (' + amount + ')';
            }

            if (this._running) {
                const unitIsArea = ['m²', 'm2', 'qm'].indexOf(o.unit) !== -1;
                if (o.rollWidth > 0 && unitIsArea) {
                    const metres = normalized / (o.rollWidth / 1000);
                    this._running.textContent = String(o.labelRunning || '= %metres% m')
                        .replace('%metres%', this._number(metres, 2))
                        .replace('%width%', this._number(o.rollWidth, 0));
                } else {
                    this._running.textContent = '';
                }
            }
        }
    }

    window.PluginManager.register('NovaDezimal', NovaDezimalPlugin, '[data-nova-dezimal]');
})();
