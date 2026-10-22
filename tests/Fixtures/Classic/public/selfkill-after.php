<?php

// Dies after flushing output: the client already has a response head, so the connection just
// ends early rather than getting a 502.
echo 'partial';
flush();
usleep(50000);
posix_kill(posix_getpid(), SIGKILL);
echo 'unreachable';
