<?php

// Dies before any output: the client must see 502, since no response head had started.
posix_kill(posix_getpid(), SIGKILL);
echo 'unreachable';
