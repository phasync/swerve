<?php

// exit() right after setting the status and a header, with no output: the response must still
// be the 302 with its Location, not a 200 with no body.
header('Location: /elsewhere');
http_response_code(302);
exit;
