<?php

// The test application, changing the working directory while it loads, as some frameworks' front controllers do
\chdir('/');

return require __DIR__ . '/app.php';
