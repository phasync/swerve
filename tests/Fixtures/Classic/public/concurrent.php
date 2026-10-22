<?php

/*
 * Counts how many requests are inside this block at once, under a flock'd counter file named
 * by SWERVE_TEST_DIR/counter ("current max"), and sleeps 500 ms while counted in.
 */
$path = getenv('SWERVE_TEST_DIR') . '/counter';
$fp   = fopen($path, 'c+');
flock($fp, LOCK_EX);
[$current, $max] = array_map('intval', explode(' ', trim(stream_get_contents($fp)) ?: '0 0'));
++$current;
$max = max($max, $current);
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, "$current $max");
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

usleep(500000);

$fp = fopen($path, 'c+');
flock($fp, LOCK_EX);
[$current, $max] = array_map('intval', explode(' ', trim(stream_get_contents($fp)) ?: '0 0'));
--$current;
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, "$current $max");
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo 'done';
