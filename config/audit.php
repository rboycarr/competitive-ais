<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit trail (P1-05, P1-50, ADR-0006)
    |--------------------------------------------------------------------------
    |
    | lag_minutes: a checkpoint closes only rows older than this. Business
    | transactions must stay well below it; a row that commits later inside a
    | closed range is reported as late_commit.
    |
    | transaction_alert_seconds: a database transaction that commits after
    | running longer than this is logged critically (TASK-0010 #4). Web requests
    | and queued jobs are cut off well before it (docs/10 §5); commands are not.
    |
    | generation: checkpoint series. Key rotation keeps the generation: each
    | manifest names its key.
    |
    */

    'lag_minutes' => 15,

    'transaction_alert_seconds' => 300,

    'generation' => 1,

    /*
    | Signed manifests (P1-50). Only the auditor service holds the signing seed
    | (.secrets/signing.env, PO decision O43); where it is missing, nothing is
    | signed, exported or verified, and the audit commands are not scheduled.
    |
    | signing_seed: 64 hex characters (a 32-byte Ed25519 seed).
    | trusted_keys: public keys of earlier signing keys (64 hex characters each,
    | comma-separated), kept after a rotation so older manifests still verify.
    | The current key is always trusted.
    | lock_mode / retention_days: object lock on each manifest (PO decision O44):
    | COMPLIANCE for 7 years in production (enforced), GOVERNANCE for 1 day
    | locally and in CI, so those buckets can be cleared.
    | stale_minutes: verification reports anchor_stale when rows older than the
    | lag window plus this are still not in a signed manifest.
    */
    'anchor' => [
        'signing_seed' => env('AUDIT_SIGNING_SEED'),
        'trusted_keys' => env('AUDIT_TRUSTED_KEYS', ''),
        'disk' => 'audit-anchor',
        'lock_mode' => env('AUDIT_ANCHOR_LOCK_MODE', 'COMPLIANCE'),
        'retention_days' => (int) env('AUDIT_ANCHOR_RETENTION_DAYS', 2557),
        'stale_minutes' => 30,
    ],

];
