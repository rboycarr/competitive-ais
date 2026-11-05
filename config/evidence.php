<?php

/*
| Evidence uploads and storage (P1-32a, P1-32b; docs/03 Evidence, ADR-0007). Changed here, in code review, not per
| environment. The virus scanner's connection is in config/services.php (`clamav`).
*/

return [

    // Days each stored blob is kept under object lock (governance mode) and in `evidence_blobs.retention_until`, until
    // P1-35 sets retention per asset (PO decision O33, 2026-11-03).
    'blob_retention_days' => 30,

    // Seconds a scan waits for another scan storing the same bytes (a MySQL named lock per hash) before it fails and
    // is retried: long enough for one 50 MB upload to the object store.
    'blob_lock_seconds' => 120,

    // Minutes an asset may wait `quarantined`/`pending` before evidence:dispatch-scans queues its scan again (a lost
    // dispatch or a worker that died mid-scan). The job's unique lock keeps a queued or running scan from doubling.
    'scan_stale_minutes' => 15,

    // Largest accepted upload. PHP (upload_max_filesize 50M, post_max_size 55M) and nginx (client_max_body_size 55m)
    // allow a little more, so this limit is the one users meet, with a clear message.
    'max_bytes' => 50 * 1024 * 1024,

    // Accepted content types, detected from the bytes (not the browser's claim), and the file extensions each may
    // carry. A file whose detected type is not listed, or whose extension does not match it, is refused before it is
    // stored; the scan checks the stored bytes again (P1-32b).
    'types' => [
        'application/pdf' => ['pdf'],
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/webp' => ['webp'],
        'text/plain' => ['txt', 'csv'],
        'text/csv' => ['csv'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
    ],

];
