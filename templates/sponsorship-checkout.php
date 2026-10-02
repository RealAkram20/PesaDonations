<?php
/**
 * Sponsorship checkout template.
 *
 * Variables available:
 *   $campaign  PesaDonations\Models\Campaign
 *
 * Theme override: /wp-content/themes/{theme}/pesa-donations/sponsorship-checkout.php
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var \PesaDonations\Models\Campaign|null $campaign Null for an open donation (see donation-form.php). */

use PesaDonations\Models\Open_Donation;
use PesaDonations\Payments\Charge_Quote;
use PesaDonations\Utils\Countries;
use PesaDonations\Utils\Currencies;
use PesaDonations\Utils\Exchange_Rates;

$is_open             = null === $campaign;
$is_sponsorship_type = ! $is_open && $campaign->is_sponsorship();
$plans               = $is_open ? [] : $campaign->get_sponsorship_plans();
// Plan tiers have not shown since the category "child" became "sponsorship"
// (this compared against "child"), and every sponsorship saved since carries
// the editor's default tiers. Showing them again changes what donors see, so
// it is opt-in: add_filter( 'pd_checkout_show_plans', '__return_true' ).
$has_plans           = ! empty( $plans ) && $is_sponsorship_type
	&& apply_filters( 'pd_checkout_show_plans', false, $campaign );
$currency            = $is_open ? Open_Donation::currency() : $campaign->get_base_currency();
$min_amount          = $is_open ? Open_Donation::min_amount() : $campaign->get_minimum_amount();
$require_address     = ! $is_open && $campaign->checkout_requires_address();
$allow_anonymous     = ! $is_open && $campaign->allows_anonymous();
$suggested_amounts   = $is_open ? ( $suggested ?? [] ) : $campaign->get_suggested_amounts();
$current_period      = $is_open ? null : $campaign->get_current_period();
$referrals           = array_filter( array_map( 'strval', (array) get_option( 'pd_referral_sources', [] ) ) );
$checkout_id         = 'pd-checkout-' . ( $is_open ? 'open' : $campaign->get_id() );
$field_id            = static fn( string $name ): string => esc_attr( $checkout_id . '-' . $name );

// Currencies the donor may give in here (only those that can be charged and counted today).
$switchable = ! $has_plans && ( $is_open ? Currencies::choice_enabled() : $campaign->allows_currency_switch() );
$choices    = Charge_Quote::choices( $currency, $switchable, $has_plans ? array_column( $plans, 'currency' ) : [] );
$rate_codes = array_unique( array_merge( $choices, Currencies::chargeable(), [ $currency ] ) );
$decimals   = [];
foreach ( $rate_codes as $code ) {
	$decimals[ $code ] = Currencies::decimals( $code );
}

