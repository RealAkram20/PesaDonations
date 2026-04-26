/**
 * PesaDonations Alpine.js components.
 * Requires Alpine.js v3 (assets/js/alpine.min.js).
 */

/* =========================================================================
   Donation Gallery Lightbox — used on the single donation page
   (templates/single-donation.php). Self-contained: takes a gallery array
   and provides keyboard-navigable, full-screen image preview with
   prev/next, thumb strip, and counter — same UX as the sponsor card
   modal's lightbox, but without the surrounding details modal.
   Usage: x-data="pdDonationGallery(galleryJson)"
   =========================================================================*/
function pdDonationGallery(galleryJson) {
	return {
		gallery:       [],
		lightboxOpen:  false,
		lightboxIndex: 0,

		init() {
			try {
				this.gallery = typeof galleryJson === 'string'
					? JSON.parse(galleryJson)
					: (Array.isArray(galleryJson) ? galleryJson : []);
			} catch (e) {
				this.gallery = [];
			}

			// Global keyboard nav while lightbox is open.
			document.addEventListener('keydown', (e) => {
				if (!this.lightboxOpen) return;
				if (e.key === 'Escape')     { this.closeLightbox(); }
				else if (e.key === 'ArrowRight') { this.lightboxNext(); }
				else if (e.key === 'ArrowLeft')  { this.lightboxPrev(); }
			});
		},

		openLightbox(index) {
			if (!this.gallery.length) return;
			this.lightboxIndex = Math.max(0, Math.min(index, this.gallery.length - 1));
			this.lightboxOpen  = true;
			document.body.style.overflow = 'hidden';
			this.scrollActiveThumbIntoView();
		},
		closeLightbox() {
			this.lightboxOpen = false;
			document.body.style.overflow = '';
		},
		lightboxNext() {
			if (!this.gallery.length) return;
			this.lightboxIndex = (this.lightboxIndex + 1) % this.gallery.length;
			this.scrollActiveThumbIntoView();
		},
		lightboxPrev() {
			if (!this.gallery.length) return;
			this.lightboxIndex = (this.lightboxIndex - 1 + this.gallery.length) % this.gallery.length;
			this.scrollActiveThumbIntoView();
		},
		scrollActiveThumbIntoView() {
			this.$nextTick(() => {
				const strip = this.$refs.strip;
				if (!strip) return;
				const active = strip.querySelector('.pd-lightbox__thumb--active');
				if (active && active.scrollIntoView) {
					active.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
				}
			});
		},
		get lightboxImage() {
			const item = this.gallery[this.lightboxIndex];
			return item ? item.full : '';
		},
		get lightboxTotal() { return this.gallery.length; },
	};
}

/* =========================================================================
   Campaign List — unified component for sponsorships AND projects.
   Handles: details modal, gallery lightbox.
   Usage: x-data="pdCampaignList(jsonData)"
   =========================================================================*/
