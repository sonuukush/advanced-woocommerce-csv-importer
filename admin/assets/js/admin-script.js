/**
 * Advanced WooCommerce CSV Importer JS Wizard Controller
 */
(function($) {
	'use strict';

	var ImporterWizard = {
		currentStep: 'upload', // upload, mapping, progress, report
		jobId: null,
		pollInterval: null,
		batchSize: 250,

		init: function() {
			this.bindEvents();
		},

		bindEvents: function() {
			var self = this;

			// Drag & Drop Highlight
			$('.drag-drop-zone').on('dragover', function(e) {
				e.preventDefault();
				$(this).addClass('dragover');
			}).on('dragleave', function() {
				$(this).removeClass('dragover');
			});

			// File Upload Event
			$('#csv_file_input').on('change', function(e) {
				self.handleFileUpload(e.target.files[0]);
			});

			// Save Column Mapping & Start Import
			$(document).on('click', '#btn_start_import', function() {
				self.startImport();
			});

			// Pause/Resume Import
			$(document).on('click', '#btn_toggle_import', function() {
				self.toggleImport();
			});

			// Cancel Import
			$(document).on('click', '#btn_cancel_import', function() {
				self.cancelImport();
			});

			// Retry Failed rows
			$(document).on('click', '.btn-retry-failed', function() {
				var jid = $(this).data('job-id');
				self.retryFailed(jid);
			});
		},

		updateWizardSteps: function(step) {
			this.currentStep = step;
			$('.step-node').removeClass('active completed');

			if (step === 'upload') {
				$('#step_upload').addClass('active');
			} else if (step === 'mapping') {
				$('#step_upload').addClass('completed');
				$('#step_mapping').addClass('active');
			} else if (step === 'progress') {
				$('#step_upload').addClass('completed');
				$('#step_mapping').addClass('completed');
				$('#step_progress').addClass('active');
			} else if (step === 'report') {
				$('#step_upload').addClass('completed');
				$('#step_mapping').addClass('completed');
				$('#step_progress').addClass('completed');
				$('#step_report').addClass('active');
			}
		},

		handleFileUpload: function(file) {
			if (!file) return;

			var self = this;
			var formData = new FormData();
			formData.append('action', 'wc_csv_upload_file');
			formData.append('security', wcCsvImporter.nonce);
			formData.append('csv_file', file);

			// Show loading status in Drag & Drop Panel
			$('.drag-drop-zone').addClass('loading');
			$('.drag-drop-zone p').text(wcCsvImporter.i18n.uploading);

			$.ajax({
				url: wcCsvImporter.ajaxUrl,
				type: 'POST',
				data: formData,
				contentType: false,
				processData: false,
				success: function(response) {
					$('.drag-drop-zone').removeClass('loading');
					if (response.success) {
						self.jobId = response.data.job_id;
						self.loadMappingStep(response.data.headers);
					} else {
						alert(response.data || 'Upload failed.');
						location.reload();
					}
				},
				error: function() {
					$('.drag-drop-zone').removeClass('loading');
					alert('Connection error occurred.');
				}
			});
		},

		loadMappingStep: function(headers) {
			var self = this;
			self.updateWizardSteps('mapping');

			// Hide upload panel, show mapping panel
			$('#panel_upload').addClass('hidden');
			$('#panel_mapping').removeClass('hidden');

			var $tableBody = $('#mapping_table_body');
			$tableBody.empty();

			// Predefined standard WooCommerce fields
			var wcFields = {
				'': 'Do Not Import',
				'name': 'Product Name',
				'sku': 'SKU',
				'slug': 'Slug',
				'type': 'Product Type (simple, variable...)',
				'description': 'Description',
				'short_description': 'Short Description',
				'regular_price': 'Regular Price',
				'sale_price': 'Sale Price',
				'stock': 'Stock Quantity',
				'stock_status': 'Stock Status',
				'categories': 'Categories (Hierarchical)',
				'tags': 'Tags',
				'brand': 'Brand',
				'weight': 'Weight',
				'length': 'Length (Dimensions)',
				'width': 'Width (Dimensions)',
				'height': 'Height (Dimensions)',
				'featured_image': 'Featured Image URL',
				'gallery_images': 'Gallery Image URLs (comma separated)',
				'seo_title': 'SEO Title',
				'seo_description': 'SEO Description',
				'status': 'Product Status (publish, draft...)',
				'visibility': 'Catalog Visibility',
				'external_url': 'External URL',
				'button_text': 'External Button Text'
			};

			// Add custom attributes & metadata entries option
			var customOptions = '<optgroup label="Custom Fields">';
			customOptions += '<option value="meta_custom_field">New Custom Meta (rename below)</option>';
			customOptions += '<option value="attribute_custom">New Custom Attribute (rename below)</option>';
			customOptions += '</optgroup>';

			headers.forEach(function(header) {
				var $tr = $('<tr>');
				$tr.append($('<td>').text(header).addClass('csv-header-name'));

				var $select = $('<select>').addClass('mapping-select');
				for (var value in wcFields) {
					var $opt = $('<option>').val(value).text(wcFields[value]);

					// Try intelligent auto-matching
					var cleanHeader = header.toLowerCase().replace(/[^a-z0-9]/g, '');
					var cleanValue = value.toLowerCase().replace(/[^a-z0-9]/g, '');
					if (cleanHeader === cleanValue || (cleanValue && cleanHeader.includes(cleanValue))) {
						$opt.attr('selected', 'selected');
					}

					$select.append($opt);
				}

				// Allow custom meta input text boxes if needed
				var $tdSelect = $('<td>').append($select);
				$tr.append($tdSelect);

				$tableBody.append($tr);
			});
		},

		startImport: function() {
			var self = this;
			var mappings = {};

			$('#mapping_table_body tr').each(function() {
				var header = $(this).find('.csv-header-name').text();
				var mappedVal = $(this).find('.mapping-select').val();
				if (mappedVal) {
					mappings[header] = mappedVal;
				}
			});

			var duplicateHandle = $('#duplicate_handle').val();
			var importMode = $('#import_mode').val();
			self.batchSize = parseInt($('#batch_size').val()) || 250;

			// Request validation scanning first
			self.updateWizardSteps('progress');
			$('#panel_mapping').addClass('hidden');
			$('#panel_progress').removeClass('hidden');
			
			// Start pre-import validation scanning UI status
			$('#progress_status_text').text(wcCsvImporter.i18n.validating);
			$('#progress_percentage_text').text('0%');

			$.ajax({
				url: wcCsvImporter.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wc_csv_save_mapping_validate',
					security: wcCsvImporter.nonce,
					job_id: self.jobId,
					mapping: JSON.stringify(mappings),
					duplicate_handle: duplicateHandle,
					import_mode: importMode,
					batch_size: self.batchSize
				},
				success: function(response) {
					if (response.success) {
						// Render validation card
						var report = response.data.validation_report;
						var $reportDiv = $('#validation_pre_report');
						$reportDiv.empty().removeClass('hidden');

						var valCardClass = report.invalid_rows > 0 ? 'invalid' : 'valid';
						var $card = $('<div>').addClass('validation-card ' + valCardClass);
						$card.append('<strong>Validation Complete:</strong> Total Rows: ' + report.total_rows + ' | Valid Rows: ' + report.valid_rows + ' | Invalid Rows: ' + report.invalid_rows);
						
						if (report.invalid_rows > 0) {
							$card.append('<br><a href="' + report.invalid_csv_url + '" class="importer-btn secondary" style="margin-top:10px; padding:6px 12px; font-size:0.85rem;" target="_blank">Download Invalid Rows CSV</a>');
						}
						$reportDiv.append($card);

						// Now start the action scheduler import
						self.triggerBackgroundJob();
					} else {
						alert(response.data || 'Validation failed.');
						location.reload();
					}
				},
				error: function() {
					alert('Connection error during validation.');
				}
			});
		},

		triggerBackgroundJob: function() {
			var self = this;
			$.ajax({
				url: wcCsvImporter.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wc_csv_trigger_import',
					security: wcCsvImporter.nonce,
					job_id: self.jobId,
					batch_size: self.batchSize
				},
				success: function(response) {
					if (response.success) {
						$('#progress_status_text').text(wcCsvImporter.i18n.processing);
						self.startPolling();
					} else {
						alert(response.data || 'Failed to start background import.');
					}
				}
			});
		},

		startPolling: function() {
			var self = this;
			// Clear existing
			if (self.pollInterval) clearInterval(self.pollInterval);

			self.pollInterval = setInterval(function() {
				self.pollProgress();
			}, 2000); // Poll every 2 seconds
		},

		pollProgress: function() {
			var self = this;
			$.ajax({
				url: wcCsvImporter.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wc_csv_get_progress',
					security: wcCsvImporter.nonce,
					job_id: self.jobId
				},
				success: function(response) {
					if (response.success) {
						var job = response.data;
						self.updateProgressUI(job);

						if (job.status === 'completed') {
							clearInterval(self.pollInterval);
							self.loadReportStep(job);
						} else if (job.status === 'failed') {
							clearInterval(self.pollInterval);
							alert('Job failed or cancelled.');
							location.reload();
						}
					}
				}
			});
		},

		updateProgressUI: function(job) {
			var total = parseInt(job.total_rows) || 0;
			var processed = parseInt(job.processed_rows) || 0;
			var failed = parseInt(job.failed_rows) || 0;
			var completed = processed + failed;

			var percent = total > 0 ? Math.min(100, Math.round((completed / total) * 100)) : 0;

			// Update Bar
			$('.progress-bar-fill').css('width', percent + '%');
			$('#progress_percentage_text').text(percent + '%');

			// Update Metrics
			$('#metric_processed').text(processed);
			$('#metric_failed').text(failed);
			$('#metric_remaining').text(Math.max(0, total - completed));

			// Calculate ETA
			var eta = 'Calculating...';
			if (completed > 0) {
				var now = new Date().getTime();
				// Estimate time based on start timestamp and speed
				var secondsElapsed = 2; // approximation since last check
				var speed = completed / secondsElapsed; // records per second
				var remaining = total - completed;
				var secondsRemaining = Math.round(remaining / (completed / 10)); // rough damping estimation
				
				if (secondsRemaining > 0) {
					var minutes = Math.floor(secondsRemaining / 60);
					var seconds = secondsRemaining % 60;
					eta = minutes + 'm ' + seconds + 's';
				} else {
					eta = '0s';
				}
			}
			$('#metric_time').text(eta);

			// Pause/Resume Buttons update
			if (job.status === 'paused') {
				$('#progress_status_text').text('Paused');
				$('#btn_toggle_import').text('Resume Import').removeClass('secondary').addClass('primary');
			} else {
				$('#progress_status_text').text(wcCsvImporter.i18n.processing);
				$('#btn_toggle_import').text('Pause Import').removeClass('primary').addClass('secondary');
			}
		},

		toggleImport: function() {
			var self = this;
			$.ajax({
				url: wcCsvImporter.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wc_csv_toggle_job',
					security: wcCsvImporter.nonce,
					job_id: self.jobId,
					batch_size: self.batchSize
				},
				success: function(response) {
					if (response.success) {
						self.pollProgress();
					}
				}
			});
		},

		cancelImport: function() {
			if (!confirm(wcCsvImporter.i18n.confirmStop)) return;

			var self = this;
			clearInterval(self.pollInterval);

			$.ajax({
				url: wcCsvImporter.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wc_csv_cancel_job',
					security: wcCsvImporter.nonce,
					job_id: self.jobId
				},
				success: function(response) {
					location.reload();
				}
			});
		},

		loadReportStep: function(job) {
			this.updateWizardSteps('report');
			$('#panel_progress').addClass('hidden');
			$('#panel_report').removeClass('hidden');

			// Populate numbers
			$('#report_total').text(job.total_rows);
			$('#report_processed').text(job.processed_rows);
			$('#report_failed').text(job.failed_rows);

			if (job.failed_rows > 0) {
				$('.failed-rows-container').removeClass('hidden');
				$('#btn_retry_failed_job').data('job-id', job.id);
				
				// Download buttons
				var downloadLogsUrl = wcCsvImporter.ajaxUrl + '?action=wc_csv_download_failed_logs&security=' + wcCsvImporter.nonce + '&job_id=' + job.id;
				$('#btn_download_failed_logs').attr('href', downloadLogsUrl).removeClass('hidden');
			} else {
				$('.failed-rows-container').addClass('hidden');
				$('#btn_download_failed_logs').addClass('hidden');
			}
		},

		retryFailed: function(jobId) {
			var self = this;
			self.jobId = jobId;
			
			// Switch UI back to progress
			self.updateWizardSteps('progress');
			$('#panel_report').addClass('hidden');
			$('#panel_progress').removeClass('hidden');

			$('#progress_status_text').text('Retrying failed rows...');
			$('.progress-bar-fill').css('width', '0%');

			$.ajax({
				url: wcCsvImporter.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wc_csv_retry_failed_job',
					security: wcCsvImporter.nonce,
					job_id: jobId
				},
				success: function(response) {
					if (response.success) {
						self.startPolling();
					} else {
						alert(response.data || 'Failed to trigger retries.');
						location.reload();
					}
				}
			});
		}
	};

	$(document).ready(function() {
		ImporterWizard.init();
	});

})(jQuery);
