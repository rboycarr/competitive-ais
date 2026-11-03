<?php

/*
| Evidence uploads (P1-32a, docs/03 Evidence, ADR-0007). Changed here, in code review, not per environment.
*/

return [

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