function pdCampaignList(campaignsJson) {
	return {
		campaigns:    [],
		modalOpen:    false,
		active:       null,

		// Lightbox state
		lightboxOpen:   false,
		lightboxIndex:  0,

		init() {
			try {
				this.campaigns = typeof campaignsJson === 'string'
					? JSON.parse(campaignsJson)
					: campaignsJson;
			} catch (e) {
				this.campaigns = [];
			}
		},

		openDetails(data) {
			try {
				this.active = typeof data === 'string' ? JSON.parse(data) : data;
			} catch (e) {
				this.active = data;
			}
			this.modalOpen = true;
			document.body.style.overflow = 'hidden';
		},

		closeModal() {
			this.modalOpen = false;
			this.active    = null;
			document.body.style.overflow = '';
		},

		// --- Gallery lightbox ---
		openLightbox(index) {
			if (!this.active || !this.active.gallery) return;
			this.lightboxIndex = index;
			this.lightboxOpen  = true;
		},
		closeLightbox() {
			this.lightboxOpen = false;
		},
		lightboxNext() {
			if (!this.active || !this.active.gallery || !this.active.gallery.length) return;
			this.lightboxIndex = (this.lightboxIndex + 1) % this.active.gallery.length;
			this.scrollActiveThumbIntoView();
		},
		lightboxPrev() {
			if (!this.active || !this.active.gallery || !this.active.gallery.length) return;
			this.lightboxIndex = (this.lightboxIndex - 1 + this.active.gallery.length) % this.active.gallery.length;
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
		get lightboxImage() {
			if (!this.active || !this.active.gallery || !this.active.gallery[this.lightboxIndex]) return '';
			return this.active.gallery[this.lightboxIndex].full;
		},
		get lightboxTotal() {
			return (this.active && this.active.gallery) ? this.active.gallery.length : 0;
		},
	};
}

// Alias for backward compat with any pages still using the old name.
function pdSponsorshipList(campaignsJson) {
	return pdCampaignList(campaignsJson);
}

/* =========================================================================
   Browse Page — sidebar filters, toolbar, grid/list, pagination.
   Standalone: embeds the same details-modal + lightbox logic as pdCampaignList
   (duplicated inline so Alpine picks up reactive getters — Object.assign
   flattens getters into data properties, breaking reactivity).
   Usage: x-data="pdBrowse(campaignsJson, configJson)"
   =========================================================================*/
function pdBrowse(campaignsJson, configJson) {
	const cfg = typeof configJson === 'string' ? JSON.parse(configJson) : configJson;

	return {
		/* ---- Campaign data (from pdCampaignList) --------------------- */
		campaigns:    [],
		modalOpen:    false,
		active:       null,
		lightboxOpen:   false,
		lightboxIndex:  0,

		/* ---- Browse config + state ----------------------------------- */
		type:         cfg.type || 'project',
		columns:      cfg.columns || 3,
		i18n:         cfg.i18n || {},
		view:         'grid',
		sort:         'default',
		perPage:      cfg.perPage || 12,
		page:         1,
		filtersOpen:  false,  // mobile drawer state
		filters: {
			search:    '',
			ageRange:  [],
			status:    [],
			goalRange: [],
		},

		/* ---- Init ---------------------------------------------------- */
		init() {
			try {
				this.campaigns = typeof campaignsJson === 'string'
					? JSON.parse(campaignsJson)
					: campaignsJson;
			} catch (e) {
				this.campaigns = [];
			}
		},

		/* ---- Computed (reactive) ------------------------------------ */
		get isGridView() { return this.view === 'grid'; },
		get isListView() { return this.view === 'list'; },
		get showGrid()   { return this.paginated.length > 0 && this.view === 'grid'; },
		get showList()   { return this.paginated.length > 0 && this.view === 'list'; },
		get showEmpty()  { return this.paginated.length === 0; },
		get showPaginator() { return this.totalPages > 1; },

		get hasActiveFilters() {
			return this.filters.search.trim() !== ''
				|| this.filters.ageRange.length > 0
				|| this.filters.status.length > 0
				|| this.filters.goalRange.length > 0;
		},

		get activeFilterCount() {
			let n = 0;
			if (this.filters.search.trim() !== '') n++;
			n += this.filters.ageRange.length;
			n += this.filters.status.length;
			n += this.filters.goalRange.length;
			return n;
		},

		get sortLabel() {
			const labels = {
				'default':       'Default',
				'recent':        'Recently Added',
				'name_asc':      'Name: A → Z',
				'name_desc':     'Name: Z → A',
				'age_asc':       'Age: Young → Old',
				'age_desc':      'Age: Old → Young',
				'progress_desc': 'Progress: High → Low',
				'progress_asc':  'Progress: Low → High',
				'goal_desc':     'Goal: High → Low',
				'goal_asc':      'Goal: Low → High',
			};
			return labels[this.sort] || 'Default';
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
					const isFunded   = c.progress >= 100;
					const wantAvail  = f.status.includes('available');
					const wantFunded = f.status.includes('funded');
					if (wantAvail && !wantFunded && isFunded)   return false;
					if (wantFunded && !wantAvail && !isFunded)  return false;
				}
				if (f.goalRange.length && this.type === 'project') {
					if (!f.goalRange.some(r => this.goalInRange(c.goal, r))) return false;
				}
				return true;
			});

			return this.applySort(list);
		},

		get paginated() {
			const start = (this.page - 1) * this.perPage;
			return this.filtered.slice(start, start + this.perPage);
		},

		get totalPages() {
			return Math.max(1, Math.ceil(this.filtered.length / this.perPage));
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
			const nameOf = c => (c.beneficiary || c.title || '').toLowerCase();
			const ageOf  = c => (c.age === '' ? 999 : parseInt(c.age, 10));
			const goalOf = c => parseFloat(c.goal) || 0;
			const progOf = c => parseFloat(c.progress) || 0;

			switch (this.sort) {
				case 'name_asc':      copy.sort((a,b) => nameOf(a).localeCompare(nameOf(b))); break;
				case 'name_desc':     copy.sort((a,b) => nameOf(b).localeCompare(nameOf(a))); break;
				case 'age_asc':       copy.sort((a,b) => ageOf(a) - ageOf(b)); break;
				case 'age_desc':      copy.sort((a,b) => ageOf(b) - ageOf(a)); break;
				case 'progress_desc': copy.sort((a,b) => progOf(b) - progOf(a)); break;
				case 'progress_asc':  copy.sort((a,b) => progOf(a) - progOf(b)); break;
				case 'goal_desc':     copy.sort((a,b) => goalOf(b) - goalOf(a)); break;
				case 'goal_asc':      copy.sort((a,b) => goalOf(a) - goalOf(b)); break;
				case 'recent':        copy.sort((a,b) => b.id - a.id); break;
			}
			return copy;
		},

		resetFilters() {
			this.filters = { search: '', ageRange: [], status: [], goalRange: [] };
			this.page    = 1;
		},

		setView(grid) {
			this.view = grid ? 'grid' : 'list';
		},

		/* ---- Details modal (same as pdCampaignList) ----------------- */
		openDetails(data) {
			try {
				this.active = typeof data === 'string' ? JSON.parse(data) : data;
			} catch (e) {
				this.active = data;
			}
			this.modalOpen = true;
			document.body.style.overflow = 'hidden';
		},
		closeModal() {
			this.modalOpen = false;
			this.active    = null;
			document.body.style.overflow = '';
		},

		/* ---- Lightbox ----------------------------------------------- */
		openLightbox(index) {
			if (!this.active || !this.active.gallery) return;
			this.lightboxIndex = index;
			this.lightboxOpen  = true;
		},
		closeLightbox() {
			this.lightboxOpen = false;
		},
		lightboxNext() {
			if (!this.active || !this.active.gallery || !this.active.gallery.length) return;
			this.lightboxIndex = (this.lightboxIndex + 1) % this.active.gallery.length;
			this.scrollActiveThumbIntoView();
		},
		lightboxPrev() {
			if (!this.active || !this.active.gallery || !this.active.gallery.length) return;
			this.lightboxIndex = (this.lightboxIndex - 1 + this.active.gallery.length) % this.active.gallery.length;
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
		get lightboxImage() {
			if (!this.active || !this.active.gallery || !this.active.gallery[this.lightboxIndex]) return '';
			return this.active.gallery[this.lightboxIndex].full;
		},
		get lightboxTotal() {
			return (this.active && this.active.gallery) ? this.active.gallery.length : 0;
		},
	};
}

