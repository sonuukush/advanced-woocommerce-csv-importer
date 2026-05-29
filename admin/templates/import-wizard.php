<?php
/**
 * Import Wizard Template
 * Renders the main modern dashboard UI, wizard steps, upload card, column mapping tables, progress bar, and logs report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<div class="wc-csv-importer-wrap">
		<!-- Header -->
		<div class="importer-header">
			<h1><?php esc_html_e( 'Advanced CSV Product Importer', 'advanced-wc-csv-importer' ); ?></h1>
			<p><?php esc_html_e( 'Import 30k+ products asynchronously with high speed, strict validation, and Zero server timeout.', 'advanced-wc-csv-importer' ); ?></p>
		</div>

		<!-- Progress Steps -->
		<div class="importer-steps">
			<div class="step-node active" id="step_upload">
				<div class="step-circle">1</div>
				<div class="step-label"><?php esc_html_e( 'Upload CSV', 'advanced-wc-csv-importer' ); ?></div>
			</div>
			<div class="step-node" id="step_mapping">
				<div class="step-circle">2</div>
				<div class="step-label"><?php esc_html_e( 'Column Mapping', 'advanced-wc-csv-importer' ); ?></div>
			</div>
			<div class="step-node" id="step_progress">
				<div class="step-circle">3</div>
				<div class="step-label"><?php esc_html_e( 'Live Progress', 'advanced-wc-csv-importer' ); ?></div>
			</div>
			<div class="step-node" id="step_report">
				<div class="step-circle">4</div>
				<div class="step-label"><?php esc_html_e( 'Import Report', 'advanced-wc-csv-importer' ); ?></div>
			</div>
		</div>

		<!-- STEP 1: UPLOAD PANEL -->
		<div class="importer-panel" id="panel_upload">
			<div class="drag-drop-zone">
				<svg class="upload-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
				</svg>
				<h3><?php esc_html_e( 'Drag and drop your CSV file here', 'advanced-wc-csv-importer' ); ?></h3>
				<p><?php esc_html_e( 'Or click here to browse files on your computer (Supports up to 100MB+)', 'advanced-wc-csv-importer' ); ?></p>
				<input type="file" id="csv_file_input" accept=".csv" />
			</div>

			<!-- Quick Instructions -->
			<div style="margin-top: 30px; font-size: 0.9rem; color: var(--text-secondary); line-height: 1.5;">
				<h4 style="color: var(--text-primary); margin-bottom: 8px;"><?php esc_html_e( 'Import Guidelines:', 'advanced-wc-csv-importer' ); ?></h4>
				<ul style="list-style-type: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'Ensure your CSV file is saved in UTF-8 encoding.', 'advanced-wc-csv-importer' ); ?></li>
					<li><?php esc_html_e( 'Use standard header names like Name, SKU, Regular Price, Categories to enable automatic column matching.', 'advanced-wc-csv-importer' ); ?></li>
					<li><?php esc_html_e( 'Image column values should be direct URLs starting with http:// or https://.', 'advanced-wc-csv-importer' ); ?></li>
				</ul>
			</div>

			<!-- Import Jobs History List -->
			<h3 class="history-title"><?php esc_html_e( 'Recent Import History', 'advanced-wc-csv-importer' ); ?></h3>
			<div class="history-table-container">
				<table class="history-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Job ID', 'advanced-wc-csv-importer' ); ?></th>
							<th><?php esc_html_e( 'File Path', 'advanced-wc-csv-importer' ); ?></th>
							<th><?php esc_html_e( 'Mode', 'advanced-wc-csv-importer' ); ?></th>
							<th><?php esc_html_e( 'Progress', 'advanced-wc-csv-importer' ); ?></th>
							<th><?php esc_html_e( 'Status', 'advanced-wc-csv-importer' ); ?></th>
							<th><?php esc_html_e( 'Date', 'advanced-wc-csv-importer' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'advanced-wc-csv-importer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $jobs ) ) : ?>
							<?php foreach ( $jobs as $job ) : ?>
								<tr>
									<td>#<?php echo esc_html( $job->id ); ?></td>
									<td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
										<?php echo esc_html( basename( $job->file_path ) ); ?>
									</td>
									<td>
										<span class="badge" style="background:#f1f5f9; color:#475569; font-size:0.75rem;">
											<?php echo esc_html( strtoupper( $job->mode ) ); ?>
										</span>
									</td>
									<td>
										<strong><?php echo esc_html( $job->processed_rows + $job->failed_rows ); ?></strong>
										/ <?php echo esc_html( $job->total_rows ); ?>
									</td>
									<td>
										<span class="badge <?php echo esc_attr( $job->status ); ?>">
											<?php echo esc_html( $job->status ); ?>
										</span>
									</td>
									<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $job->created_at ) ) ); ?></td>
									<td>
										<?php if ( $job->failed_rows > 0 ) : ?>
											<button class="importer-btn secondary btn-retry-failed" style="padding: 6px 12px; font-size: 0.8rem;" data-job-id="<?php echo esc_attr( $job->id ); ?>">
												<?php esc_html_e( 'Retry Failed', 'advanced-wc-csv-importer' ); ?>
											</button>
										<?php else : ?>
											<span>-</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="7" style="text-align: center; color: var(--text-secondary);">
									<?php esc_html_e( 'No import history found.', 'advanced-wc-csv-importer' ); ?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<!-- STEP 2: COLUMN MAPPING PANEL -->
		<div class="importer-panel hidden" id="panel_mapping">
			<h3 style="margin-top: 0; font-weight: 600; font-size: 1.4rem;"><?php esc_html_e( 'Map CSV Columns to WooCommerce Fields', 'advanced-wc-csv-importer' ); ?></h3>
			<p style="color: var(--text-secondary); margin-bottom: 24px;"><?php esc_html_e( 'Specify which CSV headers correspond to the product attributes in WooCommerce. Columns left as "Do Not Import" will be ignored.', 'advanced-wc-csv-importer' ); ?></p>

			<div class="mapping-container">
				<table class="mapping-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'CSV Header Name', 'advanced-wc-csv-importer' ); ?></th>
							<th><?php esc_html_e( 'WooCommerce Field', 'advanced-wc-csv-importer' ); ?></th>
						</tr>
					</thead>
					<tbody id="mapping_table_body">
						<!-- Loaded Dynamically -->
					</tbody>
				</table>
			</div>

			<!-- Configuration Settings Grid -->
			<h3 style="font-weight: 600; font-size: 1.2rem; margin-top: 32px;"><?php esc_html_e( 'Import Configuration', 'advanced-wc-csv-importer' ); ?></h3>
			<div class="config-grid">
				<div class="config-item">
					<label for="duplicate_handle"><?php esc_html_e( 'Duplicate SKUs Handling', 'advanced-wc-csv-importer' ); ?></label>
					<select id="duplicate_handle">
						<option value="skip"><?php esc_html_e( 'Skip existing SKU (Ignore)', 'advanced-wc-csv-importer' ); ?></option>
						<option value="update"><?php esc_html_e( 'Update existing product properties', 'advanced-wc-csv-importer' ); ?></option>
						<option value="replace"><?php esc_html_e( 'Delete and replace existing product', 'advanced-wc-csv-importer' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Create new duplicate, set as Draft', 'advanced-wc-csv-importer' ); ?></option>
					</select>
				</div>

				<div class="config-item">
					<label for="import_mode"><?php esc_html_e( 'Import Run Mode', 'advanced-wc-csv-importer' ); ?></label>
					<select id="import_mode">
						<option value="live"><?php esc_html_e( 'Live Import Mode (Save to DB)', 'advanced-wc-csv-importer' ); ?></option>
						<option value="dry_run"><?php esc_html_e( 'Dry Run Mode (Simulate Only)', 'advanced-wc-csv-importer' ); ?></option>
					</select>
				</div>

				<div class="config-item">
					<label for="batch_size"><?php esc_html_e( 'Background Batch Size', 'advanced-wc-csv-importer' ); ?></label>
					<select id="batch_size">
						<option value="100"><?php esc_html_e( '100 products (Conservative)', 'advanced-wc-csv-importer' ); ?></option>
						<option value="250" selected><?php esc_html_e( '250 products (Recommended)', 'advanced-wc-csv-importer' ); ?></option>
						<option value="500"><?php esc_html_e( '500 products (Aggressive)', 'advanced-wc-csv-importer' ); ?></option>
					</select>
				</div>
			</div>

			<div class="action-bar">
				<button class="importer-btn secondary" onclick="location.reload();"><?php esc_html_e( 'Cancel', 'advanced-wc-csv-importer' ); ?></button>
				<button class="importer-btn" id="btn_start_import"><?php esc_html_e( 'Validate & Start Import', 'advanced-wc-csv-importer' ); ?></button>
			</div>
		</div>

		<!-- STEP 3: LIVE PROGRESS PANEL -->
		<div class="importer-panel hidden" id="panel_progress">
			<!-- Validation pre-report -->
			<div id="validation_pre_report" class="hidden" style="margin-bottom: 30px;">
				<!-- Added Dynamically -->
			</div>

			<div class="progress-status-container">
				<h3 style="margin: 0 0 6px 0; font-size: 1.6rem; font-weight: 600;" id="progress_status_text">
					<?php esc_html_e( 'Initializing...', 'advanced-wc-csv-importer' ); ?>
				</h3>
				<p style="color: var(--text-secondary); margin: 0; font-size: 0.95rem;">
					<?php esc_html_e( 'This process runs in the background. You can safely close or browse away from this page.', 'advanced-wc-csv-importer' ); ?>
				</p>

				<!-- Progress Fill Bar -->
				<div class="progress-bar-wrapper">
					<div class="progress-bar-fill"></div>
				</div>
				<span style="font-size: 2.2rem; font-weight: 700; background: var(--primary-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent;" id="progress_percentage_text">0%</span>

				<!-- Metrics Cards Grid -->
				<div class="progress-metrics">
					<div class="metric-card processed">
						<div class="metric-num" id="metric_processed">0</div>
						<div class="metric-label"><?php esc_html_e( 'Imported / Updated', 'advanced-wc-csv-importer' ); ?></div>
					</div>
					<div class="metric-card failed">
						<div class="metric-num" id="metric_failed">0</div>
						<div class="metric-label"><?php esc_html_e( 'Failed Rows', 'advanced-wc-csv-importer' ); ?></div>
					</div>
					<div class="metric-card remaining">
						<div class="metric-num" id="metric_remaining">0</div>
						<div class="metric-label"><?php esc_html_e( 'Remaining Rows', 'advanced-wc-csv-importer' ); ?></div>
					</div>
					<div class="metric-card time">
						<div class="metric-num" id="metric_time">Calculating...</div>
						<div class="metric-label"><?php esc_html_e( 'Estimated Time', 'advanced-wc-csv-importer' ); ?></div>
					</div>
				</div>
			</div>

			<div class="action-bar" style="justify-content: center; margin-top: 40px; gap: 20px;">
				<button class="importer-btn secondary" id="btn_toggle_import" style="min-width: 180px;">
					<?php esc_html_e( 'Pause Import', 'advanced-wc-csv-importer' ); ?>
				</button>
				<button class="importer-btn danger" id="btn_cancel_import" style="min-width: 180px;">
					<?php esc_html_e( 'Stop / Cancel Import', 'advanced-wc-csv-importer' ); ?>
				</button>
			</div>
		</div>

		<!-- STEP 4: REPORT PANEL -->
		<div class="importer-panel hidden" id="panel_report">
			<div style="text-align: center; padding: 40px 0;">
				<div style="width: 80px; height: 80px; background: var(--success-light); color: var(--success-color); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 24px;">
					<svg style="width:40px; height:40px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
					</svg>
				</div>
				<h2 style="font-size: 2.2rem; font-weight: 700; margin: 0 0 8px 0;"><?php esc_html_e( 'Import Execution Report', 'advanced-wc-csv-importer' ); ?></h2>
				<p style="color: var(--text-secondary); font-size: 1.1rem; margin-bottom: 40px;"><?php esc_html_e( 'The CSV database processing job finished successfully.', 'advanced-wc-csv-importer' ); ?></p>

				<!-- Dashboard numbers -->
				<div style="display: flex; justify-content: center; gap: 40px; margin-bottom: 40px;">
					<div style="text-align: center; border-right: 1.5px solid var(--border-light); padding-right: 40px;">
						<div style="font-size: 2.8rem; font-weight: 700; color: var(--text-primary);" id="report_total">0</div>
						<div style="color: var(--text-secondary); text-transform: uppercase; font-size: 0.85rem; font-weight: 500; margin-top: 4px;"><?php esc_html_e( 'Total CSV Rows', 'advanced-wc-csv-importer' ); ?></div>
					</div>
					<div style="text-align: center; border-right: 1.5px solid var(--border-light); padding-right: 40px;">
						<div style="font-size: 2.8rem; font-weight: 700; color: var(--success-color);" id="report_processed">0</div>
						<div style="color: var(--text-secondary); text-transform: uppercase; font-size: 0.85rem; font-weight: 500; margin-top: 4px;"><?php esc_html_e( 'Success / Updated', 'advanced-wc-csv-importer' ); ?></div>
					</div>
					<div style="text-align: center;">
						<div style="font-size: 2.8rem; font-weight: 700; color: var(--error-color);" id="report_failed">0</div>
						<div style="color: var(--text-secondary); text-transform: uppercase; font-size: 0.85rem; font-weight: 500; margin-top: 4px;"><?php esc_html_e( 'Failed Rows', 'advanced-wc-csv-importer' ); ?></div>
					</div>
				</div>

				<!-- Action recommendations for failure -->
				<div class="failed-rows-container hidden" style="background: var(--error-light); border: 1.5px solid rgba(239,68,68,0.15); max-width: 600px; margin: 0 auto 30px auto; border-radius: 12px; padding: 24px; text-align: left;">
					<h4 style="color: #991b1b; margin: 0 0 8px 0; font-weight: 600; font-size: 1.05rem;"><?php esc_html_e( 'Troubleshooting Failed Rows:', 'advanced-wc-csv-importer' ); ?></h4>
					<p style="color: #7f1d1d; margin: 0 0 20px 0; font-size: 0.95rem; line-height: 1.5;">
						<?php esc_html_e( 'Some product rows failed due to incorrect prices, image URL errors, or database lock conditions. You can review/export full failure traces, or trigger an automated background retry of all failed rows below.', 'advanced-wc-csv-importer' ); ?>
					</p>
					<div style="display: flex; gap: 12px;">
						<a href="#" class="importer-btn danger" id="btn_download_failed_logs" target="_blank" style="padding: 8px 16px; font-size: 0.9rem;"><?php esc_html_e( 'Download Failed Rows Log CSV', 'advanced-wc-csv-importer' ); ?></a>
						<button class="importer-btn btn-retry-failed" id="btn_retry_failed_job" style="padding: 8px 16px; font-size: 0.9rem;"><?php esc_html_e( 'Retry All Failed Rows Now', 'advanced-wc-csv-importer' ); ?></button>
					</div>
				</div>

				<button class="importer-btn" onclick="location.reload();" style="min-width: 200px;"><?php esc_html_e( 'Back to Importer Dashboard', 'advanced-wc-csv-importer' ); ?></button>
			</div>
		</div>

	</div>
</div>
