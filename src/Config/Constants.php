<?php

namespace Confur\Config;

/**
 * Plugin constants
 */
class Constants
{
	// Date and format constants
	public const DATE_FORMAT = 'd/m/Y';

	// Answer constants
	public const REGISTER_QUESTION_FORM = 'registration--q4c';
	public const STATUS_FIELD = 'state';
	public const STATUS_DRAFT = 'Draft';
	public const STATUS_COMPLETED = 'Complete';
	public const STATUS_CANCELLED = 'Cancelled';
	public const DEFAULT_STATUS = self::STATUS_DRAFT;
	public const TOKEN = 'answer_submission_token';
	public const ACTION = 'answer_submission';
	public const ANSWER_CUSTOM_TYPE = 'Answer';
	public const MEETING_FIELD = 'meeting';
	public const UPDATED_FIELD = 'updated';
	public const EMAIL_FIELD = 'email';

	// Email constants
	public const REGISTRATION_RECIPIENT_EMAIL = 'email';
	public const REGISTRATION_REPLY_EMAIL = 'conference@aa-bristol.org';
	public const SUPPORT_EMAIL = 'support@aa-bristol.org';
	public const SERVICE_EMAIL_LINK = 'service@aa-bristol.org';

	// Position constants
	public const POSITION_CUSTOM_TYPE = 'intergroup-position';
	public const SERVICE_EXPIRE_MONTHS_WARNING = '6';
	public const ANONYMOUS_NAME = 'about-layout-group_anonymous-name';
	public const SHOW_ANONYMOUS_NAME = 'about-layout-group_show-anonymous-name';
	public const SHOW_MEMBER_PROFILE = 'about-layout-group_show-member-profile';
	public const INTERGROUP_POSITION = 'service-layout-group_intergroup-position';
	public const INTERGROUP_POSITION_ROTATION = 'service-layout-group_intergroup-position-rotation';
	public const POSITION_GENERIC_EMAIL_ADDRESS = 'position-generic-email-address';
	public const POSITION_SUMMARY = 'position-summary';
	public const POSITION_SHORT_DESCRIPTION = 'position-short-description';
	public const POSITION_MINIMUM_SOBRIETY = 'position-minimum-sobriety';
	public const POSITION_TERM_YEARS = 'position-term-years';

	// Announcement constants
	public const ANNOUNCEMENT_CUSTOM_TYPE = 'announcement';
	public const ANNOUNCEMENT_TITLE = 'general-group_article-title';
	public const ANNOUNCEMENT_HIDE = 'general-group_hide';
	public const ANNOUNCEMENT_END_DATE = 'general-group_end-date';
	public const ANNOUNCEMENT_BODY = 'announcement-body';
	public const ANNOUNCEMENT_LOCATION = 'announcement-location_map';
	public const ANNOUNCEMENT_SHOW_MAP = 'announcement-location_show-map';
	public const ANNOUNCEMENT_RELATED_MEETING = 'related-meeting';

	/**
	 * Initialize constants (for backward compatibility if needed)
	 */
	public static function init(): void
	{
		// Legacy constant definitions can be added here if needed for compatibility
	}
}