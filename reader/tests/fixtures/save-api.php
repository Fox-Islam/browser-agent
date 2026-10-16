<?php

// Stands in for a site whose save fails while its page says it worked.
http_response_code(500);
header('Content-Type: application/json');
echo '{"error":"Database unavailable, changes not saved"}';
