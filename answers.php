<?php

define('REGISTER_QUESTION_FORM', 'registration--q4c');
define('STATUS_FIELD', 'state');
define('STATUS_DRAFT', 'Draft');
define('STATUS_COMPLETED', 'Complete');
define('STATUS_CANCELLED', 'Cancelled');
define('DEFAULT_STATUS', STATUS_DRAFT);
define('TOKEN', 'answer_submission_token');
define('REGISTRATION_RECIPENT_EMAIL', 'email');
define('REGISTRATION_REPLY_EMAIL', 'conference@aa-bristol.org');
define('ACTION', 'answer_submission');
define('ANSWER_CUSTOM_TYPE', 'Answer');
define('SUPPORT_EMAIL', 'support@aa-bristol.org');
define('MEETING_FIELD', 'meeting');
define('UPDATED_FIELD', 'updated');
define('EMAIL_FIELD', 'email');

add_action('admin_post_nopriv_answer_submission', 'handle_answer_submission');

add_action('admin_post_answer_submission', 'handle_answer_submission');

add_action( 'df_after_insert_post', 'handle_after_insert', 10, 2);

add_action('wp_enqueue_scripts', 'inject_admin_url_to_scripts');

add_action('rest_api_init', 'register_answer_api');

function register_answer_api() {
	register_rest_route('answer/v1', '/status/(?P<name>[a-zA-Z0-9_-]+)', [
		'methods' => 'GET',
		'callback' => 'get_answer_post_status',
		'args' => [
			'name' => [
				'required' => true,
				'validate_callback' => function ($param) {
					return is_string($param) && preg_match('/^[a-zA-Z0-9_-]+$/', $param);
				},
			],
		],
	]);
}

function get_answer_post_status($request) {
	$post_name = sanitize_text_field($request['name']);

	if (empty($post_name)) {
		error_log('[get_answer_post_status] Empty post name received');
		return new WP_Error('invalid_request', 'Post name is required.', ['status' => 400]);
	}

	$post = get_page_by_path($post_name, OBJECT, ANSWER_CUSTOM_TYPE);
	if (!$post) {
		error_log("[get_answer_post_status] Post not found: $post_name");
		return new WP_Error('invalid_post', 'The specified post does not exist.', ['status' => 404]);
	}

	return new WP_REST_Response(get_answer_status($post->ID), 200);
}

function get_answer_status($post_id) {

	$status = get_field(STATUS_FIELD, $post_id);

	error_log($status);

	if (empty($status)) {

		update_field(STATUS_FIELD, STATUS_DRAFT, $post_id);

		acf_save_post();

		$status = get_field(STATUS_FIELD, $post_id);

	}

	$updated = get_field(UPDATED_FIELD, $post_id);

	if (empty($updated)) {

		$updated = 'N/A';

	}

	return ['id' => $post_id,'state' => $status, 'updated' => $updated];

}

function generate_progress_table($atts, $content) {

	$html = '<div id="progress"><h3>Progress</h3>';

	$html .= '<table><tbody>';

	for ($count = 1; $count <= 6; $count++) {

		$html .= "<tr><td><a href=\"#g_c{$count}\" class=\"status-link\"><strong>Committee No. {$count}</a></strong></td><td id=\"s_c{$count}\">Not Started</td></tr>";

	}

	$html .= '</tbody></table></div>';

	return $html;

}

function generate_status($atts, $content) {

	$html = '<p class="middle important" id="' . $atts['position'] . 'Dirty">You have made unsaved changes!</p>';

	//$html .= '<div class="status">' . $last_saved . $state . $save . $finished . '</div>';

	return $html;
}

function generate_control($atts, $content) {

	$html = "<div><strong>Status: </strong><span id=\"{$atts['position']}SaveState\"></span></div>";

	$html .= "<div><strong>Last Saved: </strong><span id=\"{$atts['position']}SaveTime\"></span></div>";

	$html .= '<div id="buttons">';

	$html .= "<button type=\"button\" class=\"submit\"id=\"{$atts['position']}Submit\" name=\"submit_answers\" value=\"Draft\" disabled>Save Draft</button>";

	$html .= "<button type=\"button\" class=\"submit\" id=\"{$atts['position']}Finish\" name=\"submit_answers\" value=\"Complete\" disabled>Save Complete</button>";

	$html .= '</div>';

	return $html;
}

