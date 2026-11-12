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
    public const ANSWER_CUSTOM_TYPE = 'answer';
    public const GROUP_FIELD = 'group';
    public const FELLOW_GROUP_FIELD = 'fellow_group';
    public const UPDATED_FIELD = 'updated';
    public const EMAIL_FIELD = 'email';
    public const ALLOCATION_FIELD = 'allocated_committee';
}
