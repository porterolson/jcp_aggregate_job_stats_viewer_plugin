<?php
/**
 * Plugin Name: JCP Aggregate Job Stats Viewer V2
 * Description: Displays aggregate job response statistics from JCP Session Tracker in wp-admin.
 * Version: 1.0.3
 * Author: Porter Olson
 * Text Domain: jcp-aggregate-job-stats-viewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'JCP_Aggregate_Job_Stats_Viewer' ) ) {
	/**
	 * Admin-only aggregate job stats viewer.
	 */
	class JCP_Aggregate_Job_Stats_Viewer {

		/**
		 * Admin page slug.
		 */
		const PAGE_SLUG = 'jcp-aggregate-job-stats-viewer';

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
			add_action( 'admin_init', array( $this, 'maybe_export_json' ) );
		}

		/**
		 * Add the page under Users.
		 *
		 * @return void
		 */
		public function register_admin_page() {
			add_users_page(
				__( 'Aggregate Job Stats', 'jcp-aggregate-job-stats-viewer' ),
				__( 'Aggregate Job Stats', 'jcp-aggregate-job-stats-viewer' ),
				'list_users',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Render admin page.
		 *
		 * @return void
		 */
		public function render_page() {
			if ( ! current_user_can( 'list_users' ) ) {
				wp_die( esc_html__( 'You do not have permission to view aggregate job stats.', 'jcp-aggregate-job-stats-viewer' ) );
			}

			$table_exists = $this->responses_table_exists();
			$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
			$detail_job   = isset( $_GET['job_path'] ) ? sanitize_text_field( wp_unslash( $_GET['job_path'] ) ) : '';
			$stats        = $table_exists ? $this->get_aggregate_stats( $search ) : array();
			$totals       = $this->calculate_totals( $stats );
			$export_url   = add_query_arg(
				array_filter(
					array(
						'page'                => self::PAGE_SLUG,
						'export_jcpajs_json'  => '1',
						'_wpnonce'            => wp_create_nonce( 'jcpajs_export_json' ),
						's'                   => $search,
					),
					'strlen'
				),
				admin_url( 'users.php' )
			);

			$this->render_styles();
			?>
			<div class="wrap jcpajs-wrap">
				<h1><?php esc_html_e( 'Aggregate Job Stats', 'jcp-aggregate-job-stats-viewer' ); ?></h1>

				<?php if ( ! $table_exists ) : ?>
					<div class="notice notice-warning">
						<p><?php esc_html_e( 'The JCP Session Tracker job response table was not found. Activate or update the session plugin first.', 'jcp-aggregate-job-stats-viewer' ); ?></p>
					</div>
				<?php else : ?>
					<div class="jcpajs-summary" aria-label="<?php esc_attr_e( 'Aggregate summary', 'jcp-aggregate-job-stats-viewer' ); ?>">
						<?php $this->render_summary_card( __( 'Jobs', 'jcp-aggregate-job-stats-viewer' ), $totals['jobs'] ); ?>
						<?php $this->render_summary_card( __( 'Responses', 'jcp-aggregate-job-stats-viewer' ), $totals['responses'] ); ?>
						<?php $this->render_summary_card( __( 'Applicants', 'jcp-aggregate-job-stats-viewer' ), $totals['applicants'] ); ?>
						<?php $this->render_summary_card( __( 'Interview Rate', 'jcp-aggregate-job-stats-viewer' ), $totals['interview_rate'] . '%' ); ?>
						<?php $this->render_summary_card( __( 'Offer Rate', 'jcp-aggregate-job-stats-viewer' ), $totals['offer_rate'] . '%' ); ?>
					</div>

					<form class="jcpajs-filter" method="get" action="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
						<label class="screen-reader-text" for="jcpajs-search"><?php esc_html_e( 'Search jobs', 'jcp-aggregate-job-stats-viewer' ); ?></label>
						<input id="jcpajs-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search job title or path', 'jcp-aggregate-job-stats-viewer' ); ?>" />
						<?php submit_button( __( 'Search Jobs', 'jcp-aggregate-job-stats-viewer' ), 'secondary', '', false ); ?>
						<a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Download JSON', 'jcp-aggregate-job-stats-viewer' ); ?></a>
						<?php if ( '' !== $search ) : ?>
							<a class="button button-link" href="<?php echo esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'users.php' ) ) ); ?>"><?php esc_html_e( 'Clear', 'jcp-aggregate-job-stats-viewer' ); ?></a>
						<?php endif; ?>
					</form>

					<?php $this->render_stats_table( $stats, $detail_job, $search ); ?>

					<?php if ( '' !== $detail_job ) : ?>
						<?php $this->render_job_detail( $detail_job, $search ); ?>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Export aggregate job stats as JSON.
		 *
		 * @return void
		 */
		public function maybe_export_json() {
			$should_export = isset( $_GET['page'], $_GET['export_jcpajs_json'] )
				&& self::PAGE_SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ) )
				&& '1' === sanitize_text_field( wp_unslash( $_GET['export_jcpajs_json'] ) );

			if ( ! $should_export ) {
				return;
			}

			if ( ! current_user_can( 'list_users' ) ) {
				wp_die( esc_html__( 'You do not have permission to export aggregate job stats.', 'jcp-aggregate-job-stats-viewer' ) );
			}

			check_admin_referer( 'jcpajs_export_json' );

			$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
			$stats  = $this->get_aggregate_stats( $search );
			$export = array(
				'generated_at' => current_time( 'mysql', true ),
				'search'       => $search,
				'job_count'    => count( $stats ),
				'jobs'         => array(),
			);

			foreach ( $stats as $row ) {
				$export['jobs'][] = $this->build_export_job_payload( $row );
			}

			nocache_headers();
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			header( 'Content-Disposition: attachment; filename=' . $this->get_export_filename( $search ) );

			echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			exit;
		}

		/**
		 * Render a summary card.
		 *
		 * @param string     $label Card label.
		 * @param int|string $value Card value.
		 * @return void
		 */
		private function render_summary_card( $label, $value ) {
			?>
			<div class="jcpajs-summary__card">
				<div class="jcpajs-summary__value"><?php echo esc_html( (string) $value ); ?></div>
				<div class="jcpajs-summary__label"><?php echo esc_html( $label ); ?></div>
			</div>
			<?php
		}

		/**
		 * Render aggregate table.
		 *
		 * @param array<int, array<string, mixed>> $stats Stats rows.
		 * @param string                          $detail_job Current detail job path.
		 * @param string                          $search Current search term.
		 * @return void
		 */
		private function render_stats_table( $stats, $detail_job, $search ) {
			?>
			<table class="widefat striped jcpajs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job', 'jcp-aggregate-job-stats-viewer' ); ?></th>
						<th><?php esc_html_e( 'Responses', 'jcp-aggregate-job-stats-viewer' ); ?></th>
						<th><?php esc_html_e( 'Applicants', 'jcp-aggregate-job-stats-viewer' ); ?></th>
						<th><?php esc_html_e( 'Interviews', 'jcp-aggregate-job-stats-viewer' ); ?></th>
						<th><?php esc_html_e( 'Offers', 'jcp-aggregate-job-stats-viewer' ); ?></th>
						<th><?php esc_html_e( 'Interview Rate', 'jcp-aggregate-job-stats-viewer' ); ?></th>
						<th><?php esc_html_e( 'Offer Rate', 'jcp-aggregate-job-stats-viewer' ); ?></th>
						<th><?php esc_html_e( 'Last Updated', 'jcp-aggregate-job-stats-viewer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $stats ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'No aggregate job responses found.', 'jcp-aggregate-job-stats-viewer' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $stats as $row ) : ?>
							<?php
							$detail_url = add_query_arg(
								array_filter(
									array(
										'page'     => self::PAGE_SLUG,
										'job_path' => $row['job_path'],
										's'        => $search,
									),
									'strlen'
								),
								admin_url( 'users.php' )
							);
							$is_selected = $detail_job === $row['job_path'];
							?>
							<tr class="<?php echo $is_selected ? 'jcpajs-row-selected' : ''; ?>">
								<td>
									<strong><?php echo esc_html( $this->get_job_label( $row ) ); ?></strong>
									<div class="jcpajs-muted"><code><?php echo esc_html( $row['job_path'] ); ?></code></div>
									<div class="row-actions">
										<span><a href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'View responses', 'jcp-aggregate-job-stats-viewer' ); ?></a></span>
										<?php if ( ! empty( $row['job_url'] ) ) : ?>
											<span> | <a href="<?php echo esc_url( $row['job_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open job', 'jcp-aggregate-job-stats-viewer' ); ?></a></span>
										<?php endif; ?>
									</div>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['response_count'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['applicant_count'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['interview_count'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['offer_count'] ) ); ?></td>
								<td><?php echo esc_html( (string) $row['interview_rate'] ); ?>%</td>
								<td><?php echo esc_html( (string) $row['offer_rate'] ); ?>%</td>
								<td><?php echo esc_html( $this->format_datetime( $row['last_updated'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * Render detail responses for one job.
		 *
		 * @param string $job_path Job path.
		 * @param string $search Current search term.
		 * @return void
		 */
		private function render_job_detail( $job_path, $search ) {
			$responses = $this->get_job_responses( $job_path );
			$back_url  = add_query_arg(
				array_filter(
					array(
						'page' => self::PAGE_SLUG,
						's'    => $search,
					),
					'strlen'
				),
				admin_url( 'users.php' )
			);
			?>
			<div class="jcpajs-detail">
				<h2><?php esc_html_e( 'Job Responses', 'jcp-aggregate-job-stats-viewer' ); ?></h2>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to Aggregate Stats', 'jcp-aggregate-job-stats-viewer' ); ?></a>
				</p>
				<p><code><?php echo esc_html( $job_path ); ?></code></p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'jcp-aggregate-job-stats-viewer' ); ?></th>
							<th><?php esc_html_e( 'Applied', 'jcp-aggregate-job-stats-viewer' ); ?></th>
							<th><?php esc_html_e( 'Interviewed', 'jcp-aggregate-job-stats-viewer' ); ?></th>
							<th><?php esc_html_e( 'Offered', 'jcp-aggregate-job-stats-viewer' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'jcp-aggregate-job-stats-viewer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $responses ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No responses found for this job.', 'jcp-aggregate-job-stats-viewer' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $responses as $response ) : ?>
								<tr>
									<td><?php echo wp_kses_post( $this->format_user_cell( $response ) ); ?></td>
									<td><?php echo esc_html( $this->format_yes_no( $response['applied'] ) ); ?></td>
									<td><?php echo esc_html( $this->format_yes_no( $response['interviewed'] ) ); ?></td>
									<td><?php echo esc_html( $this->format_yes_no( $response['offered'] ) ); ?></td>
									<td><?php echo esc_html( $this->format_datetime( $response['updated_at'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php
		}

		/**
		 * Check whether the session plugin response table exists.
		 *
		 * @return bool
		 */
		private function responses_table_exists() {
			global $wpdb;

			$table = $wpdb->prefix . 'jcpst_job_responses';

			return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}

		/**
		 * Fetch grouped aggregate stats.
		 *
		 * @param string $search Optional search term.
		 * @return array<int, array<string, mixed>>
		 */
		private function get_aggregate_stats( $search = '' ) {
			global $wpdb;

			$table  = $wpdb->prefix . 'jcpst_job_responses';
			$where  = '';
			$params = array();

			if ( '' !== $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where    = 'WHERE job_path LIKE %s OR job_title LIKE %s';
				$params[] = $like;
				$params[] = $like;
			}

			$sql = "
				SELECT
					job_path,
					MAX(job_url) AS job_url,
					MAX(job_title) AS job_title,
					COUNT(*) AS response_count,
					SUM(CASE WHEN applied = 1 THEN 1 ELSE 0 END) AS applicant_count,
					SUM(CASE WHEN applied = 1 AND interviewed = 1 THEN 1 ELSE 0 END) AS interview_count,
					SUM(CASE WHEN applied = 1 AND offered = 1 THEN 1 ELSE 0 END) AS offer_count,
					MAX(updated_at) AS last_updated
				FROM {$table}
				{$where}
				GROUP BY job_path
				ORDER BY applicant_count DESC, last_updated DESC
			";

			if ( ! empty( $params ) ) {
				$sql = $wpdb->prepare( $sql, $params );
			}

			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( ! is_array( $rows ) ) {
				return array();
			}

			foreach ( $rows as $index => $row ) {
				$applicants                     = (int) $row['applicant_count'];
				$rows[ $index ]['response_count']  = (int) $row['response_count'];
				$rows[ $index ]['applicant_count'] = $applicants;
				$rows[ $index ]['interview_count'] = (int) $row['interview_count'];
				$rows[ $index ]['offer_count']     = (int) $row['offer_count'];
				$rows[ $index ]['interview_rate']  = $applicants > 0 ? (int) round( ( (int) $row['interview_count'] / $applicants ) * 100 ) : 0;
				$rows[ $index ]['offer_rate']      = $applicants > 0 ? (int) round( ( (int) $row['offer_count'] / $applicants ) * 100 ) : 0;
			}

			return $rows;
		}

		/**
		 * Fetch individual responses for a job.
		 *
		 * @param string $job_path Job path.
		 * @return array<int, array<string, mixed>>
		 */
		private function get_job_responses( $job_path ) {
			global $wpdb;

			$table = $wpdb->prefix . 'jcpst_job_responses';

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE job_path = %s ORDER BY updated_at DESC",
					$job_path
				),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Build export payload for a single job.
		 *
		 * @param array<string, mixed> $row Aggregate stats row.
		 * @return array<string, mixed>
		 */
		private function build_export_job_payload( $row ) {
			$responses        = $this->get_job_responses( $row['job_path'] );
			$export_responses = array();

			foreach ( $responses as $response ) {
				$export_responses[] = $this->build_export_response_payload( $response );
			}

			return array(
				'job_path'       => (string) $row['job_path'],
				'job_title'      => $this->get_job_label( $row ),
				'job_url'        => isset( $row['job_url'] ) ? (string) $row['job_url'] : '',
				'response_count' => (int) $row['response_count'],
				'applicant_count'=> (int) $row['applicant_count'],
				'interview_count'=> (int) $row['interview_count'],
				'offer_count'    => (int) $row['offer_count'],
				'interview_rate' => (int) $row['interview_rate'],
				'offer_rate'     => (int) $row['offer_rate'],
				'last_updated'   => $this->format_datetime( $row['last_updated'] ),
				'responses'      => $export_responses,
			);
		}

		/**
		 * Build export payload for an individual user response.
		 *
		 * @param array<string, mixed> $response Response row.
		 * @return array<string, mixed>
		 */
		private function build_export_response_payload( $response ) {
			$user_id = isset( $response['user_id'] ) ? (int) $response['user_id'] : 0;
			$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

			return array(
				'user_id'      => $user_id,
				'display_name' => $user ? $user->display_name : '',
				'user_login'   => $user ? $user->user_login : '',
				'user_email'   => $user ? $user->user_email : '',
				'applied'      => (int) $response['applied'],
				'interviewed'  => (int) $response['interviewed'],
				'offered'      => (int) $response['offered'],
				'created_at'   => $this->format_datetime( $response['created_at'] ),
				'updated_at'   => $this->format_datetime( $response['updated_at'] ),
			);
		}

		/**
		 * Calculate totals for summary cards.
		 *
		 * @param array<int, array<string, mixed>> $stats Stats rows.
		 * @return array<string, int>
		 */
		private function calculate_totals( $stats ) {
			$totals = array(
				'jobs'            => count( $stats ),
				'responses'       => 0,
				'applicants'      => 0,
				'interviews'      => 0,
				'offers'          => 0,
				'interview_rate'  => 0,
				'offer_rate'      => 0,
			);

			foreach ( $stats as $row ) {
				$totals['responses']  += (int) $row['response_count'];
				$totals['applicants'] += (int) $row['applicant_count'];
				$totals['interviews'] += (int) $row['interview_count'];
				$totals['offers']     += (int) $row['offer_count'];
			}

			if ( $totals['applicants'] > 0 ) {
				$totals['interview_rate'] = (int) round( ( $totals['interviews'] / $totals['applicants'] ) * 100 );
				$totals['offer_rate']     = (int) round( ( $totals['offers'] / $totals['applicants'] ) * 100 );
			}

			return $totals;
		}

		/**
		 * Get display title for a job row.
		 *
		 * @param array<string, mixed> $row Stats row.
		 * @return string
		 */
		private function get_job_label( $row ) {
			if ( ! empty( $row['job_title'] ) ) {
				return (string) $row['job_title'];
			}

			$slug = trim( (string) $row['job_path'], '/' );
			$slug = preg_replace( '#^jobs/#', '', $slug );
			$slug = str_replace( array( '-', '_' ), ' ', (string) $slug );
			$slug = trim( preg_replace( '/\s+/', ' ', (string) $slug ) );

			return $slug ? ucwords( $slug ) : __( 'Untitled Job', 'jcp-aggregate-job-stats-viewer' );
		}

		/**
		 * Format a user table cell.
		 *
		 * @param array<string, mixed> $response Response row.
		 * @return string
		 */
		private function format_user_cell( $response ) {
			$user_id = isset( $response['user_id'] ) ? (int) $response['user_id'] : 0;
			$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

			if ( ! $user ) {
				return esc_html( sprintf( __( 'User #%d', 'jcp-aggregate-job-stats-viewer' ), $user_id ) );
			}

			$name = $user->display_name ? $user->display_name : $user->user_login;
			$url  = get_edit_user_link( $user_id );

			return '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a><div class="jcpajs-muted">' . esc_html( $user->user_email ) . '</div>';
		}

		/**
		 * Format boolean response values.
		 *
		 * @param mixed $value Response value.
		 * @return string
		 */
		private function format_yes_no( $value ) {
			return (int) $value ? __( 'Yes', 'jcp-aggregate-job-stats-viewer' ) : __( 'No', 'jcp-aggregate-job-stats-viewer' );
		}

		/**
		 * Format GMT datetime in site timezone.
		 *
		 * @param string $datetime GMT datetime.
		 * @return string
		 */
		private function format_datetime( $datetime ) {
			if ( empty( $datetime ) ) {
				return '';
			}

			return get_date_from_gmt( $datetime, 'Y-m-d H:i:s' );
		}

		/**
		 * Build export filename.
		 *
		 * @param string $search Search term.
		 * @return string
		 */
		private function get_export_filename( $search ) {
			$suffix = '' !== $search ? '-' . sanitize_title( $search ) : '-all-jobs';

			return 'aggregate-job-stats' . $suffix . '-' . gmdate( 'Y-m-d-His' ) . '.json';
		}

		/**
		 * Render lightweight admin CSS.
		 *
		 * @return void
		 */
		private function render_styles() {
			?>
			<style>
				.jcpajs-summary {
					display: grid;
					grid-template-columns: repeat(5, minmax(120px, 1fr));
					gap: 12px;
					margin: 18px 0;
				}
				.jcpajs-summary__card {
					background: #fff;
					border: 1px solid #dcdcde;
					border-radius: 6px;
					padding: 14px;
				}
				.jcpajs-summary__value {
					color: #1d2327;
					font-size: 24px;
					font-weight: 600;
					line-height: 1.1;
				}
				.jcpajs-summary__label,
				.jcpajs-muted {
					color: #646970;
					font-size: 12px;
					margin-top: 4px;
				}
				.jcpajs-filter {
					display: flex;
					align-items: center;
					gap: 8px;
					margin: 18px 0;
				}
				.jcpajs-filter input[type="search"] {
					min-width: 280px;
				}
				.jcpajs-table td,
				.jcpajs-table th {
					vertical-align: top;
				}
				.jcpajs-row-selected td {
					background: #f0f6fc;
				}
				.jcpajs-detail {
					margin-top: 24px;
				}
				@media (max-width: 960px) {
					.jcpajs-summary {
						grid-template-columns: repeat(2, minmax(120px, 1fr));
					}
					.jcpajs-filter {
						align-items: stretch;
						flex-direction: column;
					}
					.jcpajs-filter input[type="search"] {
						min-width: 0;
						width: 100%;
					}
				}
			</style>
			<?php
		}
	}
}

new JCP_Aggregate_Job_Stats_Viewer();
