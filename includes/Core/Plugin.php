<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Admin\AdminDashboard;
use WMCyber\Newsletter\Admin\SettingsManager;
use WMCyber\Newsletter\Database\Schema;
use WMCyber\Newsletter\Database\DoiRepository;
use WMCyber\Newsletter\Frontend\EcosystemIntegrations;
use WMCyber\Newsletter\Frontend\FormRenderer;
use WMCyber\Newsletter\Mailer\DoiMailer;
use WMCyber\Newsletter\Queue\SyncQueue;
use WMCyber\Newsletter\REST\SubscriptionController;
use WMCyber\Newsletter\REST\WebhookController;
use WMCyber\Newsletter\Security\RequestShield;
use WMCyber\Newsletter\CLI\NewsletterCommand;

final class Plugin {
	private static ?Plugin $instance = null;
	private DoiRepository $doiRepository;
	private SettingsManager $settingsManager;
	private DoiMailer $doiMailer;
	private SyncQueue $syncQueue;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->doiRepository   = new DoiRepository();
		$this->settingsManager = new SettingsManager();
		$this->doiMailer       = new DoiMailer( $this->settingsManager );
		$this->syncQueue       = new SyncQueue( $this->doiRepository, $this->settingsManager );
	}

	public function boot(): void {
		\WMCyber\Newsletter\Database\Schema::maybe_upgrade();

		// Load textdomain
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// Parameter check on the plugin's own REST routes. Until 2026-09-15 this also created
		// uploads/wm-newsletter/exports with protection files on every request, for an export to disk
		// that does not exist (the CSV export streams to the browser).
		RequestShield::boot();

		// REST API
		add_action( 'rest_api_init', function (): void {
			$subController = new SubscriptionController( $this->doiRepository, $this->doiMailer, $this->syncQueue, $this->settingsManager );
			$subController->register_routes();

			$webhookController = new WebhookController( $this->doiRepository, $this->settingsManager );
			$webhookController->register_routes();
		} );

		// Admin Dashboard
		if ( is_admin() ) {
			$admin = new AdminDashboard( $this->doiRepository, $this->settingsManager, $this->syncQueue );
			$admin->boot();
		}

		// Frontend Shortcodes & Assets
		$frontend = new FormRenderer( $this->settingsManager );
		$frontend->boot();

		// Block Bindings & GenerateBlocks Dynamic Tag
		$ecosystem = new EcosystemIntegrations( $this->doiRepository );
		$ecosystem->boot();

		// Queue Scheduler Hook
		$this->syncQueue->boot();

		// WP-CLI Commands
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wm-newsletter', NewsletterCommand::class );
		}

		do_action( 'wm_newsletter_ready', $this );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wm-dsgvo-newsletter-suite',
			false,
			dirname( WM_NEWSLETTER_PLUGIN_BASENAME ) . '/languages'
		);
	}

	public function getDoiRepository(): DoiRepository {
		return $this->doiRepository;
	}

	public function getSettingsManager(): SettingsManager {
		return $this->settingsManager;
	}

	public function getSyncQueue(): SyncQueue {
		return $this->syncQueue;
	}
}