function inject_admin_url_to_scripts() {

	wp_add_inline_script('jquery', 'var endpoints = { adminUrl: "' . esc_url(admin_url('admin-post.php')) . '", ajaxUrl: "' . esc_url(admin_url('admin-ajax.php')) . '" };');

}

function configure_custom_form($atts, $content) {

	error_log('Action: ' . $atts['action']);

// 	wp_nonce_field($atts['action'], $atts['field']);

	$hidden = '<input type="hidden" name="post_id" value="'. get_the_ID() . '"/>';
	$action = '<input type="hidden" name="action" value="'. $atts['action'] .'">';

	return $hidden . $action;

}

function handle_after_insert($form_id, $postid) {

	error_log('handle_after_insert triggered');

	if (REGISTER_QUESTION_FORM == $form_id) {

		error_log(REGISTER_QUESTION_FORM);

		$meetingid = get_field(MEETING_FIELD, $postid);

		$email = get_field(REGISTRATION_RECIPENT_EMAIL, $postid);

		if (empty($meetingid)) {

			error_log("Error: No meeting group set for post ID: $postid");

			$error_subject = 'Error: Missing Meeting Group';

			$error_body = '<p>There was an issue with your registration: No meeting group was given.</p>';

			$params = ['content' => $error_body];

			send_custom_email($email, SUPPORT_EMAIL, $error_subject, $params);

			return;
		}

		$meeting_name = get_the_title($meetingid);

		$slug = generate_unique_slug($meeting_name);

		$title = 'Answers from ' . $meeting_name;

		update_field(STATUS_FIELD, DEFAULT_STATUS);

		acf_save_post();

		wp_update_post([
			'ID'         => $postid,
			'post_title' => $title,
			'post_name'  => $slug
		]);

		$url = get_permalink($postid);

		send_registration_confirmation($email, $meeting_name, $url);

	}
}

function generate_unique_slug($page_title) {

	if (empty($page_title)) {
		error_log('[generate_unique_slug] Empty page title received');
		return false;
	}

	try {
		$prefix = substr(hash('sha256', random_bytes(16)), 0, 16);

	} catch (Exception $e) {

		error_log('[generate_unique_slug] Error generating prefix: ' . $e->getMessage());

		return false;
	}

	$page_title = sanitize_title($page_title);

	$suffix = str_replace('-', '_', $page_title);

	return $prefix . '_' . $suffix;

}

function generate_answer_field($atts = array()) {

	$committee = isset($atts['committee']) ? sanitize_text_field($atts['committee']) : '';

	$question = isset($atts['question']) ? sanitize_text_field($atts['question']) : '';

	$name = 'c' . esc_attr($committee) . '_a' . esc_attr($question);

	$existing_value = get_value($name);

	$safe_value = !empty($existing_value) ? esc_textarea($existing_value) : '';

	$label = '<label class="answer" for="' . esc_attr($name) . '">Answer ' . esc_html($committee) . '.' . esc_html($question) . '</label>';

	$textarea = '<textarea class="answer" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" placeholder="">' . $safe_value . '</textarea>';

	$textarea_existing = '<textarea class="existing-answer" id="e_' . esc_attr($name) . '" readonly>' . $safe_value . '</textarea>';

	return $label . $textarea . $textarea_existing;
}


function get_value($name) {

	$value = get_field($name);

	return sanitize_textarea_field($value);

}

function generate_question($atts = array(), $content = null) {

	$question = trim($atts['number']);

	$committee = trim($atts['committee']);

	$name = 'c' . $committee  . '_q' . $question;

	$content = do_shortcode($content);

	return '<h3 id="' . $name . '">Question ' . $committee . '.' . $question . '</h3>' . $content;

}

function generate_committee($atts = array(), $content = null) {

	$number = trim($atts['number']);

	$id = 'c' . $number;

	$status = '<span class="progress" id="s_' . $id . '"></span>';

	return '<h2 class="committee" id="' . $id . '">Committee ' . $number . ' ' . $status . '</h2>';

}

function generate_start_committee($atts = array(), $content = null) {

	$number = trim($atts['number']);

	$id = 'c' . $number;

	$start = '<div id="' . $id . '">';

	$heading = '<h2>Committee ' . $number . '</h2>';

	return $start . $heading;

}

