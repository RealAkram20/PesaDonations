<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

/**
 * Who may do what.
 *
 *   pd_view_dashboard    open the staff dashboard
 *   pd_manage_donations  donations, donors, recording a donation, the CSV export
 *   *_pd_campaigns       campaigns (the post type has its own capabilities)
 *   manage_options       settings and system status stay with administrators
 *
 * Anyone who can manage_options is granted every PesaDonations capability at
 * check time, so administrators (and custom admin roles) never depend on the
 * stored role having been updated.
 */
class Roles {

	public const MANAGER_ROLE = 'pd_donations_manager';

	public const CAMPAIGN_CAPS = [
		'edit_pd_campaigns',
		'edit_others_pd_campaigns',
		'edit_published_pd_campaigns',
		'edit_private_pd_campaigns',
		'publish_pd_campaigns',
		'read_private_pd_campaigns',
		'delete_pd_campaigns',
		'delete_others_pd_campaigns',
		'delete_published_pd_campaigns',
		'delete_private_pd_campaigns',
	];

	public const PLUGIN_CAPS = [ 'pd_view_dashboard', 'pd_manage_donations' ];

	public function register(): void {
		add_filter( 'user_has_cap', [ $this, 'grant_to_administrators' ], 10, 1 );
	}

	/** @param array<string, bool> $allcaps */
	public function grant_to_administrators( array $allcaps ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( self::all_caps() as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	/** @return string[] */
	public static function all_caps(): array {
		return array_merge( self::PLUGIN_CAPS, self::CAMPAIGN_CAPS );
	}

	/** Creates or refreshes the Donations Manager role. Safe to run on every install/upgrade. */
	public static function install(): void {
		$caps = [ 'read' => true, 'upload_files' => true ];
		foreach ( self::all_caps() as $cap ) {
			$caps[ $cap ] = true;
		}

		$role = get_role( self::MANAGER_ROLE );
		if ( ! $role ) {
			add_role( self::MANAGER_ROLE, __( 'Donations Manager', 'pesa-donations' ), $caps );
		} else {
			foreach ( $caps as $cap => $grant ) {
				$role->add_cap( $cap, $grant );
			}
		}

		// Stored on the administrator role too, so role editors show it; the filter above is what counts.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		// Editors could edit campaigns before campaigns had their own capabilities
		// (1.1.0 used the "post" ones); they keep that, and nothing more.
		$editor = get_role( 'editor' );
		if ( $editor ) {
			foreach ( self::CAMPAIGN_CAPS as $cap ) {
				$editor->add_cap( $cap );
			}
		}
	}

	public static function uninstall(): void {
		remove_role( self::MANAGER_ROLE );
		foreach ( [ 'administrator', 'editor' ] as $name ) {
			$role = get_role( $name );
			if ( $role ) {
				foreach ( self::all_caps() as $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}