$config = [
	'campaignId'     => $is_open ? Open_Donation::CAMPAIGN_ID : $campaign->get_id(),
	'currency'       => $currency,
	'choices'        => $choices,
	'chargeable'     => Currencies::chargeable(),
	'local'          => Currencies::local(),
	'rates'          => Exchange_Rates::for_codes( $rate_codes ),
	'ratesDate'      => Exchange_Rates::updated_at() ? wp_date( 'j M Y', Exchange_Rates::updated_at() ) : '',
	'decimals'       => $decimals,
	'suggested'      => array_values( array_map( static fn( array $s ): float => (float) $s['amount'], $suggested_amounts ) ),
	'plans'          => $has_plans ? $plans : [],
	'hasPlans'       => $has_plans,
	'minAmount'      => $min_amount,
	'requireAddr'    => $require_address,
	'nonce'          => wp_create_nonce( 'pd_public_nonce' ),
	'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
	'thankYouUrl'    => get_permalink( (int) get_option( 'pd_thank_you_page_id' ) ) ?: '',
	'i18n'           => [
		/* translators: %s: minimum amount with currency, e.g. "5,000 UGX" */
		'minimum'    => __( 'Minimum donation is %s.', 'pesa-donations' ),
		'amount'     => __( 'Enter the amount as a number, for example 50000.', 'pesa-donations' ),
		'firstName'  => __( 'First name is required.', 'pesa-donations' ),
		'lastName'   => __( 'Last name is required.', 'pesa-donations' ),
		'email'      => __( 'A valid email address is required.', 'pesa-donations' ),
		'emailMatch' => __( 'Email addresses do not match.', 'pesa-donations' ),
		'country'    => __( 'Country is required.', 'pesa-donations' ),
		'address'    => __( 'Address is required.', 'pesa-donations' ),
		'city'       => __( 'City is required.', 'pesa-donations' ),
		'zip'        => __( 'Zip/Postal code is required.', 'pesa-donations' ),
		'terms'      => __( 'You must agree to the terms to continue.', 'pesa-donations' ),
		'fix'        => __( 'Please check the highlighted fields.', 'pesa-donations' ),
		'generic'    => __( 'Something went wrong. Please try again.', 'pesa-donations' ),
		'network'    => __( 'Network error. Please check your connection and try again.', 'pesa-donations' ),
		/* translators: %s: amount with currency */
		'about'      => __( 'about %s', 'pesa-donations' ),
		/* translators: 1: amount with currency, 2: exchange rate such as "1 EUR = 4,147 UGX", 3: date */
		'charged'    => __( 'You will be charged %1$s (%2$s, rate of %3$s).', 'pesa-donations' ),
		'noRate'     => __( 'Today\'s exchange rate is not available for this currency. Please choose another.', 'pesa-donations' ),
	],
];

/** The "you will be charged" line for a converted currency, with the rate source's credit. */
$conversion_line = static function (): void {
	?>
	<p class="pd-amount-convert" x-show="conversionText" x-cloak style="display:none;" aria-live="polite">
		<span x-text="conversionText"></span>
		<a href="<?php echo esc_url( Exchange_Rates::CREDIT_URL ); ?>" target="_blank" rel="noopener">Rates By Exchange Rate API</a>
	</p>
	<?php
};
?>

