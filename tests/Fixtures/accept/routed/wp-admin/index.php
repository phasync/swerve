<?php echo basename(__FILE__), " ", $_SERVER["SCRIPT_NAME"], " ", $_SERVER["PHP_SELF"], " ", $_SERVER["PATH_INFO"] ?? "-", " ", basename($_SERVER["DOCUMENT_ROOT"]), " ", basename(getcwd());
