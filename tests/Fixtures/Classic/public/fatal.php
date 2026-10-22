<?php

// No output before the fatal error: the response becomes 500. display_errors off means the
// error text never reaches the client.
ini_set('display_errors', '0');
nonexistent_function_call_xyz();