function generate_end_committee() {

	return '</div>';

}

function generate_header() {

	return '<h2>Results from the ' . get_the_title(get_field('meeting')) . ' Group</h2>';
}

function handle_answer_submission() {

	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_answers'])) {
		error_log('handle_answer_submission Invalid request method or missing submit_answers field');
		wp_send_json_error(['message' => 'Unrecognized Action.'], 400);
		return;
	}

	if (!isset($_POST['post_id']) || !is_numeric($_POST['post_id'])) {
		error_log('handle_answer_submission Invalid or missing post ID');
		wp_send_json_error(['message' => 'Invalid Post ID.'], 400);
		return;
	}

	$post_id = intval($_POST['post_id']);

	if (get_post_status($post_id) === false) {
		error_log("handle_answer_submission Post ID: $post_id does not exist");
		wp_send_json_error(['message' => 'Post does not exist.'], 404);
		return;
	} else {

		$subject = "POST";
		if (isset($_SERVER['REQUEST_URI'])) {
			$url = get_permalink($post_id);
			$subject = $url;
		}

		send_backup_email('backup@aa-bristol.org', 'support@aa-bristol.org', $subject, serialize($_POST));

	}

	error_log("handle_answer_submission Processing answers for Post ID: $post_id");

	foreach ($_POST as $key => $new_value) {

		if (preg_match('/^c\d+_a\d+$/', $key)) {

			$sanitized_value = sanitize_textarea_field($new_value);

			error_log($key . ' = ' . $new_value);

			$existing = get_value($key);

			if ($existing !== $sanitized_value) {
				error_log("handle_answer_submission Updating field $key current value: '{$existing}' new value: '{$sanitized_value}'");
				if (!update_field($key, $sanitized_value, $post_id)) {
					error_log("handle_answer_submission Failed to update field $key for Post ID: $post_id");
				}
			}
		}
	}

	$valid_statuses = ['Draft', 'Complete'];
	$status = isset($_POST['submit_answers']) && in_array($_POST['submit_answers'], $valid_statuses)
		? sanitize_text_field($_POST['submit_answers'])
		: 'Draft';

	error_log('Status: ' . $status);

	$updated = current_time('l, Y-m-d h:i:s A');

	update_field(UPDATED_FIELD, esc_html($updated), $post_id);

	update_field(STATUS_FIELD, esc_html($status), $post_id);

	$meeting_id = get_field(MEETING_FIELD, $post_id);

	$meeting_name = get_the_title($meeting_id);

	$email = get_field(EMAIL_FIELD, $post_id);

	acf_save_post();

	wp_publish_post($post_id);

	if ($status == STATUS_COMPLETED) {
		send_completetion_thanks($email, $meeting_name);
	}

	wp_send_json_success([
		'message' => "Answers Saved as $status",
		'updated' => $updated,
		'state'   => $status
	]);
}


function send_registration_confirmation($recipient, $meeting_name, $registered_url) {
	error_log('send_registration_confirmation Function triggered');

	$recipient = sanitize_email($recipient);
	if (!is_email($recipient)) {
		error_log('send_registration_confirmation Invalid email: ' . esc_html($recipient));
		return;
	}

	$body = '<h3>Welcome</h3><p>Hello ' . esc_html($meeting_name) . ' your are all set to enter your answers.</p><p>To get started ' . create_link($registered_url, '', 'View Questions') . ' you do not need to start entering answers straight away.</p>';

	$params = ['content' => $body];
	$from = 'Bristol and District <' . REGISTRATION_REPLY_EMAIL . '>';

	if (!send_custom_email($recipient, $from, 'Registration Successful', $params)) {
	}
}

function send_completetion_thanks($recipient, $meeting_name) {
	error_log('send_completetion_thanks Function triggered');

	$recipient = sanitize_email($recipient);
	if (!is_email($recipient)) {
		error_log('send_completetion_thanks Invalid email: ' . esc_html($recipient));
		return;
	}

	$body = '<h3>Complete!</h3><p>Many thanks to ' . esc_html($meeting_name) . ' for taking the time to give your feedback, the conference committee is very greatful.</p><p>If you have made a mistake or want to change something, you can still make alterations and Save Complete again.</p>';

	$params = ['content' => $body];
	$from = 'Bristol and District <' . REGISTRATION_REPLY_EMAIL . '>';

	if (!send_custom_email($recipient, $from, 'All Questions Completed', $params)) {
		error_log('send_completetion_thanks Failed to send email to: ' . esc_html($recipient));
	}
}


