<?php

// Never touches php://input or $_POST: the script that ends without reading its body (STOP).
echo 'ignored-body';