window.pdBrowse = pdBrowse;

/* =========================================================================
   Slider — horizontal carousel with prev/next arrows, optional autoplay.
   Uses native CSS scroll-snap for smooth scrolling and touch/swipe support.
   Also includes the full details-modal + gallery-lightbox methods so the
   "View Details" button on each card works inside the slider scope.
   =========================================================================*/
function pdSlider(configJson, campaignsJson) {
	const cfg = typeof configJson === 'string' ? JSON.parse(configJson) : (configJson || {});

	return {
		/* ---- SLIDER state (cards carousel) ------------------------- */
		sliderOn:         !!cfg.autoplay,       // renamed from `autoplay` — "is slider autoplaying?"
		sliderInterval:   cfg.interval || 4500,
		sliderTimer:      null,
		sliderPaused:     false,

		/* ---- Details modal state ---------------------------------- */
		campaigns:    [],
		modalOpen:    false,
		active:       null,

		/* ---- LIGHTBOX state (gallery viewer) ---------------------- */
		lightboxOpen:     false,
		lightboxIndex:    0,

		init() {
			try {
				this.campaigns = typeof campaignsJson === 'string'
					? JSON.parse(campaignsJson)
					: (campaignsJson || []);
			} catch (e) { this.campaigns = []; }
			if (this.sliderOn) this.startSlider();
		},

		/* ---- Slider navigation ------------------------------------- */
		slideDistance() {
			const track = this.$refs.track;
			if (!track) return 0;
			const slide = track.querySelector('.pd-slider__slide');
			if (!slide) return track.clientWidth;
			const style = getComputedStyle(track);
			const gap   = parseInt(style.gap || style.columnGap || '20', 10) || 0;
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
				if (!this.sliderPaused) this.next();
			}, this.sliderInterval);
		},

		stopSlider() {
			if (this.sliderTimer) {
				clearInterval(this.sliderTimer);
				this.sliderTimer = null;
			}
		},

		pauseAutoplay()  { this.sliderPaused = true; },   // keeps name for slider wrapper @mouseenter
		resumeAutoplay() { this.sliderPaused = false; },  // keeps name for slider wrapper @mouseleave
		resetSlider()    { if (this.sliderOn) this.startSlider(); },

		/* ---- Details modal ----------------------------------------- */
		openDetails(data) {
			try { this.active = typeof data === 'string' ? JSON.parse(data) : data; }
			catch (e) { this.active = data; }
			this.modalOpen = true;
			document.body.style.overflow = 'hidden';
			this.pauseAutoplay();
		},
		closeModal() {
			this.modalOpen = false;
			this.active    = null;
			document.body.style.overflow = '';
			this.resumeAutoplay();
		},

		/* ---- Gallery lightbox -------------------------------------- */
		openLightbox(index) {
			if (!this.active || !this.active.gallery) return;
			this.lightboxIndex = index;
			this.lightboxOpen  = true;
		},
		closeLightbox() {
			this.lightboxOpen = false;
		},
		lightboxNext() {
			if (!this.active || !this.active.gallery || !this.active.gallery.length) return;
			this.lightboxIndex = (this.lightboxIndex + 1) % this.active.gallery.length;
			this.scrollActiveThumbIntoView();
		},
		lightboxPrev() {
			if (!this.active || !this.active.gallery || !this.active.gallery.length) return;
			this.lightboxIndex = (this.lightboxIndex - 1 + this.active.gallery.length) % this.active.gallery.length;
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
		get lightboxImage() {
			if (!this.active || !this.active.gallery || !this.active.gallery[this.lightboxIndex]) return '';
			return this.active.gallery[this.lightboxIndex].full;
		},
		get lightboxTotal() {
			return (this.active && this.active.gallery) ? this.active.gallery.length : 0;
		},
	};
}

