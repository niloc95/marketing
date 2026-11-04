<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * The newsletter list. Written only through NewsletterService; see the
 * migration for what each column means.
 */
class NewsletterSubscriberModel extends Model
{
    public const STATUS_PENDING      = 'pending';
    public const STATUS_SUBSCRIBED   = 'subscribed';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    /** local.webscheduler.co.za and local.webscheduler.app (Config\Sites keys). */
    public const SOURCE_ZA  = 'za';
    public const SOURCE_APP = 'app';

    /** The brand site, webscheduler.co.za, posting its footer form to us. */
    public const SOURCE_BRAND = 'brand';

    public const SOURCES = [self::SOURCE_ZA, self::SOURCE_APP, self::SOURCE_BRAND];

    protected $table         = 'xs_newsletter_subscribers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'email', 'source', 'status', 'confirm_token_hash', 'confirm_expires', 'confirm_sent_at',
        'unsubscribe_token', 'consent_at', 'consent_source', 'unsubscribed_at',
    ];
}