<div class="pd-checkout" id="<?php echo esc_attr( $checkout_id ); ?>"
     x-data="pdCheckout(<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>)">

	<?php $is_sponsorship = $is_sponsorship_type; ?>

	<?php if ( $is_open ) : ?>
	<div class="pd-checkout__hero pd-checkout__hero--open">
		<h2 class="pd-checkout__open-title"><?php echo esc_html( $open_title ?? Open_Donation::title() ); ?></h2>
		<?php if ( ! empty( $open_intro ) ) : ?>
			<p class="pd-checkout__open-intro"><?php echo esc_html( $open_intro ); ?></p>
		<?php endif; ?>
	</div>
	<?php else : ?>

	<?php
	$hero_title     = $is_sponsorship
		? ( $campaign->get_beneficiary_name() ?: $campaign->get_title() )
		: $campaign->get_title();

	// Split name into first + rest (e.g. "Robinah Nabuti" → "Robinah" + "Nabuti")
	// so we can render them at different weights for an editorial feel.
	$name_parts  = preg_split( '/\s+/', trim( $hero_title ), 2 );
	$name_first  = $name_parts[0] ?? '';
	$name_rest   = $name_parts[1] ?? '';
	$story_label = $is_sponsorship && $name_first
		/* translators: %s: beneficiary's first name */
		? sprintf( __( "%s's story", 'pesa-donations' ), $name_first )
		: __( 'Read the full story', 'pesa-donations' );
	$story       = $campaign->get_content();
	?>

	<?php /* ---- Hero ---------------------------------------------------- */ ?>
	<div class="pd-checkout__hero">

		<div class="pd-checkout__beneficiary">
			<?php if ( $campaign->get_thumbnail_url( 'medium_large' ) ) : ?>
				<div class="pd-checkout__photo-frame">
					<img src="<?php echo esc_url( $campaign->get_thumbnail_url( 'medium_large' ) ); ?>"
					     alt="<?php echo esc_attr( $hero_title ); ?>"
					     class="pd-checkout__photo" />
				</div>
			<?php endif; ?>

			<div class="pd-checkout__beneficiary-meta">
				<h2 class="pd-checkout__beneficiary-name">
					<span class="pd-checkout__beneficiary-name--first"><?php echo esc_html( $name_first ); ?></span>
					<?php if ( $name_rest ) : ?>
						<span class="pd-checkout__beneficiary-name--rest"><?php echo esc_html( $name_rest ); ?></span>
					<?php endif; ?>
				</h2>

				<?php if ( $campaign->get_beneficiary_location() ) : ?>
					<p class="pd-checkout__location"><?php echo esc_html( $campaign->get_beneficiary_location() ); ?></p>
				<?php endif; ?>

				<?php if ( $current_period ) : ?>
					<p class="pd-card__period pd-checkout__period">
						<span>
							<?php esc_html_e( 'Giving toward', 'pesa-donations' ); ?>
							<span class="pd-card__period-label"><?php echo esc_html( $current_period->get_label() ); ?></span>
						</span>
						<span><?php echo esc_html( $campaign->get_period_note() ); ?></span>
					</p>
				<?php endif; ?>

				<?php if ( '' !== trim( $story ) ) : ?>
					<a href="#<?php echo $field_id( 'story' ); ?>" class="pd-checkout__story-link"
					   @click.prevent="toggleStory()" :aria-expanded="storyOpen ? 'true' : 'false'"
					   aria-controls="<?php echo $field_id( 'story' ); ?>">
						<span class="pd-checkout__story-icon" aria-hidden="true">
							<svg viewBox="0 0 20 20" width="18" height="18" fill="currentColor">
								<path d="M3 2.5A1.5 1.5 0 0 1 4.5 1h11A1.5 1.5 0 0 1 17 2.5v14.75a.25.25 0 0 1-.4.2L10 12l-6.6 5.45a.25.25 0 0 1-.4-.2V2.5z"/>
							</svg>
						</span>
						<span><?php echo esc_html( $story_label ); ?></span>
					</a>
				<?php endif; ?>
			</div>
		</div>

		<?php /* inline story toggle — expands below the hero row when clicked */ ?>
		<div class="pd-checkout__story-body" id="<?php echo $field_id( 'story' ); ?>" x-show="storyOpen" x-cloak style="display:none;">
			<div class="pd-brand-bar"></div>
			<div class="pd-prose">
				<?php
				// The_content output, as on the campaign's own page. wp_kses_post()
				// here removed video embeds an editor had placed in the story.
				echo $story; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
			<?php if ( $campaign->get_beneficiary_code() ) : ?>
				<p class="pd-modal__code"><?php echo esc_html( $campaign->get_beneficiary_code() ); ?></p>
			<?php endif; ?>
			<div class="pd-brand-bar"></div>
		</div>
	</div>
	<?php endif; /* $is_open */ ?>

	<?php /* ---- Plan Selector with Slider -------------------------------- */ ?>
	<?php if ( $has_plans ) : ?>
		<div class="pd-checkout__section pd-checkout__section--plans">
			<h3 class="pd-checkout__section-title">
				<?php esc_html_e( 'Choose your contribution', 'pesa-donations' ); ?>
				<span class="pd-info-tip" title="<?php esc_attr_e( 'Your recurring monthly gift', 'pesa-donations' ); ?>">&#9432;</span>
			</h3>

			<div class="pd-amount-display" aria-live="polite">
				<span class="pd-amount-display__value"
				      x-text="formatAmount(formData.amount) + ' ' + amountCurrency"></span>
				<span class="pd-amount-display__plan-name"
				      x-show="currentPlanName"
				      x-text="'(' + currentPlanName + ')'"></span>
			</div>

			<div class="pd-slider">
				<input type="range"
				       :min="sliderMin"
				       :max="sliderMax"
				       :step="sliderStep"
				       x-model.number="formData.amount"
				       @input="onSliderChange()"
				       class="pd-slider__input"
				       aria-label="<?php esc_attr_e( 'Sponsorship amount', 'pesa-donations' ); ?>" />
				<div class="pd-slider__labels">
					<span x-text="formatAmount(sliderMin)"></span>
					<span x-text="formatAmount(sliderMax)"></span>
				</div>
			</div>

			<div class="pd-plan-buttons">
				<?php foreach ( $plans as $i => $plan ) :
					$label = number_format( (float) $plan['amount'] ) . ' ' . $plan['currency'];
					if ( '' !== $plan['name'] ) {
						$label .= ' (' . $plan['name'] . ')';
					}
				?>
					<button type="button"
					        class="pd-plan-btn"
					        :class="{ 'pd-plan-btn--active': isPlanActive(<?php echo (int) $i; ?>) }"
					        @click="selectPlan(<?php echo (int) $i; ?>)">
						<?php echo esc_html( $label ); ?>
					</button>
				<?php endforeach; ?>
				<button type="button"
				        class="pd-plan-btn pd-plan-btn--custom"
				        :class="{ 'pd-plan-btn--active': customAmountOpen }"
				        @click="toggleCustom()">
					<?php esc_html_e( 'Custom Amount', 'pesa-donations' ); ?>
				</button>
			</div>

			<div class="pd-amount-custom" x-show="customAmountOpen" x-cloak style="display:none;margin-top:12px;">
				<label for="<?php echo $field_id( 'custom-amount' ); ?>" class="pd-label">
					<?php esc_html_e( 'Enter your amount', 'pesa-donations' ); ?>
				</label>
				<div class="pd-input-group">
					<span class="pd-input-group__prefix" x-text="amountCurrency"><?php echo esc_html( $currency ); ?></span>
					<input type="text" inputmode="decimal" id="<?php echo $field_id( 'custom-amount' ); ?>"
					       x-model="formData.amount"
					       @input="onCustomChange()"
					       :class="{ 'pd-input--error': errors.amount }"
					       class="pd-input" />
				</div>
				<p class="pd-error-msg" x-show="errors.amount" x-text="errors.amount"></p>
			</div>
			<?php $conversion_line(); ?>
		</div>
	<?php else : ?>
		<div class="pd-checkout__section pd-checkout__section--amount">
			<h3 class="pd-checkout__section-title"><?php esc_html_e( 'Donation Amount', 'pesa-donations' ); ?></h3>

			<?php if ( count( $choices ) > 1 ) : ?>
				<div class="pd-form-field pd-currency-field">
					<label class="pd-label" for="<?php echo $field_id( 'currency' ); ?>"><?php esc_html_e( 'Currency', 'pesa-donations' ); ?></label>
					<select id="<?php echo $field_id( 'currency' ); ?>" class="pd-input pd-input--select" x-model="currency" @change="onCurrencyChange()" autocomplete="transaction-currency">
						<?php
						$grouped = [];
						foreach ( Currencies::groups() as $group => $codes ) {
							$in = array_values( array_intersect( $codes, $choices ) );
							if ( $in ) {
								$grouped[ $group ] = $in;
							}
						}
						foreach ( $grouped as $group => $codes ) :
							?>
							<optgroup label="<?php echo esc_attr( $group ); ?>">
								<?php foreach ( $codes as $code ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $currency, $code ); ?>><?php echo esc_html( $code . ' — ' . Currencies::name( $code ) ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>

			<?php if ( $suggested_amounts ) : ?>
				<div class="pd-amount-buttons" x-show="quickPicks.length">
					<?php /* Rendered in the chosen currency; the first paint shows the campaign's own amounts. */ ?>
					<template x-for="a in quickPicks" :key="currency + a">
						<button type="button"
						        class="pd-amount-btn"
						        :class="{ 'pd-amount-btn--active': isAmount(a) }"
						        @click="setAmount(a)"
						        x-text="pickLabel(a)"></button>
					</template>
				</div>
			<?php endif; ?>
			<div class="pd-amount-custom">
				<label for="<?php echo $field_id( 'amount' ); ?>" class="pd-label">
					<?php $suggested_amounts ? esc_html_e( 'Or enter amount', 'pesa-donations' ) : esc_html_e( 'Enter amount', 'pesa-donations' ); ?>
				</label>
				<div class="pd-input-group">
					<span class="pd-input-group__prefix" x-text="currency"><?php echo esc_html( $currency ); ?></span>
					<input type="text" inputmode="decimal" id="<?php echo $field_id( 'amount' ); ?>" x-model="formData.amount"
					       x-ref="amountInput"
					       :class="{ 'pd-input--error': errors.amount }"
					       class="pd-input" />
				</div>
				<p class="pd-error-msg" x-show="errors.amount" x-text="errors.amount"></p>
				<?php $conversion_line(); ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="pd-brand-bar"></div>

	<?php /* ---- Contact Information -------------------------------------- */ ?>
	<div class="pd-checkout__section">
		<h3 class="pd-checkout__section-title"><?php esc_html_e( 'Contact Information', 'pesa-donations' ); ?></h3>

		<div class="pd-checkout__toggle-org">
			<label class="pd-toggle">
				<input type="checkbox" x-model="isOrg" aria-labelledby="<?php echo $field_id( 'org-label' ); ?>" />
				<span class="pd-toggle__slider"></span>
			</label>
			<span class="pd-toggle__label" id="<?php echo $field_id( 'org-label' ); ?>" @click="isOrg = !isOrg"><?php esc_html_e( 'This is an organization or group', 'pesa-donations' ); ?></span>
		</div>

		<div class="pd-form-row pd-form-row--2col">
			<div class="pd-form-field">
				<label class="pd-label" for="<?php echo $field_id( 'first-name' ); ?>"><?php esc_html_e( 'First Name', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
				<input type="text" id="<?php echo $field_id( 'first-name' ); ?>" x-model="formData.first_name" class="pd-input"
				       :class="{ 'pd-input--error': errors.first_name }"
				       autocomplete="given-name" />
				<p class="pd-error-msg" x-show="errors.first_name" x-text="errors.first_name"></p>
			</div>
			<div class="pd-form-field">
				<label class="pd-label" for="<?php echo $field_id( 'last-name' ); ?>"><?php esc_html_e( 'Last Name', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
				<input type="text" id="<?php echo $field_id( 'last-name' ); ?>" x-model="formData.last_name" class="pd-input"
				       :class="{ 'pd-input--error': errors.last_name }"
				       autocomplete="family-name" />
				<p class="pd-error-msg" x-show="errors.last_name" x-text="errors.last_name"></p>
			</div>
		</div>

		<div class="pd-form-field">
			<label class="pd-label" for="<?php echo $field_id( 'email' ); ?>"><?php esc_html_e( 'Email Address', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
			<input type="email" id="<?php echo $field_id( 'email' ); ?>" x-model="formData.email" class="pd-input"
			       :class="{ 'pd-input--error': errors.email }"
			       autocomplete="email" />
			<p class="pd-error-msg" x-show="errors.email" x-text="errors.email"></p>
		</div>

		<div class="pd-form-field">
			<label class="pd-label" for="<?php echo $field_id( 'confirm-email' ); ?>"><?php esc_html_e( 'Confirm Email Address', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
			<input type="email" id="<?php echo $field_id( 'confirm-email' ); ?>" x-model="formData.confirm_email" class="pd-input"
			       :class="{ 'pd-input--error': errors.confirm_email }"
			       autocomplete="email" />
			<p class="pd-error-msg" x-show="errors.confirm_email" x-text="errors.confirm_email"></p>
		</div>

		<div class="pd-form-field">
			<label class="pd-label" for="<?php echo $field_id( 'phone' ); ?>"><?php esc_html_e( 'Phone Number', 'pesa-donations' ); ?></label>
			<input type="tel" id="<?php echo $field_id( 'phone' ); ?>" x-model="formData.phone" class="pd-input" autocomplete="tel" />
		</div>
	</div>

	<?php /* ---- Mailing Address ----------------------------------------- */ ?>
	<?php if ( $require_address ) : ?>
		<div class="pd-checkout__section">
			<h3 class="pd-checkout__section-title"><?php esc_html_e( 'Mailing Address', 'pesa-donations' ); ?></h3>

			<div class="pd-form-field">
				<label class="pd-label" for="<?php echo $field_id( 'country' ); ?>"><?php esc_html_e( 'Country', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
				<select id="<?php echo $field_id( 'country' ); ?>" x-model="formData.country" class="pd-input pd-input--select"
				        :class="{ 'pd-input--error': errors.country }" autocomplete="country">
					<option value=""><?php esc_html_e( 'Select a country', 'pesa-donations' ); ?></option>
					<?php foreach ( Countries::all() as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="pd-error-msg" x-show="errors.country" x-text="errors.country"></p>
			</div>

			<div class="pd-form-row pd-form-row--2col">
				<div class="pd-form-field">
					<label class="pd-label" for="<?php echo $field_id( 'address1' ); ?>"><?php esc_html_e( 'Address 1', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
					<input type="text" id="<?php echo $field_id( 'address1' ); ?>" x-model="formData.address1" class="pd-input"
					       :class="{ 'pd-input--error': errors.address1 }"
					       autocomplete="address-line1" />
					<p class="pd-error-msg" x-show="errors.address1" x-text="errors.address1"></p>
				</div>
				<div class="pd-form-field">
					<label class="pd-label" for="<?php echo $field_id( 'address2' ); ?>"><?php esc_html_e( 'Address 2', 'pesa-donations' ); ?></label>
					<input type="text" id="<?php echo $field_id( 'address2' ); ?>" x-model="formData.address2" class="pd-input" autocomplete="address-line2" />
				</div>
			</div>

			<div class="pd-form-row pd-form-row--2col">
				<div class="pd-form-field">
					<label class="pd-label" for="<?php echo $field_id( 'city' ); ?>"><?php esc_html_e( 'City', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
					<input type="text" id="<?php echo $field_id( 'city' ); ?>" x-model="formData.city" class="pd-input"
					       :class="{ 'pd-input--error': errors.city }"
					       autocomplete="address-level2" />
					<p class="pd-error-msg" x-show="errors.city" x-text="errors.city"></p>
				</div>
				<div class="pd-form-field">
					<label class="pd-label" for="<?php echo $field_id( 'state' ); ?>"><?php esc_html_e( 'State / Province', 'pesa-donations' ); ?></label>
					<input type="text" id="<?php echo $field_id( 'state' ); ?>" x-model="formData.state" class="pd-input" autocomplete="address-level1" />
				</div>
			</div>

			<div class="pd-form-field">
				<label class="pd-label" for="<?php echo $field_id( 'zip' ); ?>"><?php esc_html_e( 'Zip / Postal Code', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
				<input type="text" id="<?php echo $field_id( 'zip' ); ?>" x-model="formData.zip" class="pd-input"
				       :class="{ 'pd-input--error': errors.zip }"
				       autocomplete="postal-code" />
				<p class="pd-error-msg" x-show="errors.zip" x-text="errors.zip"></p>
			</div>
		</div>
	<?php endif; ?>

	<?php /* ---- Additional Notes --------------------------------------- */ ?>
	<div class="pd-checkout__section">
		<h3 class="pd-checkout__section-title"><?php esc_html_e( 'Additional Notes', 'pesa-donations' ); ?></h3>

		<?php if ( $referrals ) : ?>
			<div class="pd-form-field">
				<label class="pd-label" for="<?php echo $field_id( 'how-heard' ); ?>"><?php esc_html_e( 'How did you hear about us?', 'pesa-donations' ); ?></label>
				<select id="<?php echo $field_id( 'how-heard' ); ?>" x-model="formData.how_heard" class="pd-input pd-input--select">
					<option value=""><?php esc_html_e( 'Select one', 'pesa-donations' ); ?></option>
					<?php foreach ( $referrals as $source ) : ?>
						<option value="<?php echo esc_attr( $source ); ?>"><?php echo esc_html( $source ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<div class="pd-form-field">
			<label class="pd-label" for="<?php echo $field_id( 'notes' ); ?>"><?php esc_html_e( 'Additional Notes or Comments', 'pesa-donations' ); ?></label>
			<textarea id="<?php echo $field_id( 'notes' ); ?>" x-model="formData.notes" class="pd-input pd-input--textarea" rows="4" maxlength="2000"></textarea>
		</div>

		<?php if ( $allow_anonymous ) : ?>
			<div class="pd-form-field pd-form-field--checkbox">
				<label>
					<input type="checkbox" x-model="formData.anonymous" />
					<?php esc_html_e( 'Give anonymously', 'pesa-donations' ); ?>
				</label>
			</div>
		<?php endif; ?>

		<div class="pd-form-field pd-form-field--checkbox">
			<label>
				<input type="checkbox" x-model="formData.updates" />
				<?php
				printf(
					/* translators: %s: site name */
					esc_html__( 'Sign up to receive updates from %s', 'pesa-donations' ),
					esc_html( get_bloginfo( 'name' ) )
				);
				?>
			</label>
		</div>

		<div class="pd-form-field pd-form-field--checkbox">
			<label>
				<input type="checkbox" x-model="formData.agree_terms"
				       :class="{ 'pd-input--error': errors.agree_terms }" />
				<?php
				$terms_url   = (string) get_option( 'pd_terms_url', '' );
				if ( ! $terms_url ) {
					$terms_url = (string) get_privacy_policy_url();
				}
				$site_name      = get_bloginfo( 'name' );
				$terms_link_txt = __( 'Terms & Conditions for Donation Payments', 'pesa-donations' );

				if ( $terms_url ) {
					printf(
						/* translators: 1: site name, 2: anchor link to T&C */
						wp_kses(
							__( "I understand and agree to %1\$s's %2\$s", 'pesa-donations' ),
							[]
						),
						esc_html( $site_name ),
						sprintf(
							'<a href="%1$s" target="_blank" rel="noopener" class="pd-terms-link">%2$s</a>',
							esc_url( $terms_url ),
							esc_html( $terms_link_txt )
						)
					);
				} else {
					printf(
						/* translators: 1: site name, 2: "Terms & Conditions for Donation Payments" */
						esc_html__( "I understand and agree to %1\$s's %2\$s", 'pesa-donations' ),
						esc_html( $site_name ),
						esc_html( $terms_link_txt )
					);
				}
				?>
			</label>
			<p class="pd-error-msg" x-show="errors.agree_terms" x-text="errors.agree_terms"></p>
		</div>
	</div>

	<?php /* ---- Error / Submit ------------------------------------------ */ ?>
	<div class="pd-checkout__submit">
		<p class="pd-error-msg pd-error-msg--global" role="alert" x-show="globalError" x-text="globalError"></p>

		<button type="button"
		        class="pd-btn pd-btn--primary pd-btn--lg pd-btn--full"
		        @click="submit()"
		        :disabled="loading"
		        :aria-busy="loading ? 'true' : 'false'">
			<span x-show="!loading"><?php esc_html_e( 'Continue to Payment', 'pesa-donations' ); ?></span>
			<span x-show="loading" x-cloak style="display:none;"><?php esc_html_e( 'Please wait…', 'pesa-donations' ); ?></span>
		</button>
	</div>

	<?php /* ---- Payment Iframe Modal --------------------------------- */ ?>
	<div class="pd-iframe-overlay"
	     x-show="iframeOpen"
	     x-cloak
	     x-transition.opacity
	     style="display:none;">

		<div class="pd-iframe-modal" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Secure payment', 'pesa-donations' ); ?>">
			<button type="button"
			        class="pd-iframe-modal__close"
			        @click="closeIframe()"
			        aria-label="<?php esc_attr_e( 'Close payment window', 'pesa-donations' ); ?>">
				&times;
			</button>

			<iframe :src="iframeUrl"
			        class="pd-iframe-modal__frame"
			        title="<?php esc_attr_e( 'Secure payment', 'pesa-donations' ); ?>"
			        allow="payment"
			        x-show="iframeUrl"></iframe>
		</div>
	</div>

</div><!-- /.pd-checkout -->
