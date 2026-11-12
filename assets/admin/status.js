/* Questions for Conference → Status. Needs confurStatus (nonces), set by StatusAdminPage.
 * Loaded only on that screen. Was inline in the PHP until the Phase 4 refactor. */
jQuery(document).ready(function($) {
	$('.cancel-duplicate-btn').on('click', function(e) {
		e.preventDefault();
		
		var button = $(this);
		var answerId = button.data('answer-id');
		var groupName = button.data('group-name');
		var row = button.closest('tr');
		
		if (!confirm('Are you sure you want to cancel this duplicate registration for "' + groupName + '"?')) {
			return;
		}
		
		button.prop('disabled', true).addClass('loading');
		button.find('.btn-text').text('Cancelling...');
		
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'confur_cancel_duplicate',
				answer_id: answerId,
				nonce: confurStatus.cancelNonce
			},
			success: function(response) {
				if (response.success) {
					// Determine the old status before updating
					var statusBadge = row.find('.status-badge');
					var oldStatus = '';
					if (statusBadge.hasClass('status-completed')) {
						oldStatus = 'completed';
					} else if (statusBadge.hasClass('status-draft')) {
						oldStatus = 'draft';
					} else if (statusBadge.hasClass('status-not-started')) {
						oldStatus = 'not-started';
					}
					
					// Update the row to show cancelled status
					row.removeClass('duplicate-row registered-row').addClass('cancelled-row');
					statusBadge
						.removeClass('status-completed status-draft status-not-started')
						.addClass('status-cancelled')
						.text('Cancelled');
					button.remove();
					row.find('.duplicate-indicator').remove();
					
					// Check if only one active (non-cancelled) registration remains for this group
					// Find all rows with cancel buttons for the same group name
					var remainingDuplicateButtons = $('.cancel-duplicate-btn').filter(function() {
						return $(this).data('group-name') === groupName;
					});
					
					// If only one cancel button remains, remove it and its duplicate indicator
					if (remainingDuplicateButtons.length === 1) {
						var lastRow = remainingDuplicateButtons.closest('tr');
						remainingDuplicateButtons.remove();
						lastRow.find('.duplicate-indicator').remove();
						lastRow.removeClass('duplicate-row').addClass('registered-row');
					}
					
					// Update the statistics counters
					var cancelledStat = $('.stat-box').eq(6).find('.stat-number');
					if (cancelledStat.length) {
						cancelledStat.text(parseInt(cancelledStat.text()) + 1);
					}
					
					// Decrement the old status counter
					if (oldStatus === 'completed') {
						var completedStat = $('.stat-box').eq(3).find('.stat-number');
						if (completedStat.length) {
							completedStat.text(parseInt(completedStat.text()) - 1);
						}
					} else if (oldStatus === 'draft') {
						var draftStat = $('.stat-box').eq(4).find('.stat-number');
						if (draftStat.length) {
							draftStat.text(parseInt(draftStat.text()) - 1);
						}
					} else if (oldStatus === 'not-started') {
						var notStartedStat = $('.stat-box').eq(5).find('.stat-number');
						if (notStartedStat.length) {
							notStartedStat.text(parseInt(notStartedStat.text()) - 1);
						}
					}
					
					// Show success message
					var notice = $('<div class="notice notice-success is-dismissible"><p>Registration cancelled successfully.</p></div>');
					$('.wrap h1').after(notice);
					setTimeout(function() { notice.fadeOut(); }, 3000);
				} else {
					alert('Error: ' + (response.data.message || 'Failed to cancel registration'));
					button.prop('disabled', false).removeClass('loading');
					button.find('.btn-text').text('Cancel');
				}
			},
			error: function() {
				alert('An error occurred while cancelling the registration.');
				button.prop('disabled', false).removeClass('loading');
				button.find('.btn-text').text('Cancel');
			}
		});
	});

	$('.resend-confirmation-btn').on('click', function(e) {
		e.preventDefault();
		
		var button = $(this);
		var answerId = button.data('answer-id');
		var groupName = button.data('group-name');
		
		if (!confirm('Resend confirmation email for "' + groupName + '"?')) {
			return;
		}
		
		button.prop('disabled', true).addClass('loading');
		button.find('.btn-text').text('Sending...');
		
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'confur_resend_confirmation',
				answer_id: answerId,
				nonce: confurStatus.resendNonce
			},
			success: function(response) {
				if (response.success) {
					// Show success message
					var notice = $('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
					$('.wrap h1').after(notice);
					setTimeout(function() { notice.fadeOut(); }, 5000);
					
					button.prop('disabled', false).removeClass('loading');
					button.find('.btn-text').text('Resend Email');
				} else {
					alert('Error: ' + (response.data.message || 'Failed to send confirmation email'));
					button.prop('disabled', false).removeClass('loading');
					button.find('.btn-text').text('Resend Email');
				}
			},
			error: function() {
				alert('An error occurred while sending the confirmation email.');
				button.prop('disabled', false).removeClass('loading');
				button.find('.btn-text').text('Resend Email');
			}
		});
	});
});

// Screen Options: the "Show Cancellations" toggle.
jQuery(document).ready(function($) {
    // Create progress indicator element (same as confur-client.js)
    var progressIndicator = $("<div>").attr("id", "confur-progress-indicator").css({
        display: "none",
        position: "fixed",
        top: 0,
        left: 0,
        width: "100%",
        height: "100%",
        background: "rgba(0, 0, 0, 0.5)",
        zIndex: 9999,
        justifyContent: "center",
        alignItems: "center"
    }).html('<div style="background: white; padding: 30px; border-radius: 8px; text-align: center; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);"><div style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 50px; height: 50px; animation: confur-spin 1s linear infinite; margin: 0 auto 15px;"></div><div id="confur-progress-text" style="font-size: 16px; color: #333;">Updating...</div></div>');
    
    $("body").append(progressIndicator);
    
    // Add CSS animation for spinner
    $("<style>").text("@keyframes confur-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }").appendTo("head");
    
    $("#confur_show_cancellations").on("change", function() {
        var isChecked = $(this).is(":checked") ? "1" : "0";
        
        // Show progress indicator
        $("#confur-progress-indicator").css("display", "flex");
        
        $.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
                action: "confur_save_screen_option",
                nonce: confurStatus.screenOptionsNonce,
                show_cancellations: isChecked
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                }
            }
        });
    });
});
