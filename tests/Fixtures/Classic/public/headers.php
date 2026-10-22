<?php

http_response_code(201);
header('X-Custom: yes');
header('Set-Cookie: a=1', false);
header('Set-Cookie: b=2', false);
echo 'created';
