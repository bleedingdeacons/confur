<?php

/*
Plugin Name: Confur
Plugin URI:
Description: Automated collation of answers to questions for conference.
Version: 1.0
Author: The Bleeding Deacons
Author URI:
License: MIT
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
	exit;
}

// Include the steps and traditions functionality
require_once plugin_dir_path(__FILE__) . 'stepsandtraditions.php';

// Include general functionality
require_once plugin_dir_path(__FILE__) . 'general.php';

// Include answers functionality
require_once plugin_dir_path(__FILE__) . 'answers.php';

// Include reporting functionality
require_once plugin_dir_path(__FILE__) . 'reporting.php';

// Register shortcodes
add_action('init', 'confur_register_shortcodes');

function confur_register_shortcodes() {

	// Steps and Traditions shortcodes
	add_shortcode('step', 'generate_step');
	add_shortcode('tradition', 'generate_tradition');

	// General shortcodes
	add_shortcode('open_new_link', 'open_blank');
	add_shortcode('open_email', 'link_email');
	add_shortcode('pdf_link', 'generate_pdf_link');

	// Answer shortcodes
	add_shortcode('answer', 'generate_answer_field');
	add_shortcode('committee', 'generate_committee');
	add_shortcode('start_committee', 'generate_start_committee');
	add_shortcode('end_committee', 'generate_end_committee');
	add_shortcode('question', 'generate_question');
	add_shortcode('header', 'generate_header');
	add_shortcode('configure_form', 'configure_custom_form');
	add_shortcode('status', 'generate_status');
	add_shortcode('progress_table', 'generate_progress_table');
	add_shortcode('control', 'generate_control');
	add_shortcode('days_remaining', 'generateDaysRemaining');

	// Reporting shortcodes
	add_shortcode('answer_report', 'generate_report');
}