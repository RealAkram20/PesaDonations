/**
 * PesaDonations Alpine.js components.
 * Requires Alpine.js v3 (assets/js/alpine.min.js), which loads after this file.
 */

/* =========================================================================
   Shared helpers
   =========================================================================*/

/** Copies properties with their getters intact (Object.assign would freeze a getter into a value). */
function pdMix(target, ...sources) {
	sources.forEach(s => Object.defineProperties(target, Object.getOwnPropertyDescriptors(s)));
	return target;
}

/** The { config, items } a shortcode prints in a JSON script block inside the component. */
function pdReadData(root) {
	const el = root && root.querySelector(':scope > script.pd-data');
	if (!el) return {};
	try { return JSON.parse(el.textContent) || {}; } catch (e) { return {}; }
}

/** Story and gallery per campaign, fetched once per page view. A failed request may be retried. */
const pdDetailsRequests = {};
function pdFetchDetails(ajaxUrl, id) {
	if (!pdDetailsRequests[id]) {
		const url = ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=pd_campaign_details&id=' + encodeURIComponent(id);
		pdDetailsRequests[id] = fetch(url, { credentials: 'same-origin' })
			.then(res => res.json())
			.then(json => {
				if (!json || !json.success || !json.data) throw new Error('pd_campaign_details');
				return { content: json.data.content || '', gallery: Array.isArray(json.data.gallery) ? json.data.gallery : [] };
			});
		pdDetailsRequests[id].catch(() => { delete pdDetailsRequests[id]; });
	}
	return pdDetailsRequests[id];
}

/** Digits and one decimal point: "50,000" and "50 000" read as 50000. NaN when it is not a number. */
function pdNumber(value) {
	const raw = String(value === null || value === undefined ? '' : value).replace(/[\s, ]/g, '');
	return /^\d+(\.\d+)?$/.test(raw) ? parseFloat(raw) : NaN;
}

/* =========================================================================
   Details modal + gallery lightbox, shared by the browse pages and sliders.
   The card's own data shows at once; the story and gallery follow.
   =========================================================================*/
function pdDetails() {
	let lastFocus = null;

	return {
		campaigns:      [],
		ajaxUrl:        '',
		modalOpen:      false,
		active:         null,
		detailsLoading: false,
		detailsFailed:  false,
		lightboxOpen:   false,
		lightboxIndex:  0,

		openDetails(id) {
			const card = this.campaigns.find(c => c.id === id);
			if (!card) return;
			lastFocus   = document.activeElement;
			this.active = Object.assign({ content: '', gallery: [] }, card);
			this.modalOpen = true;
			document.body.style.overflow = 'hidden';
			this.onDetailsOpen();
			this.loadDetails(id);
			this.$nextTick(() => {
				const close = this.$root.querySelector('.pd-modal__close');
				if (close) close.focus();
			});
		},

		loadDetails(id) {
			this.detailsLoading = true;
			this.detailsFailed  = false;
			pdFetchDetails(this.ajaxUrl, id).then(
				d => {
					if (!this.modalOpen || !this.active || this.active.id !== id) return;
					this.active.content = d.content;
					this.active.gallery = d.gallery;
					this.detailsLoading = false;
				},
				() => {
					if (!this.modalOpen || !this.active || this.active.id !== id) return;
					this.detailsLoading = false;
					this.detailsFailed  = true;
				}
			);
		},

		retryDetails() {
			if (this.active) this.loadDetails(this.active.id);
		},

		closeModal() {
			this.modalOpen      = false;
			this.lightboxOpen   = false;
			// The card data stays (nulling it made the nested progress block's
			// bindings throw before Alpine removed it). Emptying the story stops
			// an embedded video from playing on behind the closed modal.
			if (this.active) {
				this.active.content = '';
				this.active.gallery = [];
			}
			this.detailsLoading = false;
			this.detailsFailed  = false;
			document.body.style.overflow = '';
			this.onDetailsClose();
			if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
			lastFocus = null;
		},

		onEscape() {
			if (this.lightboxOpen) this.closeLightbox();
			else if (this.modalOpen) this.closeModal();
		},

		// Hooks for a component that must react (the slider pauses its autoplay).
		onDetailsOpen()  {},
		onDetailsClose() {},

		/* ---- Gallery lightbox ------------------------------------------- */
		get lightboxImages() {
			return (this.active && Array.isArray(this.active.gallery)) ? this.active.gallery : [];
		},
		get lightboxTotal() { return this.lightboxImages.length; },
		get lightboxImage() {
			const img = this.lightboxImages[this.lightboxIndex];
			return img ? img.full : '';
		},
		get lightboxAlt() {
			const img = this.lightboxImages[this.lightboxIndex];
			return img ? (img.alt || '') : '';
		},
		openLightbox(index) {
			if (!this.lightboxTotal) return;
			this.lightboxIndex = index;
			this.lightboxOpen  = true;
		},
		closeLightbox() {
			this.lightboxOpen = false;
		},
		lightboxNext() {
			if (!this.lightboxTotal) return;
			this.lightboxIndex = (this.lightboxIndex + 1) % this.lightboxTotal;
			this.scrollActiveThumbIntoView();
		},
		lightboxPrev() {
			if (!this.lightboxTotal) return;
			this.lightboxIndex = (this.lightboxIndex - 1 + this.lightboxTotal) % this.lightboxTotal;
			this.scrollActiveThumbIntoView();
		},
		scrollActiveThumbIntoView() {
			this.$nextTick(() => {
				const strip = this.$refs.strip;
				if (!strip) return;
				const active = strip.querySelector('.pd-lightbox__thumb--active');
				if (active) active.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
			});
		},
	};
}

