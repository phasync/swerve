<?php
passthru('haproxy -d -f ' . dirname(__DIR__) . '/etc/haproxy.cnf');