<?php

namespace Confur\PostTypes;

/**
 * Handle Answer post type and ACF field group registration
 */
class AnswerPostType
{
	/**
	 * Initialize the post type and field group
	 */
	public function init(): void
	{
		// Register post type
		add_action('init', [$this, 'registerPostType']);

		// Register ACF field group
		add_action('acf/include_fields', [$this, 'registerFieldGroup']);
	}

	/**
	 * Register the Answer post type
	 */
	public function registerPostType(): void
	{
		try {
			// Check if post type already exists
			if (post_type_exists('answer')) {
				error_log('AnswerPostType::registerPostType - Post type "answer" already exists, skipping registration');
				return;
			}

			register_post_type('answer', array(
				'labels' => array(
					'name' => 'Answers',
					'singular_name' => 'Answer',
					'menu_name' => 'Questions for Conference',
					'all_items' => 'All Answers',
					'edit_item' => 'Edit Answer',
					'view_item' => 'View Answer',
					'view_items' => 'View Answers',
					'add_new_item' => 'Add New Answer',
					'add_new' => 'Add New Answer',
					'new_item' => 'New Answer',
					'parent_item_colon' => 'Parent Answer:',
					'search_items' => 'Search Answers',
					'not_found' => 'No answer found',
					'not_found_in_trash' => 'No answer found in the bin',
					'archives' => 'Answer Archives',
					'attributes' => 'Answer Attributes',
					'insert_into_item' => 'Insert into answer',
					'uploaded_to_this_item' => 'Uploaded to this answer',
					'filter_items_list' => 'Filter answer list',
					'filter_by_date' => 'Filter answer by date',
					'items_list_navigation' => 'Answer list navigation',
					'items_list' => 'Answers list',
					'item_published' => 'Answer published.',
					'item_published_privately' => 'Answer published privately.',
					'item_reverted_to_draft' => 'Answer reverted to draft.',
					'item_scheduled' => 'Answer scheduled.',
					'item_updated' => 'Answer updated.',
					'item_link' => 'Answer Link',
					'item_link_description' => 'A link to a answer.',
				),
				'description' => 'Questions for Conference Group Answers 2026',
				'public' => true,
				'show_in_rest' => true,
				'menu_icon' => 'dashicons-testimonial',
				'supports' => array(
					'title',
					'editor',
					'revisions',
					'custom-fields',
				),
				'delete_with_user' => false,
			));
		} catch (\Exception $e) {
			error_log('AnswerPostType::registerPostType - Failed to register post type: ' . $e->getMessage());
		}
	}

