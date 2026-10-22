<?php

/*
 * Everything that isn't a static file, an existing .php script or a directory with an index:
 * the router. Answers 404 naming the path it got, so tests can tell the router ran (rather than
 * a file being served or executed) from the body.
 */

http_response_code(404);
echo 'ROUTER:' . ($_SERVER['REQUEST_URI'] ?? '');