/* =========================================================================
   Browse Page — sidebar filters, toolbar, grid/list, pagination.
   Usage: x-data="pdBrowse()" with a script.pd-data block inside.
   =========================================================================*/
function pdBrowse() {
	return pdMix(pdDetails(), {
		type:        'project',
		columns:     3,
		i18n:        {},
		sortLabels:  {},
		view:        'grid',
		sort:        'default',
		perPage:     12,
		page:        1,
		filtersOpen: false,  // mobile drawer state
		filters: {
			search:    '',
			ageRange:  [],
			status:    [],
			goalRange: [],
		},

		init() {
			const data = pdReadData(this.$el);
			const cfg  = data.config || {};
			this.campaigns  = Array.isArray(data.items) ? data.items : [];
			this.type       = cfg.type || 'project';
			this.i18n       = cfg.i18n || {};
			this.sortLabels = cfg.sortLabels || {};
			this.perPage    = parseInt(cfg.perPage, 10) || 12;
			this.columns    = parseInt(cfg.columns, 10) || 3;
			this.ajaxUrl    = cfg.ajaxUrl || '';

			// A narrower result set must not leave the visitor on a page past its
			// end, which showed "No results" while results existed.
			this.$watch('filters', () => { this.page = 1; });
			this.$watch('sort',    () => { this.page = 1; });
			this.$watch('perPage', () => { this.page = 1; });
		},

		/* ---- Computed (reactive) ------------------------------------ */
		get isGridView()    { return this.view === 'grid'; },
		get isListView()    { return this.view === 'list'; },
		get showGrid()      { return this.paginated.length > 0 && this.view === 'grid'; },
		get showList()      { return this.paginated.length > 0 && this.view === 'list'; },
		get showEmpty()     { return this.paginated.length === 0; },
		get showPaginator() { return this.totalPages > 1; },
		get currentPage()   { return Math.min(Math.max(1, this.page), this.totalPages); },
		get onFirstPage()   { return this.currentPage === 1; },
		get onLastPage()    { return this.currentPage === this.totalPages; },

		get hasActiveFilters() {
			return this.activeFilterCount > 0;
		},

		get activeFilterCount() {
			let n = this.filters.search.trim() !== '' ? 1 : 0;
			n += this.filters.ageRange.length;
			n += this.filters.status.length;
			n += this.filters.goalRange.length;
			return n;
		},

		get sortLabel() {
			return this.sortLabels[this.sort] || this.sortLabels['default'] || '';
		},

		get filtered() {
			const f = this.filters;
			const q = f.search.trim().toLowerCase();

			const list = this.campaigns.filter(c => {
				if (q) {
					const haystack = [c.title, c.beneficiary, c.location, c.code]
						.filter(Boolean).join(' ').toLowerCase();
					if (!haystack.includes(q)) return false;
				}
				if (f.ageRange.length && this.type === 'sponsorship') {
					if (c.age === '') return false;
					if (!f.ageRange.some(r => this.ageInRange(c.age, r))) return false;
				}
				if (f.status.length) {
					const wantAvail  = f.status.includes('available');
					const wantFunded = f.status.includes('funded');
					if (wantAvail && !wantFunded && c.funded)  return false;
					if (wantFunded && !wantAvail && !c.funded) return false;
				}
				if (f.goalRange.length && this.type === 'project') {
					if (!f.goalRange.some(r => this.goalInRange(c.goal, r))) return false;
				}
				return true;
			});

			return this.applySort(list);
		},

		get paginated() {
			const start = (this.currentPage - 1) * this.perPage;
			return this.filtered.slice(start, start + this.perPage);
		},

		get totalPages() {
			return Math.max(1, Math.ceil(this.filtered.length / this.perPage));
		},

		goToPage(p) {
			this.page = Math.min(Math.max(1, p), this.totalPages);
			this.$nextTick(() => {
				const top = this.$root.getBoundingClientRect().top;
				if (top < 0) this.$root.scrollIntoView({ behavior: 'smooth', block: 'start' });
			});
		},

		/* ---- Filters helpers ---------------------------------------- */
		ageInRange(age, range) {
			age = parseInt(age, 10);
			if (isNaN(age)) return false;
			switch (range) {
				case '0-5':   return age >= 0  && age <= 5;
				case '6-10':  return age >= 6  && age <= 10;
				case '11-15': return age >= 11 && age <= 15;
				case '16-18': return age >= 16 && age <= 18;
				case '18+':   return age >= 18;
			}
			return true;
		},

		goalInRange(goal, range) {
			goal = parseFloat(goal) || 0;
			switch (range) {
				case '0-100000':       return goal < 100000;
				case '100000-500000':  return goal >= 100000 && goal < 500000;
				case '500000-1000000': return goal >= 500000 && goal < 1000000;
				case '1000000+':       return goal >= 1000000;
			}
			return true;
		},

		applySort(list) {
			const copy   = [...list];
			const nameOf = c => (c.display_title || c.title || '').toLowerCase();
			const ageOf  = c => (c.age === '' ? 999 : parseInt(c.age, 10));
			const goalOf = c => parseFloat(c.goal) || 0;
			const progOf = c => parseFloat(c.progress) || 0;

			switch (this.sort) {
				case 'name_asc':      copy.sort((a, b) => nameOf(a).localeCompare(nameOf(b))); break;
				case 'name_desc':     copy.sort((a, b) => nameOf(b).localeCompare(nameOf(a))); break;
				case 'age_asc':       copy.sort((a, b) => ageOf(a) - ageOf(b)); break;
				case 'age_desc':      copy.sort((a, b) => ageOf(b) - ageOf(a)); break;
				case 'progress_desc': copy.sort((a, b) => progOf(b) - progOf(a)); break;
				case 'progress_asc':  copy.sort((a, b) => progOf(a) - progOf(b)); break;
				case 'goal_desc':     copy.sort((a, b) => goalOf(b) - goalOf(a)); break;
				case 'goal_asc':      copy.sort((a, b) => goalOf(a) - goalOf(b)); break;
				case 'recent':        copy.sort((a, b) => b.id - a.id); break;
			}
			return copy;
		},

		resetFilters() {
			this.filters = { search: '', ageRange: [], status: [], goalRange: [] };
		},

		setView(grid) {
			this.view = grid ? 'grid' : 'list';
		},

		/** The first row is on screen at load: fetch it at once, lazy-load the rest. */
		imageLoading(i) {
			return i < this.columns ? 'eager' : 'lazy';
		},
	});
}

