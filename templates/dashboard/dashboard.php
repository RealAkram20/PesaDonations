<?php
/**
 * Staff dashboard page: a whole document, outside the theme and wp-admin.
 * Rendered by PesaDonations\Modules\Dashboard\Dashboard::render().
 *
 * Variables: $payload (array, JSON for pd-dashboard.js), $accent (array{accent, strong}).
 * Every value from the database reaches the DOM through x-text, never innerHTML.
 */
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }

$css_ver = (string) filemtime( PD_PLUGIN_DIR . 'assets/css/pd-dashboard.css' );
$js_ver  = (string) filemtime( PD_PLUGIN_DIR . 'assets/js/pd-dashboard.js' );
$site    = get_bloginfo( 'name' );

/** Icon from the sprite below (Lucide, ISC licence). */
$icon = static function ( string $name, string $class = 'pdd-icon' ): string {
	return '<svg class="' . esc_attr( $class ) . '" aria-hidden="true"><use href="#i-' . esc_attr( $name ) . '"/></svg>';
};
?><!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_user_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Donations · %s', 'pesa-donations' ), $site ) ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( PD_PLUGIN_URL . 'assets/css/pd-dashboard.css?ver=' . $css_ver ); ?>">
<style>:root{--accent:<?php echo esc_html( $accent['accent'] ); ?>;--accent-strong:<?php echo esc_html( $accent['strong'] ); ?>;}</style>
<script>
/* Theme before first paint, so a dark-mode user never sees a white flash. */
try { var t = localStorage.getItem('pdd-theme'); if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t); } catch (e) {}
</script>
</head>
<body class="pdd">

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
	<defs>
		<symbol id="i-heart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></symbol>
		<symbol id="i-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></symbol>
		<symbol id="i-banknote" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></symbol>
		<symbol id="i-download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></symbol>
		<symbol id="i-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></symbol>
		<symbol id="i-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></symbol>
		<symbol id="i-external" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></symbol>
		<symbol id="i-sliders" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4"/></symbol>
		<symbol id="i-logout" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></symbol>
		<symbol id="i-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></symbol>
		<symbol id="i-down" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m7 7 10 10"/><path d="M17 7v10H7"/></symbol>
		<symbol id="i-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></symbol>
		<symbol id="i-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></symbol>
		<symbol id="i-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></symbol>
		<symbol id="i-calendar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></symbol>
		<symbol id="i-mail" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></symbol>
		<symbol id="i-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></symbol>
		<symbol id="i-table" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18M3 15h18M12 3v18"/></symbol>
		<symbol id="i-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></symbol>
		<symbol id="i-loader" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.22-8.56"/></symbol>
	</defs>
</svg>

<noscript><p style="padding:24px"><?php esc_html_e( 'The dashboard needs JavaScript.', 'pesa-donations' ); ?></p></noscript>