function send_backup_email($recipient_email, $from, $subject, $body) {

	error_log($body);

	$headers = [
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . $from
	];

	$is_sent = wp_mail($recipient_email, $subject, $body, $headers);

	if(!$is_sent) {
		error_log('Send backup email failed!');
	}

	return $is_sent;
}

function generateDaysRemaining($atts) {
	// Define default attributes, including 'extend_by'
	$atts = shortcode_atts(
		array(
			'end_date'  => '',  // Expected: "YYYY-MM-DD" or "YYYY-MM-DD HH:MM" (24-hour clock)
			'extend_by' => 0    // Number of days to extend the deadline (optional)
		),
		$atts,
		'generateDaysRemaining'
	);

	// Ensure the end_date attribute is provided
	if (empty($atts['end_date'])) {
		return 'Please provide an end date.';
	}

	// Get the WordPress timezone as a DateTimeZone object
	$timezone = wp_timezone();

	// Trim the input date string
	$end_date_string = trim($atts['end_date']);
	$end_date_obj = false;

	/*
	 * If the input string contains a colon, we assume a time is included
	 * and try to parse using 24-hour clock formats.
	 */
	if (strpos($end_date_string, ':') !== false) {
		// List of common 24-hour date-time formats to try
		$formats = array(
			'Y-m-d H:i',
			'Y-m-d H:i:s'
		);
		foreach ($formats as $format) {
			$end_date_obj = DateTime::createFromFormat($format, $end_date_string, $timezone);
			if ($end_date_obj !== false) {
				break;
			}
		}
		// If none of the formats worked, try the generic constructor as a fallback.
		if ($end_date_obj === false) {
			try {
				$end_date_obj = new DateTime($end_date_string, $timezone);
			} catch (Exception $e) {
				return 'Invalid date format. Use "YYYY-MM-DD" or "YYYY-MM-DD HH:MM" (24-hour format).';
			}
		}
	} else {
		// No time provided; assume format is "YYYY-MM-DD"
		$end_date_obj = DateTime::createFromFormat('Y-m-d', $end_date_string, $timezone);
		if ($end_date_obj === false) {
			try {
				$end_date_obj = new DateTime($end_date_string, $timezone);
			} catch (Exception $e) {
				return 'Invalid date format. Use "YYYY-MM-DD" or "YYYY-MM-DD HH:MM" (24-hour format).';
			}
		}
	}

	// Apply extension if provided (casting to integer)
	$extend_by = intval($atts['extend_by']);
	if ($extend_by > 0) {
		$end_date_obj->modify("+{$extend_by} days");
	}

	// Choose a display format:
	// - If the time is exactly midnight, display only the date.
	// - Otherwise, display the date with time in 24‑hour format.
	if ($end_date_obj->format('H:i:s') === '00:00:00') {
		$formatted_date = $end_date_obj->format('d/m/Y');
	} else {
		$formatted_date = $end_date_obj->format('d/m/Y H:i');
	}

	// Get the current time using the WordPress timezone
	$current_time = new DateTime('now', $timezone);

	// Calculate the difference in seconds between the deadline and now
	$diff_seconds = $end_date_obj->getTimestamp() - $current_time->getTimestamp();

	// If the deadline has already passed, inform the user
	if ($diff_seconds < 0) {
		return 'The date has already passed.';
	}

	// If less than 24 hours remain, show hours remaining; otherwise, show days remaining.
	if ($diff_seconds < 24 * 60 * 60) {
		$hours_remaining = ceil($diff_seconds / 3600);
		$time_remaining_text = "<strong>$hours_remaining</strong> hours remaining.";
	} else {
		$days_remaining = ceil($diff_seconds / (60 * 60 * 24));
		$time_remaining_text = "<strong>$days_remaining</strong> days remaining.";
	}

	// Prepare an extension message if days were added
	$extension_text = ($extend_by > 0)
		? " (extended by $extend_by day" . ($extend_by > 1 ? 's' : '') . ")"
		: '';

	// Return the result as HTML
	return "<strong>Deadline:</strong> $formatted_date$extension_text - $time_remaining_text";
}