/* =========================================================================
   Slider — horizontal carousel with prev/next arrows, optional autoplay.
   Native CSS scroll-snap for smooth scrolling and touch/swipe support.
   Usage: x-data="pdSlider()" with a script.pd-data block inside.
   =========================================================================*/
function pdSlider() {
	return pdMix(pdDetails(), {
		sliderOn:       false,
		sliderInterval: 4500,
		sliderTimer:    null,
		sliderPaused:   false,

		init() {
			const data = pdReadData(this.$el);
			const cfg  = data.config || {};
			this.campaigns      = Array.isArray(data.items) ? data.items : [];
			this.ajaxUrl        = cfg.ajaxUrl || '';
			this.sliderInterval = Math.max(1500, parseInt(cfg.interval, 10) || 4500);
			// Moving content is not started for a visitor who asked the system for less motion.
			const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			this.sliderOn = !!cfg.autoplay && !reduce;
			if (this.sliderOn) this.startSlider();
		},

		destroy() {
			this.stopSlider();
		},

		/* ---- Slider navigation ------------------------------------- */
		slideDistance() {
			const track = this.$refs.track;
			if (!track) return 0;
			const slide = track.querySelector('.pd-slider__slide');
			if (!slide) return track.clientWidth;
			const style = getComputedStyle(track);
			const gap   = parseInt(style.columnGap || style.gap || '20', 10) || 0;
			return slide.offsetWidth + gap;
		},

		prev() {
			const track = this.$refs.track;
			if (!track) return;
			if (track.scrollLeft < 10) {
				track.scrollTo({ left: track.scrollWidth, behavior: 'smooth' });
			} else {
				track.scrollBy({ left: -this.slideDistance(), behavior: 'smooth' });
			}
			this.resetSlider();
		},

		next() {
			const track = this.$refs.track;
			if (!track) return;
			const maxScroll = track.scrollWidth - track.clientWidth;
			if (track.scrollLeft >= maxScroll - 10) {
				track.scrollTo({ left: 0, behavior: 'smooth' });
			} else {
				track.scrollBy({ left: this.slideDistance(), behavior: 'smooth' });
			}
			this.resetSlider();
		},

		startSlider() {
			if (!this.sliderOn) return;
			this.stopSlider();
			this.sliderTimer = setInterval(() => {
				if (!this.sliderPaused && !document.hidden) this.next();
			}, this.sliderInterval);
		},

		stopSlider() {
			if (this.sliderTimer) {
				clearInterval(this.sliderTimer);
				this.sliderTimer = null;
			}
		},

		pauseAutoplay()  { this.sliderPaused = true; },
		resumeAutoplay() { if (!this.modalOpen) this.sliderPaused = false; },
		resetSlider()    { if (this.sliderOn) this.startSlider(); },

		onDetailsOpen()  { this.pauseAutoplay(); },
		onDetailsClose() { this.resumeAutoplay(); },
	});
}

