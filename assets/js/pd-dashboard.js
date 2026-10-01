/* PesaDonations staff dashboard — Alpine component.
   Data arrives as JSON in #pd-dashboard-data; other ranges come from
   admin-ajax (action pd_dashboard_data). Every value reaches the DOM through
   x-text or textContent. The chart is plain SVG built with createElementNS. */
(function () {
	'use strict';

	var SVG = 'http://www.w3.org/2000/svg';

	function readJson(id) {
		var el = document.getElementById(id);
		try { return el ? JSON.parse(el.textContent) : {}; } catch (e) { return {}; }
	}

	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str).replace(/%(\d\$)?[sd]/g, function (m, pos) {
			var v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
			return v === undefined ? m : String(v);
		}).replace(/%%/g, '%');
	}

	/**
	 * Axis ticks on round numbers. The step comes first (1, 2, 2.5 or 5 times a
	 * power of ten, for about four intervals) and the top is a whole number of
	 * steps: splitting a round top into quarters gave 1.25M, shown as "1.3M".
	 */
	function niceScale(v) {
		if (v <= 0) return { max: 1, step: 0.25 };
		var raw = v / 4, p = Math.pow(10, Math.floor(Math.log10(raw)));
		var steps = [1, 2, 2.5, 5, 10], step = 10 * p;
		for (var i = 0; i < steps.length; i++) if (steps[i] * p >= raw) { step = steps[i] * p; break; }
		return { max: Math.ceil(v / step) * step, step: step };
	}

	/** A column whose top corners are rounded (radius r) and whose base is square. */
	function topRounded(x, y, w, h, r) {
		r = Math.max(0, Math.min(r, w / 2, h));
		return 'M' + x + ',' + (y + h) + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y +
			'H' + (x + w - r) + 'Q' + (x + w) + ',' + y + ' ' + (x + w) + ',' + (y + r) + 'V' + (y + h) + 'Z';
	}

	function el(name, attrs, parent) {
		var n = document.createElementNS(SVG, name);
		Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
		if (parent) parent.appendChild(n);
		return n;
	}

	document.addEventListener('alpine:init', function () {
		window.Alpine.data('pdDashboard', function () {
			var p = readJson('pd-dashboard-data');
			var t = readJson('pd-dashboard-i18n');

			return {
				p: p,
				t: t,
				d: p.data,
				now: p.now,
				range: p.range,
				cache: {},
				loading: false,
				error: '',
				table: false,
				tip: null,
				menu: false,
				dark: false,
				ranges: Object.keys(t.ranges || {}).map(function (k) { return { key: k, label: t.ranges[k] }; }),

				init: function () {
					var self = this;
					this.cache[this.range] = this.d;
					this.dark = this.isDark();
					this.$nextTick(function () { self.drawChart(); });
					if ('ResizeObserver' in window) {
						var last = 0;
						new ResizeObserver(function (entries) {
							var w = Math.round(entries[0].contentRect.width);
							if (w && w !== last) { last = w; self.drawChart(); }
						}).observe(this.$refs.chart);
					}
					if (window.matchMedia) {
						window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () { self.dark = self.isDark(); });
					}
				},

				// ---- Range -------------------------------------------------------

				setRange: function (key, force) {
					var self = this;
					this.range = key;
					this.error = '';
					if (this.cache[key] && !force) {
						this.d = this.cache[key];
						this.$nextTick(function () { self.drawChart(); });
						return;
					}
					this.loading = true; // Holds the previous render, dimmed: no skeleton, no jump.
					var url = this.p.ajaxUrl + '?action=pd_dashboard_data&range=' + encodeURIComponent(key) + '&nonce=' + encodeURIComponent(this.p.nonce);
					fetch(url, { credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							if (!res || !res.success) throw new Error((res && res.data && res.data.message) || '');
							self.cache[key] = res.data;
							if (self.range === key) {
								self.d = res.data;
								self.$nextTick(function () { self.drawChart(); });
							}
						})
						.catch(function (e) { self.error = (e && e.message) || self.t.loadError; })
						.finally(function () { self.loading = false; });
				},

				exportUrl: function () {
					return this.p.export.url + '?action=pd_export_donations&from=' + this.d.from + '&to=' + this.d.to + '&_wpnonce=' + encodeURIComponent(this.p.export.nonce);
				},

				// ---- Words -------------------------------------------------------

				greeting: function () {
					var h = new Date().getHours();
					var key = h < 12 ? 'morning' : (h < 17 ? 'afternoon' : 'evening');
					return fmt(this.t[key], this.p.user.first);
				},
				todayLine: function () {
					return fmt(this.t.today, this.money(this.d.kpis.today));
				},
				vsLabel: function () { return this.t.vs[this.d.range] || ''; },
				kpiLabel: function (k) { return this.t[k]; },
				kpiValue: function (k) {
					var v = this.d.kpis[k].value;
					if (v === null) return '—'; // Nothing to average: a dash, never a zero.
					return k === 'average' ? this.money(v) : this.number(v);
				},
				kpiFoot: function (k) {
					if (k === 'average' && this.d.kpis.average.value === null) return this.t.noGifts;
					if (k === 'donors') return fmt(this.t.newDonors, this.d.donors['new']);
					return this.delta(k) ? this.vsLabel() : ''; // "vs …" only beside a comparison.
				},
				giftsLabel: function (n) { return n === 1 ? this.t.gift1 : fmt(this.t.giftN, n); },
				lapsedTitle: function () {
					var n = this.now.lapsed.total;
					return n === 1 ? this.t.lapsed1 : fmt(this.t.lapsedN, n);
				},
				otherCurrencies: function () {
					var self = this;
					return fmt(this.t.alsoIn, this.d.other_currencies.map(function (c) {
						return self.money(c.amount, c.currency) + ' (' + self.giftsLabel(c.gifts) + ')';
					}).join(', '));
				},
				initials: function (name) {
					var parts = String(name || '?').replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean);
					return (parts.slice(0, 2).map(function (s) { return s.charAt(0); }).join('') || '?').toUpperCase();
				},

				// ---- Numbers -----------------------------------------------------

				money: function (v, currency) {
					currency = currency || this.d.currency;
					try {
						return new Intl.NumberFormat(this.p.locale, { style: 'currency', currency: currency, currencyDisplay: 'code', maximumFractionDigits: currency === 'USD' ? 2 : 0 }).format(v || 0);
					} catch (e) {
						return currency + ' ' + Math.round(v || 0).toLocaleString();
					}
				},
				compact: function (v) {
					try {
						return new Intl.NumberFormat(this.p.locale, { notation: 'compact', maximumFractionDigits: 1 }).format(v || 0);
					} catch (e) { return Math.round(v || 0).toLocaleString(); }
				},
				number: function (v) {
					try { return new Intl.NumberFormat(this.p.locale).format(v || 0); } catch (e) { return String(v); }
				},

				/** Signed change vs the previous range. None when there is nothing to compare with. */
				delta: function (k) {
					var cur = this.d.kpis[k].value, prev = this.d.kpis[k].previous;
					if (cur === null || prev === null || prev === undefined) return null;
					if (prev === 0) return cur > 0 ? { text: this.t.upFromZero, tone: 'good', icon: 'up' } : null; // No percentage of nothing.
					var pct = Math.round(((cur - prev) / prev) * 100);
					if (pct === 0) return { text: '0%', tone: 'flat', icon: 'up' };
					return { text: (pct > 0 ? '+' : '') + pct + '%', tone: pct > 0 ? 'good' : 'bad', icon: pct > 0 ? 'up' : 'down' };
				},

				share: function (amount) {
					var max = Math.max.apply(null, this.d.by_campaign.map(function (r) { return r.amount; }).concat([0]));
					return max > 0 ? Math.max(1, (amount / max) * 100) : 0;
				},

				// ---- Campaigns ---------------------------------------------------

				badge: function (c) {
					switch (c.pace) {
						case 'behind':   return { text: this.t.behind, tone: 'behind', icon: 'alert' };
						case 'on_track': return { text: this.t.onTrack, tone: 'good', icon: 'check' };
						case 'reached':  return { text: this.t.reached, tone: 'good', icon: 'check' };
						case 'upcoming': return { text: this.t.upcoming, tone: 'muted', icon: 'clock' };
						default:         return null;
					}
				},
				timeLeft: function (c) {
					if (c.starts_in !== null) return c.starts_in === 1 ? this.t.startsIn1 : fmt(this.t.startsInN, c.starts_in);
					if (c.days_left === null) return this.t.noEnd;
					return c.days_left <= 1 ? this.t.daysLeft1 : fmt(this.t.daysLeftN, c.days_left);
				},
				meterLabel: function (c) {
					var raised = Math.round(c.progress * 100);
					return c.elapsed === null ? fmt(this.t.meterNoPace, raised) : fmt(this.t.meter, raised, Math.round(c.elapsed * 100));
				},
				attentionIcon: function (item) {
					return { pending: 'clock', failed: 'x', no_next_period: 'calendar', ending: 'calendar', reminders: 'mail' }[item.kind] || 'alert';
				},

				// ---- Theme -------------------------------------------------------

				isDark: function () {
					var set = document.documentElement.getAttribute('data-theme');
					if (set) return set === 'dark';
					return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
				},
				toggleTheme: function () {
					var next = this.isDark() ? 'light' : 'dark';
					document.documentElement.setAttribute('data-theme', next);
					try { localStorage.setItem('pdd-theme', next); } catch (e) { /* private mode: this visit only */ }
					this.dark = next === 'dark';
				},

				// ---- Chart -------------------------------------------------------

				hasSeries: function () {
					return this.d.series.some(function (b) { return b.campaign > 0 || b.open > 0; });
				},

				/**
				 * Stacked columns: campaigns (slot 1) under open donations (slot 2),
				 * a 2px surface gap between segments and between columns, 4px rounded
				 * tops, square at the baseline, hairline grid, one y-axis.
				 */
				drawChart: function () {
					var host = this.$refs.chart;
					if (!host || this.table || !this.hasSeries()) return;
					var self = this;
					var W = Math.max(280, host.clientWidth || 600);
					var H = W < 480 ? 200 : 260;
					var padL = 52, padR = 8, padT = 12, padB = 26;
					var plotW = W - padL - padR, plotH = H - padT - padB;
					var s = this.d.series;
					var scale = niceScale(Math.max.apply(null, s.map(function (b) { return b.campaign + b.open; })));
					var max = scale.max;
					var slot = plotW / s.length;
					var barW = Math.max(1, Math.min(24, slot - 2));
					var y = function (v) { return padT + plotH - (v / max) * plotH; };

					var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': this.chartSummary() });
					for (var i = 0; i * scale.step <= max + scale.step / 1000; i++) {
						var v = scale.step * i, gy = Math.round(y(v)) + 0.5;
						el('line', { x1: padL, x2: W - padR, y1: gy, y2: gy, 'class': i === 0 ? 'base' : 'grid' }, svg);
						var label = el('text', { x: padL - 8, y: gy + 4, 'text-anchor': 'end', 'class': 'tick' }, svg);
						label.textContent = this.compact(v);
					}

					var every = s.length <= 12 ? 1 : Math.ceil(s.length / (W < 480 ? 5 : 8));
					s.forEach(function (b, idx) {
						var x = padL + idx * slot + (slot - barW) / 2;
						var g = el('g', { 'class': 'col' }, svg);
						var base = padT + plotH;
						var hc = b.campaign > 0 ? (b.campaign / max) * plotH : 0;
						var ho = b.open > 0 ? (b.open / max) * plotH : 0;
						var gap = hc > 0 && ho > 0 ? 2 : 0;
						if (hc > 0) {
							var cTop = base - hc;
							el('path', { d: ho > 0 ? topRounded(x, cTop, barW, hc, 0) : topRounded(x, cTop, barW, hc, 4), 'class': 's1' }, g);
						}
						if (ho > 0) {
							var oH = Math.max(1, ho - gap);
							el('path', { d: topRounded(x, base - hc - gap - oH, barW, oH, 4), 'class': 's2' }, g);
						}
						var hit = el('rect', { x: padL + idx * slot, y: padT, width: slot, height: plotH, 'class': 'hit' }, g);
						var show = function () {
							svg.querySelectorAll('.col.is-active').forEach(function (n) { n.classList.remove('is-active'); });
							g.classList.add('is-active');
							var box = host.getBoundingClientRect(), card = host.parentElement.getBoundingClientRect();
							var scale = box.width / W;
							self.tip = {
								title: b.label + (b.partial ? ' *' : ''),
								campaign: b.campaign, open: b.open,
								gifts: self.giftsLabel(b.gifts),
								x: (box.left - card.left) + (x + barW / 2) * scale,
								y: (box.top - card.top) + y(b.campaign + b.open) * scale - 10
							};
						};
						hit.addEventListener('pointermove', show);
						hit.addEventListener('pointerenter', show);

						if (idx % every === 0 || idx === s.length - 1) {
							if (idx !== s.length - 1 && s.length - 1 - idx < every / 2) return; // keep the last label clear
							var tx = el('text', { x: x + barW / 2, y: H - 6, 'text-anchor': 'middle', 'class': 'tick' }, svg);
							tx.textContent = s.length <= 12 ? b.short : b.label;
						}
					});

					svg.addEventListener('pointerleave', function () {
						self.tip = null;
						svg.querySelectorAll('.col.is-active').forEach(function (n) { n.classList.remove('is-active'); });
					});
					host.replaceChildren(svg);
				},

				chartSummary: function () {
					var total = this.d.series.reduce(function (a, b) { return a + b.campaign + b.open; }, 0);
					return this.money(total) + ' · ' + this.d.label;
				}
			};
		});
	});
})();
