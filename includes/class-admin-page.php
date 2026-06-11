<?php
/**
 * Admin page for Bulk SKU Search & Draft.
 */

defined( 'ABSPATH' ) || exit;

class BSSDD_Admin_Page {

	const PAGE_SLUG        = 'bulk-sku-search-draft-dragan';
	const NONCE_SEARCH     = 'bssdd_search_skus';
	const NONCE_DRAFT      = 'bssdd_draft_products';
	const NONCE_SKU        = 'bssdd_update_sku';
	const NONCE_PREFIX_ZERO = 'bssdd_prefix_zero_sku';
	const PER_PAGE         = 50;

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_bssdd_draft_batch', array( $this, 'ajax_draft_batch' ) );
		add_action( 'wp_ajax_bssdd_update_sku', array( $this, 'ajax_update_sku' ) );
		add_action( 'wp_ajax_bssdd_prefix_zero_batch', array( $this, 'ajax_prefix_zero_batch' ) );
	}

	/**
	 * Register WooCommerce submenu page.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Bulk SKU Search Dragan', 'bulk-sku-search-draft-dragan' ),
			__( 'Bulk SKU Search Dragan', 'bulk-sku-search-draft-dragan' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets on plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'bssdd-admin',
			BSSDD_PLUGIN_URL . 'assets/admin.css',
			array(),
			BSSDD_VERSION
		);

		wp_enqueue_script(
			'bssdd-admin',
			BSSDD_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			BSSDD_VERSION,
			true
		);

		wp_localize_script(
			'bssdd-admin',
			'bssddAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE_DRAFT ),
				'skuNonce'        => wp_create_nonce( self::NONCE_SKU ),
				'prefixZeroNonce' => wp_create_nonce( self::NONCE_PREFIX_ZERO ),
				'batchSize'       => (int) BSSDD_BATCH_SIZE,
				'i18n'            => array(
					'confirmDraft'      => __( 'This will set %d published product(s) to draft. Continue?', 'bulk-sku-search-draft-dragan' ),
					'drafting'          => __( 'Setting products to draft…', 'bulk-sku-search-draft-dragan' ),
					'complete'          => __( 'Draft update complete.', 'bulk-sku-search-draft-dragan' ),
					'error'             => __( 'An error occurred. Please try again.', 'bulk-sku-search-draft-dragan' ),
					'editSku'           => __( 'Edit SKU', 'bulk-sku-search-draft-dragan' ),
					'saveSku'           => __( 'Save', 'bulk-sku-search-draft-dragan' ),
					'cancelSku'         => __( 'Cancel', 'bulk-sku-search-draft-dragan' ),
					'savingSku'         => __( 'Saving…', 'bulk-sku-search-draft-dragan' ),
					'skuSaved'          => __( 'SKU saved.', 'bulk-sku-search-draft-dragan' ),
					'skuFailed'         => __( 'Could not save SKU.', 'bulk-sku-search-draft-dragan' ),
					'addLeadingZero'    => __( 'Add 0', 'bulk-sku-search-draft-dragan' ),
					'confirmPrefixZero' => __( 'This will add a leading 0 to %d SKU(s). Continue?', 'bulk-sku-search-draft-dragan' ),
					'prefixingZero'     => __( 'Adding leading 0…', 'bulk-sku-search-draft-dragan' ),
					'prefixZeroComplete' => __( 'Leading 0 update complete.', 'bulk-sku-search-draft-dragan' ),
				),
			)
		);
	}

	/**
	 * AJAX handler: process one batch of product IDs.
	 */
	public function ajax_draft_batch() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'bulk-sku-search-draft-dragan' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE_DRAFT, 'nonce' );

		$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

		$cached = get_transient( BSSDD_TRANSIENT_KEY );
		if ( ! is_array( $cached ) || empty( $cached['draftable_ids'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Search results expired. Please run the search again.', 'bulk-sku-search-draft-dragan' ) ),
				400
			);
		}

		$all_ids = array_map( 'absint', (array) $cached['draftable_ids'] );
		$batch   = array_slice( $all_ids, $offset, BSSDD_BATCH_SIZE );

		if ( empty( $batch ) ) {
			wp_send_json_success(
				array(
					'done'      => true,
					'offset'    => $offset,
					'total'     => count( $all_ids ),
					'succeeded' => 0,
					'failed'    => 0,
					'skipped'   => 0,
					'errors'    => array(),
				)
			);
		}

		$result = BSSDD_Draft_Processor::process_batch( $batch );
		$new_offset = $offset + count( $batch );

		wp_send_json_success(
			array(
				'done'      => $new_offset >= count( $all_ids ),
				'offset'    => $new_offset,
				'total'     => count( $all_ids ),
				'succeeded' => (int) $result['succeeded'],
				'failed'    => (int) $result['failed'],
				'skipped'   => (int) $result['skipped'],
				'errors'    => array_slice( $result['errors'], 0, 10 ),
			)
		);
	}

	/**
	 * AJAX handler: update a product SKU inline.
	 */
	public function ajax_update_sku() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'bulk-sku-search-draft-dragan' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE_SKU, 'nonce' );

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$new_sku    = isset( $_POST['sku'] ) ? wp_unslash( $_POST['sku'] ) : '';

		$result = BSSDD_SKU_Updater::update( $product_id, $new_sku );

		if ( ! $result['success'] ) {
			wp_send_json_error(
				array(
					'message' => $result['message'],
					'sku'     => $result['sku'],
				),
				400
			);
		}

		BSSDD_SKU_Updater::update_cached_results( $product_id, $result['sku'] );

		wp_send_json_success(
			array(
				'message' => $result['message'],
				'sku'     => $result['sku'],
			)
		);
	}

	/**
	 * AJAX handler: add a leading zero to SKUs in batches.
	 */
	public function ajax_prefix_zero_batch() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'bulk-sku-search-draft-dragan' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE_PREFIX_ZERO, 'nonce' );

		$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

		$cached = get_transient( BSSDD_TRANSIENT_KEY );
		if ( ! is_array( $cached ) || empty( $cached['found'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Search results expired. Please run the search again.', 'bulk-sku-search-draft-dragan' ) ),
				400
			);
		}

		$all_ids = array_map( 'absint', (array) ( $cached['prefixable_ids'] ?? array() ) );
		if ( empty( $all_ids ) ) {
			$all_ids = BSSDD_SKU_Updater::get_prefixable_ids( $cached['found'] );
		}

		if ( empty( $all_ids ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No SKUs need a leading zero.', 'bulk-sku-search-draft-dragan' ) ),
				400
			);
		}
		$batch   = array_slice( $all_ids, $offset, BSSDD_BATCH_SIZE );

		if ( empty( $batch ) ) {
			wp_send_json_success(
				array(
					'done'      => true,
					'offset'    => $offset,
					'total'     => count( $all_ids ),
					'succeeded' => 0,
					'failed'    => 0,
					'skipped'   => 0,
					'errors'    => array(),
				)
			);
		}

		$result     = BSSDD_SKU_Updater::process_leading_zero_batch( $batch );
		$new_offset = $offset + count( $batch );

		wp_send_json_success(
			array(
				'done'      => $new_offset >= count( $all_ids ),
				'offset'    => $new_offset,
				'total'     => count( $all_ids ),
				'succeeded' => (int) $result['succeeded'],
				'failed'    => (int) $result['failed'],
				'skipped'   => (int) $result['skipped'],
				'errors'    => array_slice( $result['errors'], 0, 10 ),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'bulk-sku-search-draft-dragan' ) );
		}

		$results = null;
		$error   = null;
		$input   = '';

		if ( isset( $_POST['bssdd_search'] ) ) {
			$input  = sanitize_textarea_field( wp_unslash( $_POST['bssdd_skus'] ?? '' ) );
			$result = $this->handle_search( $input );

			if ( is_wp_error( $result ) ) {
				$error = $result;
			} else {
				$results = $result;
			}
		} else {
			$cached = get_transient( BSSDD_TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				$results = $cached;
				$input   = $cached['input'] ?? '';
			}
		}

		$this->render_search_form( $input, $results, $error );

		if ( $results ) {
			$this->render_results( $results );
		}
	}

	/**
	 * Process search form submission.
	 *
	 * @param string $input Raw textarea input.
	 * @return array|WP_Error
	 */
	private function handle_search( $input ) {
		check_admin_referer( self::NONCE_SEARCH );

		$parsed = BSSDD_SKU_Parser::parse( $input );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$search = BSSDD_SKU_Finder::search( $parsed['skus'] );

		$results = array(
			'input'          => $input,
			'skus'           => $parsed['skus'],
			'found'          => $search['found'],
			'not_found'      => $search['not_found'],
			'summary'        => $search['summary'],
			'draftable_ids'  => BSSDD_SKU_Finder::get_draftable_ids( $search['found'] ),
			'prefixable_ids' => BSSDD_SKU_Updater::get_prefixable_ids( $search['found'] ),
			'searched_at'    => time(),
		);

		set_transient( BSSDD_TRANSIENT_KEY, $results, HOUR_IN_SECONDS );

		return $results;
	}

	/**
	 * Render search form.
	 *
	 * @param string          $input   Textarea value.
	 * @param array|null      $results Search results.
	 * @param WP_Error|null   $error   Error object.
	 */
	private function render_search_form( $input, $results, $error ) {
		?>
		<div class="wrap bssdd-wrap">
			<h1><?php esc_html_e( 'Bulk SKU Search', 'bulk-sku-search-draft-dragan' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %d: maximum SKU count */
					esc_html__( 'Paste up to %d SKUs (one per line) to find matching WooCommerce products. You can then set published matches to draft.', 'bulk-sku-search-draft-dragan' ),
					(int) BSSDD_MAX_SKUS
				);
				?>
			</p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error bssdd-notice-inline">
					<p><?php echo esc_html( $error->get_error_message() ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" class="bssdd-search-form">
				<?php wp_nonce_field( self::NONCE_SEARCH ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="bssdd_skus"><?php esc_html_e( 'SKU List', 'bulk-sku-search-draft-dragan' ); ?></label>
						</th>
						<td>
							<textarea
								name="bssdd_skus"
								id="bssdd_skus"
								rows="12"
								class="large-text code"
								placeholder="<?php esc_attr_e( 'Enter one SKU per line (max 500)', 'bulk-sku-search-draft-dragan' ); ?>"
							><?php echo esc_textarea( $input ); ?></textarea>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="bssdd_search" class="button button-primary">
						<?php esc_html_e( 'Search Products', 'bulk-sku-search-draft-dragan' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render search results.
	 *
	 * @param array $results Search results.
	 */
	private function render_results( $results ) {
		$summary       = $results['summary'] ?? array();
		$draftable     = (int) ( $summary['draftable'] ?? 0 );
		$draftable_ids   = array_map( 'absint', (array) ( $results['draftable_ids'] ?? array() ) );
		$prefixable_ids = array_map( 'absint', (array) ( $results['prefixable_ids'] ?? array() ) );
		if ( empty( $prefixable_ids ) && ! empty( $results['found'] ) ) {
			$prefixable_ids = BSSDD_SKU_Updater::get_prefixable_ids( $results['found'] );
		}
		$view = sanitize_key( wp_unslash( $_GET['bssdd_view'] ?? 'found' ) );

		if ( ! in_array( $view, array( 'found', 'not_found', 'draftable' ), true ) ) {
			$view = 'found';
		}

		$rows = $this->get_view_rows( $results, $view );
		$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$total_pages = max( 1, (int) ceil( count( $rows ) / self::PER_PAGE ) );
		$paged = min( $paged, $total_pages );
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$page_rows = array_slice( $rows, $offset, self::PER_PAGE );

		$base_url = add_query_arg(
			array(
				'page'      => self::PAGE_SLUG,
				'bssdd_view' => $view,
			),
			admin_url( 'admin.php' )
		);

		?>
		<div class="bssdd-wrap">
			<h2><?php esc_html_e( 'Search Results', 'bulk-sku-search-draft-dragan' ); ?></h2>

			<div class="bssdd-summary">
				<div class="bssdd-summary-card bssdd-summary-card--total">
					<?php esc_html_e( 'Total SKUs', 'bulk-sku-search-draft-dragan' ); ?>
					<strong><?php echo esc_html( (string) ( $summary['total'] ?? 0 ) ); ?></strong>
				</div>
				<div class="bssdd-summary-card bssdd-summary-card--found">
					<?php esc_html_e( 'Found in Store', 'bulk-sku-search-draft-dragan' ); ?>
					<strong><?php echo esc_html( (string) ( $summary['found_skus'] ?? 0 ) ); ?></strong>
				</div>
				<div class="bssdd-summary-card bssdd-summary-card--missing">
					<?php esc_html_e( 'Not Found', 'bulk-sku-search-draft-dragan' ); ?>
					<strong><?php echo esc_html( (string) ( $summary['not_found'] ?? 0 ) ); ?></strong>
				</div>
				<div class="bssdd-summary-card bssdd-summary-card--draftable">
					<?php esc_html_e( 'Published (Draftable)', 'bulk-sku-search-draft-dragan' ); ?>
					<strong><?php echo esc_html( (string) $draftable ); ?></strong>
				</div>
				<div class="bssdd-summary-card bssdd-summary-card--draft">
					<?php esc_html_e( 'Already Draft', 'bulk-sku-search-draft-dragan' ); ?>
					<strong><?php echo esc_html( (string) ( $summary['already_draft'] ?? 0 ) ); ?></strong>
				</div>
				<?php if ( ! empty( $summary['other_status'] ) ) : ?>
					<div class="bssdd-summary-card bssdd-summary-card--other">
						<?php esc_html_e( 'Other Status', 'bulk-sku-search-draft-dragan' ); ?>
						<strong><?php echo esc_html( (string) $summary['other_status'] ); ?></strong>
					</div>
				<?php endif; ?>
			</div>

			<div class="bssdd-actions">
				<button
					type="button"
					id="bssdd-draft-btn"
					class="button button-primary"
					data-count="<?php echo esc_attr( (string) count( $draftable_ids ) ); ?>"
					<?php disabled( count( $draftable_ids ) === 0 ); ?>
				>
					<?php
					printf(
						/* translators: %d: number of draftable products */
						esc_html__( 'Set Published to Draft (%d)', 'bulk-sku-search-draft-dragan' ),
						count( $draftable_ids )
					);
					?>
				</button>
				<button
					type="button"
					id="bssdd-prefix-zero-btn"
					class="button"
					data-count="<?php echo esc_attr( (string) count( $prefixable_ids ) ); ?>"
					<?php disabled( count( $prefixable_ids ) === 0 ); ?>
				>
					<?php
					printf(
						/* translators: %d: number of SKUs that can get a leading zero */
						esc_html__( 'Add Leading 0 to SKUs (%d)', 'bulk-sku-search-draft-dragan' ),
						count( $prefixable_ids )
					);
					?>
				</button>
				<span id="bssdd-draft-progress" class="bssdd-draft-progress" aria-live="polite"></span>
				<span id="bssdd-prefix-zero-progress" class="bssdd-draft-progress" aria-live="polite"></span>
			</div>

			<nav class="nav-tab-wrapper bssdd-view-tabs">
				<?php
				$tabs = array(
					'found'      => __( 'Found', 'bulk-sku-search-draft-dragan' ) . ' (' . (int) ( $summary['found_rows'] ?? 0 ) . ')',
					'not_found'  => __( 'Not Found', 'bulk-sku-search-draft-dragan' ) . ' (' . (int) ( $summary['not_found'] ?? 0 ) . ')',
					'draftable'  => __( 'Draftable', 'bulk-sku-search-draft-dragan' ) . ' (' . $draftable . ')',
				);

				foreach ( $tabs as $tab_key => $label ) {
					$url = add_query_arg(
						array(
							'page'      => self::PAGE_SLUG,
							'bssdd_view' => $tab_key,
						),
						admin_url( 'admin.php' )
					);
					$active = $view === $tab_key ? ' nav-tab-active' : '';
					printf(
						'<a href="%s" class="nav-tab%s">%s</a>',
						esc_url( $url ),
						esc_attr( $active ),
						esc_html( $label )
					);
				}
				?>
			</nav>

			<?php if ( empty( $rows ) ) : ?>
				<div class="notice notice-info bssdd-notice-inline">
					<p><?php esc_html_e( 'No items to display for this view.', 'bulk-sku-search-draft-dragan' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped bssdd-results-table">
					<thead>
						<tr>
							<?php if ( 'not_found' !== $view ) : ?>
								<th><?php esc_html_e( 'SKU', 'bulk-sku-search-draft-dragan' ); ?></th>
								<th><?php esc_html_e( 'Product Name', 'bulk-sku-search-draft-dragan' ); ?></th>
								<th><?php esc_html_e( 'Status', 'bulk-sku-search-draft-dragan' ); ?></th>
								<th><?php esc_html_e( 'Type', 'bulk-sku-search-draft-dragan' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'bulk-sku-search-draft-dragan' ); ?></th>
							<?php else : ?>
								<th><?php esc_html_e( 'SKU', 'bulk-sku-search-draft-dragan' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php if ( 'not_found' === $view ) : ?>
							<?php foreach ( $page_rows as $sku ) : ?>
								<tr>
									<td><?php echo esc_html( $sku ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<?php foreach ( $page_rows as $row ) : ?>
								<?php
								$product_id    = (int) ( $row['product_id'] ?? 0 );
								$sku_value     = (string) ( $row['sku'] ?? $row['input_sku'] ?? '' );
								$needs_prefix  = BSSDD_SKU_Updater::needs_leading_zero( $sku_value );
								$prefixed_sku  = BSSDD_SKU_Updater::add_leading_zero( $sku_value );
								?>
								<tr data-product-id="<?php echo esc_attr( (string) $product_id ); ?>">
									<td class="bssdd-sku-cell">
										<div class="bssdd-sku-display">
											<code class="bssdd-sku-value"><?php echo esc_html( $sku_value ); ?></code>
											<?php if ( $needs_prefix ) : ?>
												<span class="bssdd-sku-target" title="<?php esc_attr_e( 'SKU after adding leading 0', 'bulk-sku-search-draft-dragan' ); ?>">
													&rarr; <code><?php echo esc_html( $prefixed_sku ); ?></code>
												</span>
											<?php endif; ?>
											<?php if ( $needs_prefix ) : ?>
												<button type="button" class="button button-small bssdd-sku-prefix-zero-btn" title="<?php esc_attr_e( 'Add leading 0', 'bulk-sku-search-draft-dragan' ); ?>">
													<?php esc_html_e( 'Add 0', 'bulk-sku-search-draft-dragan' ); ?>
												</button>
											<?php endif; ?>
											<button type="button" class="button button-small bssdd-sku-edit-btn" title="<?php esc_attr_e( 'Edit SKU', 'bulk-sku-search-draft-dragan' ); ?>">
												<?php esc_html_e( 'Edit SKU', 'bulk-sku-search-draft-dragan' ); ?>
											</button>
											<span class="bssdd-sku-display-feedback" aria-live="polite"></span>
										</div>
										<div class="bssdd-sku-editor" hidden>
											<input
												type="text"
												class="bssdd-sku-input regular-text"
												value="<?php echo esc_attr( $sku_value ); ?>"
												maxlength="100"
											/>
											<div class="bssdd-sku-editor-actions">
												<button type="button" class="button button-primary button-small bssdd-sku-save-btn">
													<?php esc_html_e( 'Save', 'bulk-sku-search-draft-dragan' ); ?>
												</button>
												<button type="button" class="button button-small bssdd-sku-cancel-btn">
													<?php esc_html_e( 'Cancel', 'bulk-sku-search-draft-dragan' ); ?>
												</button>
												<span class="bssdd-sku-feedback" aria-live="polite"></span>
											</div>
										</div>
									</td>
									<td>
										<?php if ( ! empty( $row['edit_link'] ) ) : ?>
											<a href="<?php echo esc_url( $row['edit_link'] ); ?>" target="_blank" rel="noopener noreferrer">
												<?php echo esc_html( $row['title'] ?? '' ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( $row['title'] ?? '' ); ?>
										<?php endif; ?>
									</td>
									<td>
										<span class="bssdd-status bssdd-status--<?php echo esc_attr( sanitize_html_class( $row['status'] ?? 'unknown' ) ); ?>">
											<?php echo esc_html( ucfirst( (string) ( $row['status'] ?? '' ) ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $this->format_post_type( $row['post_type'] ?? '' ) ); ?></td>
									<td class="bssdd-actions-cell">
										<?php if ( ! empty( $row['view_link'] ) ) : ?>
											<a href="<?php echo esc_url( $row['view_link'] ); ?>" class="bssdd-quick-link" target="_blank" rel="noopener noreferrer">
												<?php esc_html_e( 'View', 'bulk-sku-search-draft-dragan' ); ?>
											</a>
										<?php endif; ?>
										<?php if ( ! empty( $row['edit_link'] ) ) : ?>
											<a href="<?php echo esc_url( $row['edit_link'] ); ?>" class="bssdd-quick-link" target="_blank" rel="noopener noreferrer">
												<?php esc_html_e( 'Edit', 'bulk-sku-search-draft-dragan' ); ?>
											</a>
										<?php endif; ?>
										<?php if ( empty( $row['view_link'] ) && empty( $row['edit_link'] ) ) : ?>
											&mdash;
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bssdd-pagination">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%', $base_url ),
										'format'    => '',
										'current'   => $paged,
										'total'     => $total_pages,
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get rows for the active results view.
	 *
	 * @param array  $results Search results.
	 * @param string $view    View key.
	 * @return array<int, mixed>
	 */
	private function get_view_rows( $results, $view ) {
		if ( 'not_found' === $view ) {
			return array_values( (array) ( $results['not_found'] ?? array() ) );
		}

		$found = (array) ( $results['found'] ?? array() );

		if ( 'draftable' === $view ) {
			return array_values(
				array_filter(
					$found,
					function ( $row ) {
						return ! empty( $row['is_draftable'] );
					}
				)
			);
		}

		return $found;
	}

	/**
	 * Format post type label for display.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	private function format_post_type( $post_type ) {
		if ( 'product_variation' === $post_type ) {
			return __( 'Variation', 'bulk-sku-search-draft-dragan' );
		}

		return __( 'Product', 'bulk-sku-search-draft-dragan' );
	}
}
