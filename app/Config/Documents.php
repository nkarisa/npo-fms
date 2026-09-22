<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Supporting documents: which records must carry one, which are asked for one,
 * and what files are accepted.
 *
 * A donor audit samples transactions and asks for the source document behind
 * each, so the records it samples first need their document before they move on.
 * Every value can be set from .env as `documents.<name>` (e.g.
 * `documents.journalThreshold = 250000`).
 */
class Documents extends BaseConfig
{
    /** A bill needs the supplier's invoice before it goes for approval. */
    public bool $requireBillInvoice = true;

    /** Surrendering a staff advance needs the receipts for what was spent. */
    public bool $requireAdvanceReceipts = true;

    /**
     * An award needs its signed agreement once it is active: when recorded as
     * Active, or when a pipeline award is converted on signature. A pipeline award
     * may be recorded without one.
     */
    public bool $requireGrantAgreement = true;

    /**
     * A journal whose debits come to more than this needs a supporting document
     * before it goes for approval. Drafts can be saved without one. 0 turns the
     * rule off.
     */
    public float $journalThreshold = 500000;

    /** Journal types that need a supporting document whatever their amount. */
    public array $journalTypes = ['Adjustment'];

    /**
     * Above the three-quote threshold, a quotation counts towards the three only
     * when its document is attached.
     */
    public bool $requireQuotationDocuments = true;

    /** Largest file accepted, in bytes. */
    public int $maxBytes = 10 * 1024 * 1024;

    /** File extensions accepted. */
    public array $types = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'msg', 'eml'];

    /** Hours an uploaded file waits to be attached to a record before it is removed. */
    public int $unclaimedHours = 24;

    // ------------------------------------------------------------------
    // Where the files are kept (docs/documents.md#storage)
    // ------------------------------------------------------------------

    /**
     * `local`: under writable/uploads on this server. `s3`: in an S3 bucket created
     * with Object Lock, so a document cannot be deleted before its retention ends.
     */
    public string $disk = 'local';

    public string $s3Bucket = '';

    /** Cape Town is the AWS region nearest Kenya. */
    public string $s3Region = 'af-south-1';

    /**
     * An access key for the bucket. Leave both empty on a server with an AWS role,
     * and the SDK finds its credentials itself.
     */
    public string $s3Key = '';
    public string $s3Secret = '';

    /** Another S3-compatible service (MinIO, R2, Wasabi): its endpoint URL. Empty for AWS. */
    public string $s3Endpoint = '';

    /** Address the bucket in the path rather than the host name; MinIO usually needs it. */
    public bool $s3PathStyle = false;

    /** A folder inside the bucket for this installation, when the bucket is shared. */
    public string $s3Prefix = '';

    /**
     * Object Lock mode for stored documents. COMPLIANCE: nobody can delete or
     * shorten the lock, the bucket's owner included. GOVERNANCE: accounts with a
     * special permission can; use it while trying the setup out. OFF: no lock.
     */
    public string $lockMode = 'COMPLIANCE';

    /** Years a stored document is locked for, counted from when it is stored. */
    public int $retentionYears = 7;

    /** Seconds a download link from S3 stays valid. */
    public int $linkSeconds = 120;
}