	/**
	 * Register the ACF field group for Answer post type
	 */
	public function registerFieldGroup(): void
	{
		try {
			// Check if ACF is available
			if (!function_exists('acf_add_local_field_group')) {
				error_log('AnswerPostType::registerFieldGroup - ACF not available');
				return;
			}

			// Check if field group already exists
			if (acf_get_field_group('group_6943990fb706a')) {
				error_log('AnswerPostType::registerFieldGroup - Field group "group_6943990fb706a" already exists, skipping registration');
				return;
			}

			acf_add_local_field_group(array(
				'key' => 'group_6943990fb706a',
				'title' => 'Answer',
				'fields' => array(
					array(
						'key' => 'field_6943990fbfd58',
						'label' => 'Meeting',
						'name' => 'meeting',
						'aria-label' => '',
						'type' => 'post_object',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'post_type' => array(
							0 => 'tsml_meeting',
						),
						'post_status' => array(
							0 => 'publish',
						),
						'taxonomy' => '',
						'return_format' => 'id',
						'multiple' => 0,
						'allow_null' => 0,
						'allow_in_bindings' => 0,
						'bidirectional' => 0,
						'ui' => 1,
						'bidirectional_target' => array(),
					),
					array(
						'key' => 'field_6949ce3043a26',
						'label' => 'Fellow Meeting',
						'name' => 'fellow_meeting',
						'aria-label' => '',
						'type' => 'post_object',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'post_type' => array(
							0 => 'tsml_meeting',
						),
						'post_status' => '',
						'taxonomy' => '',
						'return_format' => 'object',
						'multiple' => 0,
						'allow_null' => 0,
						'allow_in_bindings' => 0,
						'bidirectional' => 0,
						'ui' => 1,
						'bidirectional_target' => array(),
					),
					array(
						'key' => 'field_6943990fc39f4',
						'label' => 'Email',
						'name' => 'email',
						'aria-label' => '',
						'type' => 'email',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'allow_in_bindings' => 0,
						'placeholder' => '',
						'prepend' => '',
						'append' => '',
					),
					array(
						'key' => 'field_6943991007702',
						'label' => 'Committee 1 Answer 1',
						'name' => 'c1_a1',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_694399100b1fa',
						'label' => 'Committee 1 Answer 2',
						'name' => 'c1_a2',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_694399100edd8',
						'label' => 'Committee 1 Answer 3',
						'name' => 'c1_a3',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_6943991034645',
						'label' => 'Committee 2 Answer 1',
						'name' => 'c2_a1',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_69439910381df',
						'label' => 'Committee 2 Answer 2',
						'name' => 'c2_a2',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_694399105636f',
						'label' => 'Committee 3 Answer 1',
						'name' => 'c3_a1',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_6943991059ea4',
						'label' => 'Committee 3 Answer 2',
						'name' => 'c3_a2',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_6943991074252',
						'label' => 'Committee 4 Answer 1',
						'name' => 'c4_a1',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_69439910779ba',
						'label' => 'Committee 4 Answer 2',
						'name' => 'c4_a2',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_69439910918ac',
						'label' => 'Committee 5 Answer 1',
						'name' => 'c5_a1',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_6943991095307',
						'label' => 'Committee 5 Answer 2',
						'name' => 'c5_a2',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_69439910b2d5d',
						'label' => 'Committee 6 Answer 1',
						'name' => 'c6_a1',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_69439910b6812',
						'label' => 'Committee 6 Answer 2',
						'name' => 'c6_a2',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_69439910c8fe1',
						'label' => 'All Committees Answer 1',
						'name' => 'c7_a1',
						'aria-label' => '',
						'type' => 'textarea',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'default_value' => '',
						'maxlength' => '',
						'allow_in_bindings' => 1,
						'rows' => '',
						'placeholder' => '',
						'new_lines' => '',
					),
					array(
						'key' => 'field_6943990fe1ace',
						'label' => 'Updated',
						'name' => 'updated',
						'aria-label' => '',
						'type' => 'date_time_picker',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'display_format' => 'Y-m-d H:i:s',
						'return_format' => 'l, F j, Y g:i A',
						'first_day' => 1,
						'allow_in_bindings' => 0,
						'default_to_current_date' => 0,
					),
					array(
						'key' => 'field_6943990fe54da',
						'label' => 'Completed',
						'name' => 'completed',
						'aria-label' => '',
						'type' => 'date_time_picker',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'display_format' => 'Y-m-d H:i:s',
						'return_format' => 'l, F j, Y g:i A',
						'first_day' => 1,
						'default_to_current_date' => 0,
						'allow_in_bindings' => 0,
					),
					array(
						'key' => 'field_6943990fe8f88',
						'label' => 'State',
						'name' => 'state',
						'aria-label' => '',
						'type' => 'select',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'choices' => array(
							'Draft' => 'Draft',
							'Complete' => 'Complete',
							'Cancelled' => 'Cancelled',
						),
						'default_value' => 'Draft',
						'return_format' => 'value',
						'multiple' => 0,
						'allow_null' => 0,
						'allow_in_bindings' => 0,
						'ui' => 0,
						'ajax' => 0,
						'placeholder' => '',
						'create_options' => 0,
						'save_options' => 0,
					),
				),
				'location' => array(
					array(
						array(
							'param' => 'post_type',
							'operator' => '==',
							'value' => 'answer',
						),
					),
				),
				'menu_order' => 0,
				'position' => 'normal',
				'style' => 'default',
				'label_placement' => 'top',
				'instruction_placement' => 'label',
				'hide_on_screen' => '',
				'active' => true,
				'description' => '',
				'show_in_rest' => 0,
				'display_title' => '',
			));
		} catch (\Exception $e) {
			error_log('AnswerPostType::registerFieldGroup - Failed to register field group: ' . $e->getMessage());
		}
	}
}