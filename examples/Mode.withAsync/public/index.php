<?php

// The Classic router: PHP's own request lifecycle, as under PHP-FPM. Never reached by the
// WebSocket route below: async routes come first.
echo "Classic\n";