/* =========================================================================
   Checkout Form
   Usage: x-data="pdCheckout(config)"
   =========================================================================*/
function pdCheckout(configJson) {
	const config = typeof configJson === 'string' ? JSON.parse(configJson) : (configJson || {});
	const t      = config.i18n || {};
	const say    = (key, fallback) => t[key] || fallback;

	return {
		// Config
		campaignId:    config.campaignId || 0,
		currency:      config.currency   || 'UGX',
		plans:         Array.isArray(config.plans) ? config.plans : [],
		hasPlans:      !!config.hasPlans,
		minAmount:     parseFloat(config.minAmount) || 0,
		requireAddr:   !!config.requireAddr,
		nonce:         config.nonce      || '',
		ajaxUrl:       config.ajaxUrl    || '',
		thankYouUrl:   config.thankYouUrl || '',

		// State
		planIndex:          -1,
		isOrg:              false,
		storyOpen:          false,
		loading:            false,
		globalError:        '',
		errors:             {},
		iframeOpen:         false,
		iframeUrl:          '',
		customAmountOpen:   false,
		sliderMin:          0,
		sliderMax:          0,
		sliderStep:         1,

		formData: {
			amount:        '',
			first_name:    '',
			last_name:     '',
			email:         '',
			confirm_email: '',
			phone:         '',
			country:       '',
			address1:      '',
			address2:      '',
			city:          '',
			state:         '',
			zip:           '',
			how_heard:     '',
			notes:         '',
			anonymous:     false,
			updates:       false,
			agree_terms:   false,
		},

		init() {
			if (!this.hasPlans || !this.plans.length) return;

			// The slider runs between the plans' amounts, in the plans' currency.
			const amounts = this.plans.map(p => parseFloat(p.amount)).filter(n => !isNaN(n));
			const min = Math.min(...amounts);
			const max = Math.max(...amounts);

			if (min === max) {
				// Single plan: let the donor slide from half of it to double.
				this.sliderMin = Math.max(this.minAmount || 0, Math.round(min * 0.5));
				this.sliderMax = Math.round(min * 2);
			} else {
				this.sliderMin = min;
				this.sliderMax = max;
			}

			this.sliderStep = this.stepForCurrency(this.amountCurrency);
			this.selectPlan(0);
		},

		stepForCurrency(code) {
			const steps = { UGX: 1000, KES: 50, TZS: 500, USD: 1, EUR: 1, GBP: 1 };
			return steps[code] || 1;
		},

		formatAmount(n) {
			const v = pdNumber(n);
			return isNaN(v) ? '0' : v.toLocaleString();
		},

		/** The selected plan's currency; otherwise the plans' shared one; otherwise the campaign's. */
		get amountCurrency() {
			const plan = this.plans[this.planIndex];
			if (plan && plan.currency) return plan.currency;
			const shared = [...new Set(this.plans.map(p => p.currency || this.currency))];
			return this.hasPlans && shared.length === 1 ? shared[0] : this.currency;
		},

		get currentPlanName() {
			if (this.customAmountOpen) return '';
			const plan = this.plans[this.planIndex];
			return plan ? (plan.name || '') : '';
		},

		isPlanActive(i) {
			return !this.customAmountOpen && this.planIndex === i;
		},

		selectPlan(i) {
			const plan = this.plans[i];
			if (!plan) return;
			this.planIndex        = i;
			this.customAmountOpen = false;
			this.formData.amount  = plan.amount;
		},

		onSliderChange() {
			this.customAmountOpen = false;
			const amount = pdNumber(this.formData.amount);
			this.planIndex = this.plans.findIndex(p => parseFloat(p.amount) === amount && (p.currency || this.currency) === this.amountCurrency);
		},

		onCustomChange() {
			this.planIndex = -1;
		},

		toggleCustom() {
			this.customAmountOpen = !this.customAmountOpen;
			if (this.customAmountOpen) this.planIndex = -1;
		},

		setAmount(n) {
			this.formData.amount = n;
		},

		isAmount(n) {
			return pdNumber(this.formData.amount) === n;
		},

		toggleStory() {
			this.storyOpen = !this.storyOpen;
		},

		closeIframe() {
			this.iframeOpen = false;
			this.iframeUrl  = '';
			document.body.style.overflow = '';
		},

		validate() {
			const errs = {};
			const f    = this.formData;
			const amt  = pdNumber(f.amount);

			if (isNaN(amt) || amt <= 0) {
				errs.amount = say('amount', 'Enter the amount as a number, for example 50000.');
			} else if (this.amountCurrency === this.currency && amt < this.minAmount) {
				// The minimum is set in the campaign's currency and applies only in it (as on the server).
				errs.amount = say('minimum', 'Minimum donation is %s.')
					.replace('%s', Number(this.minAmount).toLocaleString() + ' ' + this.currency);
			}

			if (!f.first_name.trim()) errs.first_name = say('firstName', 'First name is required.');
			if (!f.last_name.trim())  errs.last_name  = say('lastName', 'Last name is required.');

			const email = f.email.trim();
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
				errs.email = say('email', 'A valid email address is required.');
			} else if (email.toLowerCase() !== f.confirm_email.trim().toLowerCase()) {
				errs.confirm_email = say('emailMatch', 'Email addresses do not match.');
			}

			if (this.requireAddr) {
				if (!f.country)          errs.country  = say('country', 'Country is required.');
				if (!f.address1.trim())  errs.address1 = say('address', 'Address is required.');
				if (!f.city.trim())      errs.city     = say('city', 'City is required.');
				if (!f.zip.trim())       errs.zip      = say('zip', 'Zip/Postal code is required.');
			}

			if (!f.agree_terms) {
				errs.agree_terms = say('terms', 'You must agree to the terms to continue.');
			}

			this.errors = errs;
			return Object.keys(errs).length === 0;
		},

		buildBody() {
			const f    = this.formData;
			const body = new FormData();
			body.append('action',      'pd_init_donation');
			body.append('nonce',       this.nonce);
			body.append('campaign_id', this.campaignId);
			body.append('amount',      String(pdNumber(f.amount)));
			body.append('currency',    this.amountCurrency);
			body.append('gateway',     'pesapal');
			body.append('first_name',  f.first_name.trim());
			body.append('last_name',   f.last_name.trim());
			body.append('email',       f.email.trim());
			body.append('phone',       f.phone.trim());
			body.append('country',     f.country);
			body.append('message',     f.notes);
			body.append('how_heard',   f.how_heard);
			body.append('updates',     f.updates ? '1' : '');
			body.append('is_org',      this.isOrg ? '1' : '');
			body.append('anonymous',   f.anonymous ? '1' : '');
			if (this.requireAddr) {
				body.append('address1', f.address1);
				body.append('address2', f.address2);
				body.append('city',     f.city);
				body.append('state',    f.state);
				body.append('zip',      f.zip);
			}
			return body;
		},

		async post() {
			const res = await fetch(this.ajaxUrl, { method: 'POST', body: this.buildBody(), credentials: 'same-origin' });
			return res.json();
		},

		/**
		 * The nonce printed in the page lives 12-24 hours; a page cache can serve
		 * the page for longer. On a nonce refusal, fetch a fresh one (admin-ajax
		 * is never cached) and send once more.
		 */
		async refreshNonce() {
			const res  = await fetch(this.ajaxUrl + (this.ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=pd_nonce', { credentials: 'same-origin', cache: 'no-store' });
			const json = await res.json();
			if (!json || !json.success || !json.data || !json.data.nonce) return false;
			this.nonce = json.data.nonce;
			return true;
		},

		async submit() {
			if (this.loading) return;
			this.globalError = '';

			if (!this.validate()) {
				this.globalError = say('fix', 'Please check the highlighted fields.');
				this.$nextTick(() => {
					const el = this.$root.querySelector('.pd-input--error');
					if (el) {
						el.scrollIntoView({ behavior: 'smooth', block: 'center' });
						el.focus({ preventScroll: true });
					}
				});
				return;
			}

			this.loading = true;

			try {
				let data = await this.post();
				if (!data.success && data.data && data.data.code === 'nonce' && await this.refreshNonce()) {
					data = await this.post();
				}

				if (data.success) {
					if (data.data && data.data.redirect_url) {
						this.iframeUrl  = data.data.redirect_url;
						this.iframeOpen = true;
						document.body.style.overflow = 'hidden';
					} else if (this.thankYouUrl) {
						window.location.href = this.thankYouUrl;
					}
				} else {
					this.globalError = (data.data && data.data.message) || say('generic', 'Something went wrong. Please try again.');
				}
			} catch (err) {
				this.globalError = say('network', 'Network error. Please check your connection and try again.');
			} finally {
				this.loading = false;
			}
		},
	};
}

/* 1.1.0's button markup (x-data="pdDonateButton()") can outlive an update in a page cache. */
function pdDonateButton() {
	return {};
}

/* =========================================================================
   Expose on window so Alpine's x-data can find them regardless of
   script load order.
   =========================================================================*/
window.pdBrowse       = pdBrowse;
window.pdSlider       = pdSlider;
window.pdCheckout     = pdCheckout;
window.pdDonateButton = pdDonateButton;
