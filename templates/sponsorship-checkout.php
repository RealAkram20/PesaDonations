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

/** @var \PesaDonations\Models\Campaign $campaign */

$is_sponsorship_type = 'child' === $campaign->get_category();
$plans               = $campaign->get_sponsorship_plans();
$has_plans           = ! empty( $plans ) && $is_sponsorship_type;
$currency            = $campaign->get_base_currency();
$referrals           = (array) get_option( 'pd_referral_sources', [] );
$nonce               = wp_create_nonce( 'pd_public_nonce' );
$checkout_id         = esc_attr( 'pd-checkout-' . $campaign->get_id() );

$config = wp_json_encode( [
	'campaignId'   => $campaign->get_id(),
	'currency'     => $currency,
	'plans'        => $has_plans ? $plans : [],
	'hasPlans'     => $has_plans,
	'minAmount'    => $campaign->get_minimum_amount(),
	'requireAddr'  => $campaign->checkout_requires_address(),
	'nonce'        => $nonce,
	'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
	'thankYouUrl'  => get_permalink( (int) get_option( 'pd_thank_you_page_id' ) ) ?: '',
] );
?>

<div class="pd-checkout" id="<?php echo $checkout_id; ?>"
     x-data="pdCheckout(<?php echo esc_attr( $config ); ?>)"
     x-init="init()">

	<?php
	$is_sponsorship = 'child' === $campaign->get_category();
	$hero_title     = $is_sponsorship
		? ( $campaign->get_beneficiary_name() ?: $campaign->get_title() )
		: $campaign->get_title();
	$hero_label     = $is_sponsorship
		? __( 'Thank you for choosing to sponsor:', 'pesa-donations' )
		: __( 'You are donating to:', 'pesa-donations' );
	?>

	<?php /* ---- Hero ---------------------------------------------------- */ ?>
	<div class="pd-checkout__hero">
		<p class="pd-checkout__thank-note"><?php echo esc_html( $hero_label ); ?></p>

		<div class="pd-checkout__beneficiary">
			<?php if ( $campaign->get_thumbnail_url() ) : ?>
				<img src="<?php echo esc_url( $campaign->get_thumbnail_url( 'thumbnail' ) ); ?>"
				     alt="<?php echo esc_attr( $hero_title ); ?>"
				     class="pd-checkout__photo<?php echo $is_sponsorship ? '' : ' pd-checkout__photo--square'; ?>" />
			<?php endif; ?>

			<div class="pd-checkout__beneficiary-meta">
				<h2 class="pd-checkout__beneficiary-name">
					<?php echo esc_html( $hero_title ); ?>
				</h2>
				<?php if ( $campaign->get_beneficiary_location() ) : ?>
					<p class="pd-checkout__location"><?php echo esc_html( $campaign->get_beneficiary_location() ); ?></p>
				<?php endif; ?>
				<?php if ( $campaign->get_content() ) : ?>
					<a href="#pd-story-inline" class="pd-checkout__story-link" @click.prevent="toggleStory()">
						<?php
						echo esc_html(
							$is_sponsorship && $campaign->get_beneficiary_name()
								? $campaign->get_beneficiary_name() . '\'s story'
								: __( 'Learn more', 'pesa-donations' )
						);
						?>
					</a>
				<?php endif; ?>
			</div>
		</div>

		<?php /* inline story toggle */ ?>
		<div class="pd-checkout__story-body" id="pd-story-inline" x-show="storyOpen" style="display:none;">
			<div class="pd-brand-bar"></div>
			<div class="pd-prose">
				<?php echo wp_kses_post( $campaign->get_content() ); ?>
			</div>
			<?php if ( $campaign->get_beneficiary_code() ) : ?>
				<p class="pd-modal__code"><?php echo esc_html( $campaign->get_beneficiary_code() ); ?></p>
			<?php endif; ?>
			<div class="pd-brand-bar"></div>
		</div>
	</div>

	<?php /* ---- Plan Selector with Slider -------------------------------- */ ?>
	<?php if ( $has_plans && $is_sponsorship ) : ?>
		<div class="pd-checkout__section pd-checkout__section--plans">
			<h3 class="pd-checkout__section-title">
				<?php esc_html_e( 'Choose your contribution', 'pesa-donations' ); ?>
				<span class="pd-info-tip" title="<?php esc_attr_e( 'Your recurring monthly gift', 'pesa-donations' ); ?>">&#9432;</span>
			</h3>

			<div class="pd-amount-display">
				<span class="pd-amount-display__value"
				      x-text="formatAmount(formData.amount) + ' ' + currency"></span>
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
				<?php foreach ( $plans as $plan ) :
					$plan_currency = ! empty( $plan['currency'] ) ? strtoupper( $plan['currency'] ) : $currency;
					$plan_payload  = array_merge( $plan, [ 'currency' => $plan_currency ] );
					$label         = number_format( (float) $plan['amount'] ) . ' ' . $plan_currency;
					if ( ! empty( $plan['name'] ) ) {
						$label .= ' (' . $plan['name'] . ')';
					}
				?>
					<button type="button"
					        class="pd-plan-btn"
					        :class="{ 'pd-plan-btn--active': !customAmountOpen && selectedPlan && selectedPlan.name === '<?php echo esc_js( $plan['name'] ); ?>' }"
					        @click="selectPlan(<?php echo esc_attr( wp_json_encode( $plan_payload ) ); ?>)">
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
				<label for="pd-custom-amount" class="pd-label">
					<?php esc_html_e( 'Enter your amount', 'pesa-donations' ); ?>
				</label>
				<div class="pd-input-group">
					<span class="pd-input-group__prefix"><?php echo esc_html( $currency ); ?></span>
					<input type="number" id="pd-custom-amount"
					       x-model.number="formData.amount"
					       @input="onCustomChange()"
					       :min="minAmount"
					       step="<?php echo esc_attr( $currency === 'UGX' ? '1000' : '1' ); ?>"
					       class="pd-input" />
				</div>
			</div>
		</div>
	<?php else : ?>
		<div class="pd-checkout__section pd-checkout__section--amount">
			<h3 class="pd-checkout__section-title"><?php esc_html_e( 'Donation Amount', 'pesa-donations' ); ?></h3>
			<?php if ( $campaign->get_suggested_amounts() ) : ?>
				<div class="pd-amount-buttons">
					<?php foreach ( $campaign->get_suggested_amounts() as $sug ) : ?>
						<button type="button"
						        class="pd-amount-btn"
						        :class="{ 'pd-amount-btn--active': formData.amount == <?php echo esc_js( $sug['amount'] ); ?> }"
						        @click="formData.amount = <?php echo esc_js( $sug['amount'] ); ?>">
							<?php echo esc_html( number_format( (float) $sug['amount'] ) . ' ' . ( $sug['currency'] ?? $currency ) ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="pd-amount-custom">
				<label for="pd-amount" class="pd-label"><?php esc_html_e( 'Or enter amount', 'pesa-donations' ); ?></label>
				<div class="pd-input-group">
					<span class="pd-input-group__prefix"><?php echo esc_html( $currency ); ?></span>
					<input type="number" id="pd-amount" name="amount" x-model="formData.amount"
					       min="<?php echo esc_attr( $campaign->get_minimum_amount() ); ?>"
					       step="100" class="pd-input" />
				</div>
				<p class="pd-error-msg" x-show="errors.amount" x-text="errors.amount"></p>
			</div>
		</div>
	<?php endif; ?>

	<div class="pd-brand-bar"></div>

	<?php /* ---- Contact Information -------------------------------------- */ ?>
	<div class="pd-checkout__section">
		<h3 class="pd-checkout__section-title"><?php esc_html_e( 'Contact Information', 'pesa-donations' ); ?></h3>

		<div class="pd-checkout__toggle-org">
			<label class="pd-toggle">
				<input type="checkbox" x-model="isOrg" />
				<span class="pd-toggle__slider"></span>
			</label>
			<span class="pd-toggle__label"><?php esc_html_e( 'This is an organization or group', 'pesa-donations' ); ?></span>
		</div>

		<div class="pd-form-row pd-form-row--2col">
			<div class="pd-form-field">
				<label class="pd-label"><?php esc_html_e( 'First Name', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
				<input type="text" x-model="formData.first_name" class="pd-input"
				       :class="{ 'pd-input--error': errors.first_name }"
				       autocomplete="given-name" />
				<p class="pd-error-msg" x-show="errors.first_name" x-text="errors.first_name"></p>
			</div>
			<div class="pd-form-field">
				<label class="pd-label"><?php esc_html_e( 'Last Name', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
				<input type="text" x-model="formData.last_name" class="pd-input"
				       :class="{ 'pd-input--error': errors.last_name }"
				       autocomplete="family-name" />
				<p class="pd-error-msg" x-show="errors.last_name" x-text="errors.last_name"></p>
			</div>
		</div>

		<div class="pd-form-field">
			<label class="pd-label"><?php esc_html_e( 'Email Address', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
			<input type="email" x-model="formData.email" class="pd-input"
			       :class="{ 'pd-input--error': errors.email }"
			       autocomplete="email" />
			<p class="pd-error-msg" x-show="errors.email" x-text="errors.email"></p>
		</div>

		<div class="pd-form-field">
			<label class="pd-label"><?php esc_html_e( 'Confirm Email Address', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
			<input type="email" x-model="formData.confirm_email" class="pd-input"
			       :class="{ 'pd-input--error': errors.confirm_email }" />
			<p class="pd-error-msg" x-show="errors.confirm_email" x-text="errors.confirm_email"></p>
		</div>

		<div class="pd-form-field">
			<label class="pd-label"><?php esc_html_e( 'Phone Number', 'pesa-donations' ); ?></label>
			<input type="tel" x-model="formData.phone" class="pd-input" autocomplete="tel" />
		</div>
	</div>

	<?php /* ---- Mailing Address ----------------------------------------- */ ?>
	<?php if ( $campaign->checkout_requires_address() ) : ?>
		<div class="pd-checkout__section">
			<h3 class="pd-checkout__section-title"><?php esc_html_e( 'Mailing Address', 'pesa-donations' ); ?></h3>

			<div class="pd-form-field">
				<label class="pd-label"><?php esc_html_e( 'Country', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
				<select x-model="formData.country" class="pd-input pd-input--select"
				        :class="{ 'pd-input--error': errors.country }">
					<option value=""><?php esc_html_e( 'Select a country', 'pesa-donations' ); ?></option>
					<?php foreach ( pd_get_countries() as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="pd-error-msg" x-show="errors.country" x-text="errors.country"></p>
			</div>

			<div class="pd-form-row pd-form-row--2col">
				<div class="pd-form-field">
					<label class="pd-label"><?php esc_html_e( 'Address 1', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
					<input type="text" x-model="formData.address1" class="pd-input"
					       :class="{ 'pd-input--error': errors.address1 }"
					       autocomplete="address-line1" />
					<p class="pd-error-msg" x-show="errors.address1" x-text="errors.address1"></p>
				</div>
				<div class="pd-form-field">
					<label class="pd-label"><?php esc_html_e( 'Address 2', 'pesa-donations' ); ?></label>
					<input type="text" x-model="formData.address2" class="pd-input" autocomplete="address-line2" />
				</div>
			</div>

			<div class="pd-form-row pd-form-row--2col">
				<div class="pd-form-field">
					<label class="pd-label"><?php esc_html_e( 'City', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
					<input type="text" x-model="formData.city" class="pd-input"
					       :class="{ 'pd-input--error': errors.city }"
					       autocomplete="address-level2" />
					<p class="pd-error-msg" x-show="errors.city" x-text="errors.city"></p>
				</div>
				<div class="pd-form-field">
					<label class="pd-label"><?php esc_html_e( 'State / Province', 'pesa-donations' ); ?></label>
					<input type="text" x-model="formData.state" class="pd-input" autocomplete="address-level1" />
				</div>
			</div>

			<div class="pd-form-field">
				<label class="pd-label"><?php esc_html_e( 'Zip / Postal Code', 'pesa-donations' ); ?> <span class="pd-required">*</span></label>
				<input type="text" x-model="formData.zip" class="pd-input"
				       :class="{ 'pd-input--error': errors.zip }"
				       autocomplete="postal-code" />
				<p class="pd-error-msg" x-show="errors.zip" x-text="errors.zip"></p>
			</div>

			<div class="pd-form-field pd-form-field--checkbox">
				<label>
					<input type="checkbox" x-model="formData.billing_same" />
					<?php esc_html_e( 'Billing address same as mailing address', 'pesa-donations' ); ?>
				</label>
			</div>
		</div>
	<?php endif; ?>

	<?php /* ---- Additional Notes --------------------------------------- */ ?>
	<div class="pd-checkout__section">
		<h3 class="pd-checkout__section-title"><?php esc_html_e( 'Additional Notes', 'pesa-donations' ); ?></h3>

		<?php if ( $referrals ) : ?>
			<div class="pd-form-field">
				<label class="pd-label"><?php esc_html_e( 'How did you hear about us?', 'pesa-donations' ); ?></label>
				<select x-model="formData.how_heard" class="pd-input pd-input--select">
					<option value=""><?php esc_html_e( 'Select one', 'pesa-donations' ); ?></option>
					<?php foreach ( $referrals as $source ) : ?>
						<option value="<?php echo esc_attr( $source ); ?>"><?php echo esc_html( $source ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<div class="pd-form-field">
			<label class="pd-label"><?php esc_html_e( 'Additional Notes or Comments', 'pesa-donations' ); ?></label>
			<textarea x-model="formData.notes" class="pd-input pd-input--textarea" rows="4"></textarea>
		</div>

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
				$terms_url    = get_privacy_policy_url();
				$terms_label  = __( "I understand and agree to %s's Terms & Conditions for Donation Payments", 'pesa-donations' );
				$site_name    = get_bloginfo( 'name' );
				if ( $terms_url ) {
					printf(
						wp_kses(
							sprintf( $terms_label, '<a href="%s" target="_blank">%s</a>' ),
							[ 'a' => [ 'href' => [], 'target' => [] ] ]
						),
						esc_url( $terms_url ),
						esc_html( $site_name )
					);
				} else {
					printf( esc_html( $terms_label ), esc_html( $site_name ) );
				}
				?>
			</label>
			<p class="pd-error-msg" x-show="errors.agree_terms" x-text="errors.agree_terms"></p>
		</div>
	</div>

	<?php /* ---- Error / Submit ------------------------------------------ */ ?>
	<div class="pd-checkout__submit">
		<p class="pd-error-msg pd-error-msg--global" x-show="globalError" x-text="globalError"></p>

		<button type="button"
		        class="pd-btn pd-btn--primary pd-btn--lg pd-btn--full"
		        @click="submit()"
		        :disabled="loading">
			<span x-show="!loading"><?php esc_html_e( 'Continue to Payment', 'pesa-donations' ); ?></span>
			<span x-show="loading"><?php esc_html_e( 'Please wait…', 'pesa-donations' ); ?></span>
		</button>
	</div>

	<?php /* ---- Payment Iframe Modal --------------------------------- */ ?>
	<div class="pd-iframe-overlay"
	     x-show="iframeOpen"
	     x-cloak
	     x-transition.opacity
	     style="display:none;">

		<div class="pd-iframe-modal" role="dialog" aria-modal="true">
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

<?php
// Helper available globally after template is loaded.
function pd_get_countries(): array {
	return [
		'UG' => 'Uganda',   'KE' => 'Kenya',    'TZ' => 'Tanzania',
		'RW' => 'Rwanda',   'SS' => 'South Sudan','BI' => 'Burundi',
		'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada',
		'AU' => 'Australia', 'DE' => 'Germany',  'NL' => 'Netherlands',
		'ZA' => 'South Africa', 'NG' => 'Nigeria', 'GH' => 'Ghana',
	];
}
