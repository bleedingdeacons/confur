<?php

namespace Confur\Config;

/**
 * Plugin constants
 */
class Constants
{
	// Email constants
	public const REGISTRATION_RECIPIENT_EMAIL = 'email';
	// Answer constants
	public const REGISTER_QUESTION_FORM = 'registration--q4c';
	public const COMPLETION_FIELD = 'completed';
	public const STATUS_FIELD = 'state';
	public const STATUS_DRAFT = 'Draft';
	public const STATUS_COMPLETED = 'Complete';
	public const STATUS_CANCELLED = 'Cancelled';
	public const DEFAULT_STATUS = self::STATUS_DRAFT;
	public const TOKEN = 'answer_submission_token';
	public const ACTION = 'answer_submission';
	public const ANSWER_CUSTOM_TYPE = 'answer';
	public const MEETING_FIELD = 'meeting';
	public const FELLOW_MEETING_FIELD = 'fellow_meeting';
	public const UPDATED_FIELD = 'updated';
	public const EMAIL_FIELD = 'email';
	public const ALLOCATION_FIELD = 'allocated_committee';

	/**
	 * Initialize constants (for backward compatibility if needed)
	 */
	public static function init(): void
	{
		// Legacy constant definitions can be added here if needed for compatibility
	}
}