<div class="pdd-app" x-data="pdDashboard" x-cloak>

	<header class="pdd-top">
		<div class="pdd-top__in">
			<a class="pdd-brand" :href="p.links.site">
				<span class="pdd-brand__mark"><?php echo $icon( 'heart' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<span class="pdd-brand__text">
					<span class="pdd-brand__site"><?php echo esc_html( $site ); ?></span>
					<span class="pdd-brand__name"><?php esc_html_e( 'Donations', 'pesa-donations' ); ?></span>
				</span>
			</a>

			<nav class="pdd-top__actions" aria-label="<?php esc_attr_e( 'Quick actions', 'pesa-donations' ); ?>">
				<a class="pdd-btn pdd-btn--primary" x-show="p.can.campaigns" :href="p.links.newCampaign" title="<?php esc_attr_e( 'New campaign', 'pesa-donations' ); ?>">
					<?php echo $icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="pdd-btn__text"><?php esc_html_e( 'New campaign', 'pesa-donations' ); ?></span>
				</a>
				<a class="pdd-btn" x-show="p.can.donations" :href="p.links.recordDonation" title="<?php esc_attr_e( 'Record a donation', 'pesa-donations' ); ?>">
					<?php echo $icon( 'banknote' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="pdd-btn__text"><?php esc_html_e( 'Record donation', 'pesa-donations' ); ?></span>
				</a>
				<button type="button" class="pdd-btn pdd-btn--icon" @click="toggleTheme()"
				        :aria-label="dark ? '<?php echo esc_js( __( 'Switch to light mode', 'pesa-donations' ) ); ?>' : '<?php echo esc_js( __( 'Switch to dark mode', 'pesa-donations' ) ); ?>'">
					<svg class="pdd-icon" aria-hidden="true" x-show="!dark"><use href="#i-moon"/></svg>
					<svg class="pdd-icon" aria-hidden="true" x-show="dark"><use href="#i-sun"/></svg>
				</button>
				<div class="pdd-user" @keydown.escape="menu = false" @click.outside="menu = false">
					<button type="button" class="pdd-avatar" @click="menu = !menu" :aria-expanded="menu.toString()"
					        aria-haspopup="true" aria-label="<?php esc_attr_e( 'Account menu', 'pesa-donations' ); ?>" x-text="p.user.initials"></button>
					<div class="pdd-menu" x-show="menu" x-transition.opacity.duration.120ms role="menu">
						<div class="pdd-menu__head"><strong x-text="p.user.name"></strong><?php esc_html_e( 'Signed in', 'pesa-donations' ); ?></div>
						<a :href="p.links.campaigns" role="menuitem" x-show="p.can.campaigns"><?php echo $icon( 'calendar' ); // phpcs:ignore ?><?php esc_html_e( 'Campaigns', 'pesa-donations' ); ?></a>
						<a :href="p.links.donations" role="menuitem" x-show="p.can.donations"><?php echo $icon( 'banknote' ); // phpcs:ignore ?><?php esc_html_e( 'All donations', 'pesa-donations' ); ?></a>
						<a :href="p.links.settings" role="menuitem" x-show="p.can.settings"><?php echo $icon( 'sliders' ); // phpcs:ignore ?><?php esc_html_e( 'Settings', 'pesa-donations' ); ?></a>
						<a :href="p.links.wpAdmin" role="menuitem"><?php echo $icon( 'external' ); // phpcs:ignore ?><?php esc_html_e( 'WordPress admin', 'pesa-donations' ); ?></a>
						<a :href="p.links.logout" role="menuitem"><?php echo $icon( 'logout' ); // phpcs:ignore ?><?php esc_html_e( 'Sign out', 'pesa-donations' ); ?></a>
					</div>
				</div>
			</nav>
		</div>
	</header>

	<main class="pdd-main">

		<div class="pdd-hello">
			<div>
				<h1 x-text="greeting()"></h1>
				<p x-text="todayLine()"></p>
			</div>
		</div>

		<div class="pdd-toolbar">
			<div class="pdd-seg" role="group" aria-label="<?php esc_attr_e( 'Date range', 'pesa-donations' ); ?>">
				<template x-for="r in ranges" :key="r.key">
					<button type="button" :aria-pressed="(range === r.key).toString()" @click="setRange(r.key)" x-text="r.label"></button>
				</template>
			</div>
			<span class="pdd-toolbar__range" x-text="d.label"></span>
			<svg class="pdd-spin" aria-hidden="true" x-show="loading"><use href="#i-loader"/></svg>
			<span class="pdd-sr" role="status" x-text="loading ? '<?php echo esc_js( __( 'Loading', 'pesa-donations' ) ); ?>' : ''"></span>
			<div class="pdd-toolbar__end">
				<a class="pdd-btn" x-show="p.can.donations" :href="exportUrl()" title="<?php esc_attr_e( 'Export these donations as CSV', 'pesa-donations' ); ?>">
					<?php echo $icon( 'download' ); // phpcs:ignore ?><span class="pdd-btn__text"><?php esc_html_e( 'Export CSV', 'pesa-donations' ); ?></span>
				</a>
			</div>
		</div>

		<div class="pdd-alert" x-show="error" role="alert">
			<?php echo $icon( 'alert' ); // phpcs:ignore ?><span x-text="error"></span>
			<button type="button" class="pdd-btn" @click="setRange(range, true)"><?php esc_html_e( 'Try again', 'pesa-donations' ); ?></button>
		</div>

		<div class="pdd-refetch" :class="{ 'is-loading': loading }">

			<!-- Money and trends -->
			<section class="pdd-grid pdd-grid--kpi" aria-label="<?php esc_attr_e( 'Totals', 'pesa-donations' ); ?>">
				<div class="pdd-card pdd-kpi pdd-kpi--hero">
					<span class="pdd-kpi__label"><?php esc_html_e( 'Raised', 'pesa-donations' ); ?></span>
					<span class="pdd-kpi__value" :title="money(d.kpis.raised.value)"><span x-text="compact(d.kpis.raised.value)"></span><span class="pdd-kpi__cur" x-text="d.currency"></span></span>
					<div class="pdd-kpi__foot">
						<template x-if="delta('raised')"><span class="pdd-delta" :class="'pdd-delta--' + delta('raised').tone"><svg class="pdd-icon" aria-hidden="true"><use :href="'#i-' + delta('raised').icon"/></svg><span x-text="delta('raised').text"></span></span></template>
						<span x-text="vsLabel()"></span>
					</div>
				</div>
				<template x-for="k in ['gifts', 'average', 'donors']" :key="k">
					<div class="pdd-card pdd-kpi">
						<span class="pdd-kpi__label" x-text="kpiLabel(k)"></span>
						<span class="pdd-kpi__value" x-text="kpiValue(k)"></span>
						<div class="pdd-kpi__foot">
							<template x-if="delta(k)"><span class="pdd-delta" :class="'pdd-delta--' + delta(k).tone"><svg class="pdd-icon" aria-hidden="true"><use :href="'#i-' + delta(k).icon"/></svg><span x-text="delta(k).text"></span></span></template>
							<span x-text="kpiFoot(k)"></span>
						</div>
					</div>
				</template>
			</section>

			<section class="pdd-grid pdd-grid--wide">
				<div class="pdd-card">
					<div class="pdd-card__head">
						<h2 class="pdd-card__title"><?php esc_html_e( 'Donations over time', 'pesa-donations' ); ?></h2>
						<div class="pdd-card__tools">
							<button type="button" class="pdd-toggle" :aria-pressed="table.toString()" @click="table = !table; $nextTick(() => drawChart())">
								<?php echo $icon( 'table' ); // phpcs:ignore ?><?php esc_html_e( 'Table', 'pesa-donations' ); ?>
							</button>
						</div>
					</div>
					<div class="pdd-legend" x-show="!table && hasSeries()">
						<span><i style="background:var(--series-1)"></i><?php esc_html_e( 'Campaigns', 'pesa-donations' ); ?></span>
						<span><i style="background:var(--series-2)"></i><?php esc_html_e( 'Open donations', 'pesa-donations' ); ?></span>
					</div>
					<div class="pdd-chart" x-ref="chart" x-show="!table && hasSeries()" @mouseleave="tip = null"></div>
					<div class="pdd-tip" x-show="tip" x-cloak :style="tip ? `left:${tip.x}px;top:${tip.y}px` : ''">
						<template x-if="tip">
							<div>
								<div class="pdd-tip__title" x-text="tip.title"></div>
								<div class="pdd-tip__row"><span class="pdd-tip__key" style="background:var(--series-1)"></span><?php esc_html_e( 'Campaigns', 'pesa-donations' ); ?><b x-text="money(tip.campaign)"></b></div>
								<div class="pdd-tip__row"><span class="pdd-tip__key" style="background:var(--series-2)"></span><?php esc_html_e( 'Open donations', 'pesa-donations' ); ?><b x-text="money(tip.open)"></b></div>
								<div class="pdd-tip__row" style="color:var(--ink-3);margin-top:4px"><span x-text="tip.gifts"></span></div>
							</div>
						</template>
					</div>
					<p class="pdd-empty" x-show="!hasSeries()"><?php esc_html_e( 'No donations in this range', 'pesa-donations' ); ?></p>
					<div class="pdd-table-wrap" x-show="table && hasSeries()">
						<table class="pdd-table">
							<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'pesa-donations' ); ?></th><th scope="col" class="r"><?php esc_html_e( 'Campaigns', 'pesa-donations' ); ?></th><th scope="col" class="r"><?php esc_html_e( 'Open', 'pesa-donations' ); ?></th><th scope="col" class="r"><?php esc_html_e( 'Gifts', 'pesa-donations' ); ?></th></tr></thead>
							<tbody>
								<template x-for="b in d.series" :key="b.key">
									<tr><td x-text="b.label"></td><td class="r" x-text="money(b.campaign)"></td><td class="r" x-text="money(b.open)"></td><td class="r" x-text="b.gifts"></td></tr>
								</template>
							</tbody>
						</table>
					</div>
				</div>

				<div class="pdd-card">
					<div class="pdd-card__head">
						<h2 class="pdd-card__title"><?php esc_html_e( 'By campaign', 'pesa-donations' ); ?></h2>
						<a class="pdd-card__tools pdd-link" :href="d.donations_url" x-show="p.can.donations"><?php esc_html_e( 'All', 'pesa-donations' ); ?><?php echo $icon( 'chevron' ); // phpcs:ignore ?></a>
					</div>
					<div class="pdd-bars" x-show="d.by_campaign.length">
						<template x-for="row in d.by_campaign" :key="row.kind + ':' + row.url">
							<div class="pdd-bar" :class="{ 'pdd-bar--open': row.kind === 'open' }">
								<div class="pdd-bar__top">
									<a class="pdd-bar__label" :href="p.can.donations ? row.url : null" x-text="row.label"></a>
									<span class="pdd-bar__value" x-text="money(row.amount)"></span>
								</div>
								<div class="pdd-bar__track"><div class="pdd-bar__fill" :style="`width:${share(row.amount)}%`"></div></div>
							</div>
						</template>
					</div>
					<p class="pdd-empty" x-show="!d.by_campaign.length"><?php esc_html_e( 'Nothing given yet', 'pesa-donations' ); ?></p>
					<p class="pdd-note" x-show="d.other_currencies.length" x-text="otherCurrencies()"></p>
				</div>
			</section>
		</div>

		<!-- Campaign periods -->
		<section class="pdd-card" style="margin-bottom:16px">
			<div class="pdd-card__head">
				<h2 class="pdd-card__title"><?php esc_html_e( 'Campaigns this period', 'pesa-donations' ); ?></h2>
				<span class="pdd-card__sub" x-text="now.campaigns.length"></span>
				<a class="pdd-card__tools pdd-link" :href="p.links.campaigns" x-show="p.can.campaigns"><?php esc_html_e( 'Manage', 'pesa-donations' ); ?><?php echo $icon( 'chevron' ); // phpcs:ignore ?></a>
			</div>
			<div class="pdd-camps" x-show="now.campaigns.length">
				<template x-for="c in now.campaigns" :key="c.id">
					<a class="pdd-camp" :href="c.edit_url || null">
						<div class="pdd-camp__top">
							<div>
								<div class="pdd-camp__title" x-text="c.title"></div>
								<div class="pdd-camp__period" x-text="c.period || c.repeat || '<?php echo esc_js( __( 'One-time campaign', 'pesa-donations' ) ); ?>'"></div>
							</div>
							<template x-if="badge(c)">
								<span class="pdd-badge" :class="'pdd-badge--' + badge(c).tone"><svg class="pdd-icon" aria-hidden="true"><use :href="'#i-' + badge(c).icon"/></svg><span x-text="badge(c).text"></span></span>
							</template>
						</div>
						<template x-if="c.goal">
							<div class="pdd-meter" role="img" :aria-label="meterLabel(c)">
								<div class="pdd-meter__fill" :style="`width:${Math.min(100, c.progress * 100)}%`"></div>
								<template x-if="c.elapsed !== null && c.pace !== 'reached'"><div class="pdd-meter__pace" :style="`left:${c.elapsed * 100}%`"></div></template>
							</div>
						</template>
						<div class="pdd-camp__nums">
							<b x-text="money(c.raised, c.currency)"></b>
							<span x-show="c.goal" x-text="'<?php echo esc_js( __( 'of', 'pesa-donations' ) ); ?> ' + money(c.goal, c.currency)"></span>
						</div>
						<div class="pdd-camp__foot">
							<span x-text="timeLeft(c)"></span>
							<span x-show="c.goal" x-text="Math.round(c.progress * 100) + '%'"></span>
						</div>
					</a>
				</template>
			</div>
			<div class="pdd-empty" x-show="!now.campaigns.length">
				<?php esc_html_e( 'No open campaigns', 'pesa-donations' ); ?>
				<div style="margin-top:12px" x-show="p.can.campaigns"><a class="pdd-btn pdd-btn--primary" :href="p.links.newCampaign"><?php echo $icon( 'plus' ); // phpcs:ignore ?><?php esc_html_e( 'New campaign', 'pesa-donations' ); ?></a></div>
			</div>
		</section>

		<section class="pdd-grid pdd-grid--half">
			<!-- Needs attention -->
			<div class="pdd-card">
				<div class="pdd-card__head"><h2 class="pdd-card__title"><?php esc_html_e( 'Needs attention', 'pesa-donations' ); ?></h2></div>
				<div class="pdd-clear" x-show="!now.attention.length"><?php echo $icon( 'check' ); // phpcs:ignore ?><?php esc_html_e( 'All clear', 'pesa-donations' ); ?></div>
				<div class="pdd-list">
					<template x-for="(item, i) in now.attention" :key="i">
						<div class="pdd-item">
							<span class="pdd-item__icon" :class="'pdd-item__icon--' + item.severity"><svg class="pdd-icon" aria-hidden="true"><use :href="'#i-' + attentionIcon(item)"/></svg></span>
							<div class="pdd-item__body">
								<a class="pdd-item__title" :href="item.url" x-text="item.title"></a>
								<div class="pdd-item__detail" x-text="item.detail"></div>
								<ul class="pdd-mini" x-show="item.rows.length">
									<template x-for="r in item.rows" :key="r.url">
										<li><a :href="r.url" x-text="r.donor || r.reference"></a><span x-text="r.when" style="color:var(--ink-3)"></span><span x-text="money(r.amount, r.currency)"></span></li>
									</template>
								</ul>
							</div>
						</div>
					</template>
				</div>
			</div>

			<!-- Donors -->
			<div class="pdd-card pdd-refetch" :class="{ 'is-loading': loading }">
				<div class="pdd-card__head">
					<h2 class="pdd-card__title"><?php esc_html_e( 'Donors', 'pesa-donations' ); ?></h2>
					<a class="pdd-card__tools pdd-link" :href="p.links.donors" x-show="p.can.donations"><?php esc_html_e( 'All', 'pesa-donations' ); ?><?php echo $icon( 'chevron' ); // phpcs:ignore ?></a>
				</div>
				<div class="pdd-split">
					<div><div class="pdd-split__n" x-text="d.donors.new"></div><div class="pdd-split__l"><i style="background:var(--series-1)"></i><?php esc_html_e( 'New', 'pesa-donations' ); ?></div></div>
					<div><div class="pdd-split__n" x-text="d.donors.returning"></div><div class="pdd-split__l"><i style="background:var(--series-2)"></i><?php esc_html_e( 'Returning', 'pesa-donations' ); ?></div></div>
				</div>
				<div class="pdd-stack" x-show="d.donors.new + d.donors.returning > 0" role="img" :aria-label="`${d.donors.new} new, ${d.donors.returning} returning`">
					<div :style="`flex:${d.donors.new}`"></div><div :style="`flex:${d.donors.returning}`"></div>
				</div>

				<div class="pdd-subhead"><?php esc_html_e( 'Top donors', 'pesa-donations' ); ?></div>
				<p class="pdd-empty" style="padding:8px 0;text-align:left" x-show="!d.donors.top.length"><?php esc_html_e( 'None in this range', 'pesa-donations' ); ?></p>
				<div class="pdd-people">
					<template x-for="(v, i) in d.donors.top" :key="i">
						<div class="pdd-person">
							<span class="pdd-person__av" x-text="initials(v.name)"></span>
							<div class="pdd-person__who">
								<div><span x-text="v.name"></span><span class="pdd-tag" x-show="v.anonymous"><?php esc_html_e( 'anonymous', 'pesa-donations' ); ?></span></div>
								<small x-text="giftsLabel(v.gifts)"></small>
							</div>
							<span class="pdd-person__amt" x-text="money(v.amount)"></span>
						</div>
					</template>
				</div>

				<template x-if="now.lapsed.total">
					<div>
						<div class="pdd-subhead"><?php echo $icon( 'mail' ); // phpcs:ignore ?><span x-text="lapsedTitle()"></span></div>
						<div class="pdd-people">
							<template x-for="(v, i) in now.lapsed.rows" :key="i">
								<div class="pdd-person">
									<span class="pdd-person__av" x-text="initials(v.name)"></span>
									<div class="pdd-person__who">
										<div x-text="v.name"></div>
										<small x-text="v.campaign + ' · ' + v.period"></small>
									</div>
									<a class="pdd-btn pdd-btn--icon" x-show="v.email" :href="'mailto:' + v.email" :aria-label="'<?php echo esc_js( __( 'Email', 'pesa-donations' ) ); ?> ' + v.name"><?php echo $icon( 'mail' ); // phpcs:ignore ?></a>
								</div>
							</template>
						</div>
					</div>
				</template>
			</div>
		</section>
	</main>
</div>

<script type="application/json" id="pd-dashboard-data"><?php
echo wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with every HTML-significant character escaped.
?></script>
<script type="application/json" id="pd-dashboard-i18n"><?php
echo wp_json_encode( [
	'ranges'     => [ '7d' => __( '7 days', 'pesa-donations' ), '30d' => __( '30 days', 'pesa-donations' ), '90d' => __( '90 days', 'pesa-donations' ), '12m' => __( '12 months', 'pesa-donations' ) ],
	'vs'         => [ '7d' => __( 'vs previous 7 days', 'pesa-donations' ), '30d' => __( 'vs previous 30 days', 'pesa-donations' ), '90d' => __( 'vs previous 90 days', 'pesa-donations' ), '12m' => __( 'vs the 12 months before', 'pesa-donations' ) ],
	'morning'    => __( 'Good morning, %s', 'pesa-donations' ),
	'afternoon'  => __( 'Good afternoon, %s', 'pesa-donations' ),
	'evening'    => __( 'Good evening, %s', 'pesa-donations' ),
	'today'      => __( '%s raised today', 'pesa-donations' ),
	'gifts'      => __( 'Donations', 'pesa-donations' ),
	'average'    => __( 'Average gift', 'pesa-donations' ),
	'donors'     => __( 'Donors', 'pesa-donations' ),
	'noGifts'    => __( 'No gifts to average', 'pesa-donations' ),
	'upFromZero' => __( 'Up from 0', 'pesa-donations' ),
	'newDonors'  => __( '%d new', 'pesa-donations' ),
	'gift1'      => __( '1 gift', 'pesa-donations' ),
	'giftN'      => __( '%d gifts', 'pesa-donations' ),
	'behind'     => __( 'Behind', 'pesa-donations' ),
	'onTrack'    => __( 'On track', 'pesa-donations' ),
	'reached'    => __( 'Goal reached', 'pesa-donations' ),
	'upcoming'   => __( 'Upcoming', 'pesa-donations' ),
	'daysLeft1'  => __( 'Last day', 'pesa-donations' ),
	'daysLeftN'  => __( '%d days left', 'pesa-donations' ),
	'startsIn1'  => __( 'Starts tomorrow', 'pesa-donations' ),
	'startsInN'  => __( 'Starts in %d days', 'pesa-donations' ),
	'noEnd'      => __( 'No end date', 'pesa-donations' ),
	'meter'      => __( '%1$s%% raised, %2$s%% of the time gone', 'pesa-donations' ),
	'meterNoPace' => __( '%s%% raised', 'pesa-donations' ),
	'lapsed1'    => __( '1 donor gave last period, not yet this one', 'pesa-donations' ),
	'lapsedN'    => __( '%d donors gave last period, not yet this one', 'pesa-donations' ),
	'alsoIn'     => __( 'Not included above: %s', 'pesa-donations' ),
	'loadError'  => __( 'Could not load that range. Check the connection and try again.', 'pesa-donations' ),
	'giftsIn'    => __( '%s gifts', 'pesa-donations' ),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
?></script>
<script src="<?php echo esc_url( PD_PLUGIN_URL . 'assets/js/pd-dashboard.js?ver=' . $js_ver ); ?>"></script>
<script src="<?php echo esc_url( PD_PLUGIN_URL . 'assets/js/alpine.min.js?ver=3.14.1' ); ?>" defer></script>
</body>
</html>