window.pdSlider = pdSlider;

/* =========================================================================
   Checkout Form
   =========================================================================
   Usage: x-data="pdCheckout(configJson)"
*/
function pdCheckout(configJson) {
	const config = typeof configJson === 'string' ? JSON.parse(configJson) : configJson;

	return {
		// Config
		campaignId:    config.campaignId || 0,
		currency:      config.currency   || 'UGX',
		plans:         config.plans      || [],
		hasPlans:      config.hasPlans   || false,
		minAmount:     config.minAmount  || 0,
		requireAddr:   config.requireAddr || false,
		nonce:         config.nonce      || '',
		ajaxUrl:       config.ajaxUrl    || '',
		thankYouUrl:   config.thankYouUrl || '',

		// State
		selectedPlan:       null,
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
		donorRecognized:    false,
		donorLookupInFlight: false,

		formData: {
			amount:        '',
			first_name:    '',
			last_name:     '',
			email:         '',
			confirm_email: '',
			phone:         '',
			country:       '',
			org_name:      '',
			address1:      '',
			address2:      '',
			city:          '',
			state:         '',
			zip:           '',
			billing_same:  true,
			how_heard:     '',
			notes:         '',
			updates:       false,
			agree_terms:   false,
		},

		init() {
			if (this.plans.length > 0) {
				// Seed slider range from defined plan amounts.
				const amounts = this.plans.map(p => parseFloat(p.amount)).filter(n => !isNaN(n));
				const min = Math.min(...amounts);
				const max = Math.max(...amounts);

				if (min === max) {
					// Single plan: let user slide between ±50% around it.
					this.sliderMin = Math.max(this.minAmount || 0, Math.round(min * 0.5));
					this.sliderMax = Math.round(min * 2);
				} else {
					this.sliderMin = min;
					this.sliderMax = max;
				}

				this.sliderStep = this.stepForCurrency(this.currency);
				this.selectedPlan   = this.plans[0];
				this.formData.amount = this.selectedPlan.amount;
			}
		},

		stepForCurrency(code) {
			const steps = { UGX: 1000, KES: 50, TZS: 500, USD: 1, EUR: 1, GBP: 1 };
			return steps[code] || 1;
		},

		formatAmount(n) {
			if (n === '' || n === null || isNaN(n)) return '0';
			return Number(n).toLocaleString();
		},

		get currentPlanName() {
			if (this.customAmountOpen) return '';
			const match = this.plans.find(p => parseFloat(p.amount) === parseFloat(this.formData.amount));
			return match ? (match.name || '') : '';
		},

		selectPlan(plan) {
			this.selectedPlan     = plan;
			this.customAmountOpen = false;
			this.formData.amount  = plan.amount;
		},

		onSliderChange() {
			this.customAmountOpen = false;
			const match = this.plans.find(p => parseFloat(p.amount) === parseFloat(this.formData.amount));
			this.selectedPlan = match || null;
		},

		onCustomChange() {
			this.selectedPlan = null;
		},

		toggleCustom() {
			this.customAmountOpen = !this.customAmountOpen;
			if (this.customAmountOpen) {
				this.selectedPlan = null;
				// Start custom amount at current slider value.
			}
		},

		toggleStory() {
			this.storyOpen = !this.storyOpen;
		},

		async lookupDonor() {
			const email = (this.formData.email || '').trim();
			const phone = (this.formData.phone || '').trim();
			if ((!email || !email.includes('@')) && !phone) return;
			if (this.donorLookupInFlight) return;
			this.donorLookupInFlight = true;

			try {
				const body = new FormData();
				body.append('action', 'pd_lookup_donor');
				body.append('nonce', this.nonce);
				if (email) body.append('email', email);
				if (phone) body.append('phone', phone);

				const res  = await fetch(this.ajaxUrl, { method: 'POST', body });
				const data = await res.json();
				if (data.success && data.data) {
					const d = data.data;
					// Only fill empty fields — don't overwrite what the user typed.
					if (!this.formData.first_name && d.first_name) this.formData.first_name = d.first_name;
					if (!this.formData.last_name  && d.last_name)  this.formData.last_name  = d.last_name;
					if (!this.formData.phone      && d.phone)      this.formData.phone      = d.phone;
					if (!this.formData.email      && d.email)      { this.formData.email = d.email; this.formData.confirm_email = d.email; }
					if (!this.formData.confirm_email && this.formData.email) this.formData.confirm_email = this.formData.email;
					if (!this.formData.country    && d.country)    this.formData.country    = d.country;
					this.donorRecognized = true;
					setTimeout(() => { this.donorRecognized = false; }, 5000);
				}
			} catch (e) {
				/* silent — lookup is a nicety, not critical */
			} finally {
				this.donorLookupInFlight = false;
			}
		},

		closeIframe() {
			this.iframeOpen = false;
			this.iframeUrl  = '';
			document.body.style.overflow = '';
		},

		validate() {
			const errs = {};

			const amt = parseFloat(this.formData.amount);
			if (!amt || amt < this.minAmount) {
				errs.amount = `Minimum donation is ${Number(this.minAmount).toLocaleString()} ${this.currency}.`;
			}

			if (!this.formData.first_name.trim()) {
				errs.first_name = pdL10n('First name is required.');
			}
			if (!this.formData.last_name.trim()) {
				errs.last_name = pdL10n('Last name is required.');
			}
			if (this.isOrg && !this.formData.org_name.trim()) {
				errs.org_name = pdL10n('Organization name is required.');
			}
			if (!this.formData.email.trim() || !this.formData.email.includes('@')) {
				errs.email = pdL10n('A valid email address is required.');
			}
			if (this.formData.email !== this.formData.confirm_email) {
				errs.confirm_email = pdL10n('Email addresses do not match.');
			}

			if (this.requireAddr) {
				if (!this.formData.country)  errs.country  = pdL10n('Country is required.');
				if (!this.formData.address1) errs.address1 = pdL10n('Address is required.');
				if (!this.formData.city)     errs.city     = pdL10n('City is required.');
				if (!this.formData.zip)      errs.zip      = pdL10n('Zip/Postal code is required.');
			}

			if (!this.formData.agree_terms) {
				errs.agree_terms = pdL10n('You must agree to the terms to continue.');
			}

			this.errors = errs;
			return Object.keys(errs).length === 0;
		},

		async submit() {
			console.log('[PesaDonations] submit() called', { formData: this.formData, selectedPlan: this.selectedPlan });
			this.globalError = '';

			if (!this.validate()) {
				const errorList = Object.values(this.errors).filter(Boolean);
				this.globalError = 'Please fix the errors above: ' + errorList.join(' ');
				console.warn('[PesaDonations] validation failed', this.errors);
				this.$nextTick(() => {
					const el = document.querySelector('.pd-input--error, .pd-error-msg:not(:empty)');
					if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
				});
				return;
			}

			this.loading = true;

			// formData.amount is authoritative: slider, custom input, and plan
			// buttons all write to it.
			const amount   = parseFloat(this.formData.amount) || 0;
			const currency = (this.hasPlans && this.selectedPlan && this.selectedPlan.currency) || this.currency;

			const body = new FormData();
			body.append('action',      'pd_init_donation');
			body.append('nonce',       this.nonce);
			body.append('campaign_id', this.campaignId);
			body.append('amount',      amount);
			body.append('currency',    currency);
			body.append('gateway',     'pesapal');
			body.append('first_name',  this.formData.first_name);
			body.append('last_name',   this.formData.last_name);
			body.append('email',       this.formData.email);
			body.append('phone',       this.formData.phone);
			body.append('country',     this.formData.country);
			body.append('message',     this.formData.notes);
			body.append('updates',     this.formData.updates ? '1' : '0');
			body.append('is_org',      this.isOrg ? '1' : '0');
			body.append('org_name',    this.isOrg ? this.formData.org_name : '');

			try {
				console.log('[PesaDonations] sending AJAX', this.ajaxUrl);
				const res  = await fetch(this.ajaxUrl, { method: 'POST', body });
				const data = await res.json();
				console.log('[PesaDonations] AJAX response', data);

				if (data.success) {
					if (data.data && data.data.redirect_url) {
						console.log('[PesaDonations] opening payment iframe', data.data.redirect_url);
						this.iframeUrl  = data.data.redirect_url;
						this.iframeOpen = true;
						document.body.style.overflow = 'hidden';
					} else if (this.thankYouUrl) {
						window.location.href = this.thankYouUrl;
					}
				} else {
					this.globalError = (data.data && data.data.message)
						|| pdL10n('Something went wrong. Please try again.');
				}
			} catch (err) {
				console.error('[PesaDonations] fetch failed', err);
				this.globalError = pdL10n('Network error. Please check your connection and try again.');
			} finally {
				this.loading = false;
			}
		},
	};
}

/* =========================================================================
   Donate Button (opens checkout via link — no modal needed)
   =========================================================================*/
function pdDonateButton() {
	return {};
}

/* =========================================================================
   Utility: safe L10n fallback
   =========================================================================*/
function pdL10n(str) {
	return (window.pdPublicStrings && window.pdPublicStrings[str]) || str;
}

/* =========================================================================
   Expose on window so Alpine's x-data can find them regardless of
   script load order.
   =========================================================================*/
window.pdCampaignList     = pdCampaignList;
window.pdSponsorshipList  = pdSponsorshipList;
window.pdCheckout         = pdCheckout;
window.pdDonateButton     = pdDonateButton;
window.pdDonationGallery  = pdDonationGallery;

document.addEventListener('alpine:init', () => {
	console.log('[PesaDonations] Alpine init — components ready');
});
